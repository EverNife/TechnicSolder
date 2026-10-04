---
name: import-solder-modpack
description: Copy modpack builds, as is, from the old Solder (solder.finalcraft.com.br) to the new one (solder.finaltech.com.br) - modpack, build, mods, mod versions and their archives - one build at a time. Use for "importa a versão X do modpack Y", "traz o modpack do solder antigo", "migra a build", "importa a última de cada major", or any copy of modpacks/builds between two Solder instances.
---

# Import a modpack build from the old Solder

The old Solder (0.12.x) is read only through its **public API** (`/api/modpack`, `/api/mod`); the
new one is written only through its **write API** (docs in `docs/api/write/`). Nothing touches
either server's disk or database directly.

Builds are imported **one at a time, as is**: same modpack slug, same mod slugs, same version
strings, same archives (md5 verified). Cleanup of names/slugs is a separate, later pass by the
owner.

## Setup (once per machine)

The new Solder needs a Sanctum token: log in → profile → create an API token. Paste it, one line,
into `.token` in this folder. It is listed in `.git/info/exclude`; never commit it.

## Which builds

The owner's default is **the newest build of each major version** of a pack (4.x → 4.0.14,
5.x → 5.5.4). Find them with:

```bash
PYTHONIOENCODING=utf-8 python latest_per_major.py <modpack-slug> [...]
```

Old pack slugs don't match display names (`twing-sky` = Skylords, `finalcraft-pokeborn` = Pixelmon,
`finalcraft-mod-pack-30` = HardCore); `curl -s https://solder.finalcraft.com.br/api/modpack` lists
them all.

## Run

```bash
cd .claude/skills/import-solder-modpack
PYTHONIOENCODING=utf-8 python import_build.py <modpack-slug> <build-version> --dry-run
PYTHONIOENCODING=utf-8 python import_build.py <modpack-slug> <build-version> --limit 2   # trial
PYTHONIOENCODING=utf-8 python import_build.py <modpack-slug> <build-version>
```

- Import a pack's builds **oldest first**, so later builds reuse the mods and versions already there.
- `--dry-run` reads both sides and downloads every archive into `~/.solder-import-cache` (verified
  against the old md5), writing nothing. The real run reuses that cache.
- Each step skips what already exists, so a failed run is just rerun. The run ends with a
  verification: every source mod present in the new build with the same name, version and md5;
  exit code 1 on any mismatch.
- Redirect the output to a file and grep `^!|FAILED|verify|MISMATCH`; a full build prints
  hundreds of lines.
- Override hosts with `SOLDER_SRC` / `SOLDER_DST`.

## What the script decides

- **Modpack** is created **hidden** (off the public listing, still reachable by slug); unhide it
  in the UI after review. Icon/logo/background are not copied (the old ones are defaults).
- **Build** is created published, with `minecraft`, and `forge`/`min_java`/`min_memory` only when
  the old build has them. Recommended/latest are not set.
- **Mods that already exist** in the new Solder are left as they are (metadata not overwritten).
- **Mod version** with a different md5 in the new Solder is re-uploaded with `replace=true`.
- **Two versions of one mod in one build** (the old Solder allowed it, e.g. `customnpcs` plus its
  resourcepack; the new API answers 422): the oldest version keeps the slug, each other one becomes
  its own mod `<slug>-<version>` with pretty name `<Name> (<version>)`. Version string and archive
  are unchanged, so the launcher gets the same files.
- **Archive over 95 MB** (Cloudflare refuses bodies over 100 MB with `413`): sent in 90 MB slices
  through `POST /api/mod/{slug}/{version}/file/parts` (`docs/api/write/mods.md`), which joins them
  and stores the file like a single upload. A version that exists with the right md5 but whose
  file the mirror does not serve is uploaded again.
- **Archive gone from the old server** (`404` on the download): the mod is skipped, the rest of
  the build goes on, the run ends with `! MISSING ON OLD SERVER` and exit 1. Ask the owner: another
  version of the mod, a file from them, or leave it out.
- **Orphan archive on disk** (`409` for a version the database does not have, left by a deleted
  version): overwritten with the old server's copy.

## Gotchas

- Both Solders throttle the API at **60 requests a minute**; the script sleeps on `429` for the
  `Retry-After` seconds. Archive downloads (`/mods/...`) are not throttled.
- The new Solder caches a missing build for 1 minute and a modpack for 5; a `GET` right after a
  create can still answer 404. The script tolerates it; manual checks may need to wait.
- `DELETE /api/mod/...` removes database rows only; the archive stays on disk in the mods volume.
- Checking many archive URLs: use Python `HEAD` requests. `curl -r 0-0 -o NUL` in Git Bash answers
  `000` for every URL and looks like the whole server is gone.

## Log of imports

| Date | Modpack | Build | Result |
|------|---------|-------|--------|
| 2026-10-04 | twing-sky | 4.0.14 | 93/93; customnpcs resourcepack split to `customnpcs-resourcepack-v20` |
| 2026-10-04 | twing-sky | 5.5.4 | 104/104 |
| 2026-10-04 | finalcraft-pokeborn | 1.2.4b | 7/7 |
| 2026-10-04 | finalcraft-pokeborn | 2.4.6 | 20/20; `pixelmon` (375 MB) and `resourcepacks finalcraft-pixelmon-ost` (118 MB) need the host copy; resourcepacks customnpcs split to `resourcepacks-customnpcs-1-12-2` |
| 2026-10-04 | finalcraft-pokeborn | 3.0.5 | 14/14; `pixelmon` (376 MB) needs the host copy |
| 2026-10-04 | finalcraft-mod-pack-30 | 3.0.2b | 81/83; `actuallyadditions 1.12.2-r146` and `backpacksplus 1.12.2-3.5.5` missing on the old server |
