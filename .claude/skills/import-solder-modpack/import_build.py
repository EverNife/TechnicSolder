"""Copy one modpack build, as is, from a source Solder to a destination Solder.

Reads the source only through its public API and writes the destination only through the write
API, so it runs from any machine. Every step is idempotent: rerunning after a failure skips what
already landed (same md5) and carries on.

    python import_build.py <modpack-slug> <build-version> [--dry-run] [--limit N]

Token for the destination: env SOLDER_TOKEN, else the file .token next to this script (git-ignored).
"""

import argparse
import hashlib
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

SRC = os.environ.get("SOLDER_SRC", "https://solder.finalcraft.com.br")
DST = os.environ.get("SOLDER_DST", "https://solder.finaltech.com.br")
CACHE = Path(os.environ.get("SOLDER_CACHE", Path.home() / ".solder-import-cache"))
UA = {"User-Agent": "solder-import/1", "Accept": "application/json"}
# Cloudflare in front of the new Solder refuses request bodies over 100 MB: archives above
# MAX_UPLOAD go through the parts endpoint in PART_SIZE slices.
MAX_UPLOAD = 95_000_000
PART_SIZE = 90_000_000
problems: list[str] = []


def token() -> str:
    """Empty when unset, so a --dry-run still works against the public read API."""
    file = Path(__file__).with_name(".token")
    return os.environ.get("SOLDER_TOKEN") or (file.read_text().strip() if file.exists() else "")


def call(method: str, url: str, body=None, auth=False, files=None) -> tuple[int, dict]:
    headers = dict(UA)
    data = None
    if auth and token():
        headers["Authorization"] = f"Bearer {token()}"
    if files is not None:
        boundary = uuid.uuid4().hex
        parts = []
        for name, value in (body or {}).items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        for name, path in files.items():
            parts.append(
                f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{path.name}"\r\n'
                f"Content-Type: application/zip\r\n\r\n".encode() + path.read_bytes() + b"\r\n"
            )
        data = b"".join(parts) + f"--{boundary}--\r\n".encode()
        headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
    elif body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    while True:
        try:
            with urllib.request.urlopen(req, timeout=600) as r:
                return r.status, json.loads(r.read() or b"{}")
        except urllib.error.HTTPError as e:
            raw = e.read()
            if e.code == 429:  # Solder throttles its API at 60 requests a minute
                wait = max(1, int(e.headers.get("Retry-After") or 10))
                print(f"  (rate limited, waiting {wait}s)")
                time.sleep(wait)
                continue
            try:
                return e.code, json.loads(raw)
            except ValueError:
                return e.code, {"raw": raw[:300].decode(errors="replace")}


def q(s: str) -> str:
    return urllib.parse.quote(s, safe="")


def must(status: int, body: dict, what: str, ok=(200, 201)) -> dict:
    if status not in ok:
        sys.exit(f"FAILED {what}: HTTP {status} {body}")
    return body


def md5_of(path: Path) -> str:
    h = hashlib.md5()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def fetch_archive(mod: dict) -> Path | None:
    """Download the source archive into the cache, verified against the source md5.
    None when the old server no longer has the file."""
    path = CACHE / mod["src"] / f"{mod['src']}-{mod['version']}.zip"
    if path.exists() and md5_of(path) == mod["md5"]:
        return path
    path.parent.mkdir(parents=True, exist_ok=True)
    req = urllib.request.Request(mod["url"], headers={"User-Agent": UA["User-Agent"]})
    try:
        with urllib.request.urlopen(req, timeout=600) as r, path.open("wb") as f:
            while chunk := r.read(1 << 20):
                f.write(chunk)
    except urllib.error.HTTPError as e:
        path.unlink(missing_ok=True)
        problems.append(f"MISSING ON OLD SERVER (HTTP {e.code}, skipped): {mod['src']} {mod['version']} {mod['url']}")
        return None
    got = md5_of(path)
    if got != mod["md5"]:
        # The source Solder's stored md5 can be stale; keep the real file and say so.
        print(f"  ! {mod['name']} {mod['version']}: source md5 {mod['md5']} but file is {got}")
    return path


def nullable(v):
    return v if v not in ("", None, 0) else None


def ensure_modpack(slug: str, dry: bool) -> None:
    status, _ = call("GET", f"{DST}/api/modpack/{q(slug)}")
    if status == 200:
        return
    src = must(*call("GET", f"{SRC}/api/modpack/{q(slug)}"), f"read source modpack {slug}")
    print(f"+ modpack {slug} ({src['display_name']})")
    if not dry:
        # Created hidden: the pack stays off the public listing until someone reviews it.
        must(*call("POST", f"{DST}/api/modpack", auth=True, body={
            "name": src["display_name"], "slug": slug, "url": nullable(src.get("url")), "hidden": True,
        }), f"create modpack {slug}")


def ensure_build(slug: str, version: str, src_build: dict, dry: bool) -> None:
    status, _ = call("GET", f"{DST}/api/modpack/{q(slug)}/{q(version)}", auth=True)
    if status == 200:
        return
    body = {"version": version, "minecraft": src_build["minecraft"], "is_published": True}
    for src_key, dst_key in (("forge", "forge"), ("java", "min_java"), ("memory", "min_memory")):
        if nullable(src_build.get(src_key)) is not None:
            body[dst_key] = src_build[src_key]
    print(f"+ build {slug} {version} {body}")
    if not dry:
        must(*call("POST", f"{DST}/api/modpack/{q(slug)}/build", auth=True, body=body), f"create build {version}")


def ensure_mod(mod: dict, dry: bool) -> None:
    name = mod["name"]
    status, _ = call("GET", f"{DST}/api/mod/{q(name)}", auth=True)
    if status == 200:
        return
    src = must(*call("GET", f"{SRC}/api/mod/{q(mod['src'])}"), f"read source mod {mod['src']}")
    body = {"name": name, "pretty_name": src["pretty_name"] or name}
    if name != mod["src"]:
        body["pretty_name"] += f" ({mod['version']})"
    for key in ("author", "description", "link"):
        if nullable(src.get(key)) is not None:
            body[key] = src[key]
    print(f"+ mod {name} ({body['pretty_name']})")
    if not dry:
        must(*call("POST", f"{DST}/api/mod", auth=True, body=body), f"create mod {name}")


def served(url: str) -> bool:
    try:
        urllib.request.urlopen(urllib.request.Request(url, method="HEAD", headers=UA), timeout=60).close()
        return True
    except urllib.error.HTTPError:
        return False


def upload_in_parts(url: str, path: Path, form: dict) -> tuple[int, dict]:
    """Send the archive through the parts endpoint in PART_SIZE slices; the response of the slice
    that completes it is the same as a single upload's."""
    parts = -(-path.stat().st_size // PART_SIZE)
    with path.open("rb") as f:
        for part in range(parts):
            piece = path.with_name(f"{path.name}.part{part}")
            piece.write_bytes(f.read(PART_SIZE))
            try:
                result = call("POST", f"{url}/parts", auth=True, files={"file": piece},
                              body={**form, "filename": path.name, "parts": parts, "part": part})
            finally:
                piece.unlink()
            if result[0] not in (200, 201, 202):
                return result
    return result


def ensure_version(mod: dict, dry: bool) -> bool:
    """False when the archive cannot be had, so the mod must not be attached."""
    name, version = mod["name"], mod["version"]
    big = mod["filesize"] > MAX_UPLOAD
    status, existing = call("GET", f"{DST}/api/mod/{q(name)}/{q(version)}", auth=True)
    # A big version may have been registered by md5 alone, so also check the file is served.
    if status == 200 and existing.get("md5") == mod["md5"] and (
            not big or served(f"{DST}/mods/{q(name)}/{q(name)}-{q(version)}.zip")):
        return True
    path = fetch_archive(mod)
    if path is None:
        return False
    print(f"+ upload {name} {version} ({path.stat().st_size // 1024} KB)"
          f"{' in parts' if big else ''}{' replace' if status == 200 else ''}")
    if not dry:
        url = f"{DST}/api/mod/{q(name)}/{q(version)}/file"

        def send(form: dict) -> tuple[int, dict]:
            if big:
                return upload_in_parts(url, path, form)
            return call("POST", url, auth=True, body=form, files={"file": path})

        result = send({"replace": "true"} if status == 200 else {})
        if result[0] == 409 and status != 200:
            # An orphan archive left on disk by a deleted version; the old server's copy wins.
            print("  (orphan archive on disk, replacing)")
            result = send({"replace": "true"})
        must(*result, f"upload {name} {version}")
    return True


def attach(slug: str, build: str, mod: dict, current: dict, dry: bool) -> None:
    name, version = mod["name"], mod["version"]
    if current.get(name) == version:
        return
    print(f"+ attach {name} {version}")
    if dry:
        return
    if name in current:
        must(*call("PUT", f"{DST}/api/modpack/{q(slug)}/{q(build)}/mod/{q(name)}", auth=True,
                   body={"mod_version": version}), f"swap {name}")
    else:
        must(*call("POST", f"{DST}/api/modpack/{q(slug)}/{q(build)}/mod", auth=True,
                   body={"mod_slug": name, "mod_version": version}), f"attach {name}")


def split_duplicates(mods: list[dict]) -> None:
    """The old Solder let one build hold two versions of the same mod (e.g. a mod and its
    resourcepack); the new API refuses that. The oldest version keeps the slug, every other one
    moves to its own mod named <slug>-<version>, deterministic so later builds reuse it."""
    by_source = {}
    for mod in mods:
        mod["src"] = mod["name"]
        by_source.setdefault(mod["src"], []).append(mod)
    for same in by_source.values():
        for mod in sorted(same, key=lambda m: m["id"])[1:]:
            mod["name"] = f"{mod['src']}-{re.sub(r'[^a-z0-9]+', '-', mod['version'].lower()).strip('-')}"
            print(f"! {mod['src']} {mod['version']} is a second version in this build -> mod {mod['name']}")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("modpack")
    ap.add_argument("build")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--limit", type=int, help="import only the first N mods (trial runs)")
    a = ap.parse_args()

    src_build = must(*call("GET", f"{SRC}/api/modpack/{q(a.modpack)}/{q(a.build)}"), "read source build")
    split_duplicates(src_build["mods"])
    mods = src_build["mods"][: a.limit]
    print(f"{a.modpack} {a.build}: MC {src_build['minecraft']}, {len(mods)} mods")

    ensure_modpack(a.modpack, a.dry_run)
    ensure_build(a.modpack, a.build, src_build, a.dry_run)
    status, dst_build = call("GET", f"{DST}/api/modpack/{q(a.modpack)}/{q(a.build)}", auth=True)
    current = {m["name"]: m["version"] for m in dst_build.get("mods", [])} if status == 200 else {}

    for i, mod in enumerate(mods, 1):
        print(f"[{i}/{len(mods)}] {mod['name']} {mod['version']}")
        ensure_mod(mod, a.dry_run)
        if ensure_version(mod, a.dry_run):
            attach(a.modpack, a.build, mod, current, a.dry_run)

    extra = [] if a.limit else sorted(set(current) - {m["name"] for m in mods})
    if extra:
        print(f"! destination build has mods the source lacks (left alone): {extra}")
    for line in problems:
        print(f"! {line}")
    if a.dry_run:
        return

    final = must(*call("GET", f"{DST}/api/modpack/{q(a.modpack)}/{q(a.build)}", auth=True), "re-read build")
    want = {(m["name"], m["version"], m["md5"]) for m in mods}
    got = {(m["name"], m["version"], m["md5"]) for m in final["mods"]}
    missing = want - got
    print(f"verify: {len(want) - len(missing)}/{len(want)} mods match name+version+md5")
    for m in sorted(missing):
        print(f"  MISMATCH {m}")
    sys.exit(1 if missing or problems else 0)


if __name__ == "__main__":
    main()
