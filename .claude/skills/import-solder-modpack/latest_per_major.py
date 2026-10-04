"""List, per modpack, the newest build of each major version on the source Solder.

    python latest_per_major.py <modpack-slug> [<modpack-slug> ...]

"Newest" is the highest version string; the source build id (creation order) is printed next to
it so a disagreement between the two shows up. The old API lists builds out of order.
"""

import re
import sys

from import_build import SRC, call, must, q


def version_key(version: str) -> list:
    return [(int(p), "") if p.isdigit() else (-1, p) for p in re.findall(r"\d+|[a-z]+", version.lower())]


for slug in sys.argv[1:]:
    builds = must(*call("GET", f"{SRC}/api/modpack/{q(slug)}"), f"read {slug}")["builds"]
    by_major = {}
    for version in builds:
        by_major.setdefault(version.split(".")[0], []).append(version)
    print(slug)
    for major, versions in sorted(by_major.items(), key=lambda kv: version_key(kv[0])):
        newest = max(versions, key=version_key)
        info = must(*call("GET", f"{SRC}/api/modpack/{q(slug)}/{q(newest)}"), f"read {slug} {newest}")
        print(f"  major {major}: {newest}  (MC {info['minecraft']}, {len(info['mods'])} mods, "
              f"build id {info['id']}, {len(versions)} builds in this major)")
