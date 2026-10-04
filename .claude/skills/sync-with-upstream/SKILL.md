---
name: sync-with-upstream
description: Bring this fork (EverNife/TechnicSolder) up to date with its parent TechnicPack/TechnicSolder - check what upstream has that we lack, merge it without losing the fork's own changes, validate on the dev stack, and (with the owner's go-ahead) push and watch the Dokploy deploy. Use for "sync with upstream", "atualiza com o upstream", "tem coisa nova no upstream?", "traz os commits do TechnicPack", or any update of this fork from its parent.
---

# Sync with upstream

This repository is a **fork**. GitHub's "Sync fork" button cannot be used: the fork carries its own
commits (deployment plumbing and the mod upload feature) that upstream does not have. Updates come
in as a **merge commit**, never a rebase, so every sync stays incremental and the fork's history
survives.

Run the steps in order. Stop and ask the owner where a step says so.

## 1. Check what upstream has

```bash
git remote get-url upstream || git remote add upstream https://github.com/TechnicPack/TechnicSolder.git
git fetch upstream && git fetch origin
git status -sb                                   # must be on main, clean, level with origin/main
git log --format='%h %ad %s' --date=short HEAD..upstream/main
```

Nothing listed → we are level. Report that and stop.

Otherwise size the update before touching anything:

```bash
git diff --stat HEAD...upstream/main
git diff --name-status HEAD...upstream/main -- database/       # migrations
comm -12 <(git diff --name-only upstream/main...HEAD | sort) \
         <(git diff --name-only HEAD...upstream/main | sort)  # files both sides touched
```

Read the upstream `CHANGELOG.md` entries for the new versions. Flag to the owner:

- **Migrations**: new additive ones are routine (the entrypoint runs `migrate --force` on boot). An
  **edited or destructive** migration (drop/rename column, data rewrite) means a database backup
  before deploying (step 6). Ask the owner before going on.
- **New environment variables** in upstream's `compose.yml` / `.env.example` / `config/*.php`.
  Production env lives in the Dokploy panel, not in git; tell the owner which ones to set.
- **Overlap** with the fork's files (table in section 3).

Worth merging? Security fixes and bug fixes: yes. Dependency/CI-only releases: yes, they are cheap.
Say what the update brings and whether it is worth it; the owner decides.

## 2. Merge

```bash
git merge --no-ff --no-commit upstream/main
git diff --name-only --diff-filter=U             # conflicted files
```

Resolve with the rules in section 3, `git add` each file, then validate (step 4) **before**
committing. Abort with `git merge --abort` if the merge turns out wrong.

## 3. What the fork owns - never lose these

Every row was a real production failure or a deliberate decision. When upstream touches one of
these spots, keep the fork's behaviour and take upstream's other changes around it.

| File | Fork change | Why it must survive |
|---|---|---|
| `compose.yml` | No `mysql` service; `DB_CONNECTION=pgsql`, `DB_HOST`/`DB_PASSWORD` required | Production uses a Postgres managed by Dokploy, outside the stack. Upstream keeps re-pinning its MariaDB image, so **this conflicts on almost every sync**: drop their `mysql` block, keep their image pins on the other services. |
| `compose.yml` | `expose: 80` instead of `ports:`; `dokploy-network` (external) on nginx and solder | Traefik routes by `Host:` header; publishing a host port is unnecessary surface. |
| `compose.yml` | `APP_KEY`, `APP_URL`, `DB_HOST`, `DB_PASSWORD`, `SOLDER_MIRROR_URL` use `${VAR:?message}` | Fail fast with an actionable message instead of booting on a placeholder. |
| `compose.yml` | `../files/mods:/var/www/mods` (solder rw, nginx ro), `../files/valkey:/data` | Dokploy deletes and re-clones `code/` on every deploy; `files/` is its sibling and survives. Mod archives live there. |
| `compose.yml` | `SOLDER_REPO_LOCATION=/var/www/`, mirror URL = site root | Solder appends `mods/` itself (`Modversion::getUrlAttribute`, `ModArchiveStore`). Pointing these at `.../mods/` doubles the segment and every download 404s silently. |
| `compose.yml` | No `./public:/var/www/html/public` mount on nginx | The nested mount shadowed the parent and served an empty root. |
| `docker/Dockerfile` | Assets copied to `/opt/solder-assets/build`, not `public/build` | The source bind mount masks the image's `public/build`; the entrypoint restores it from `/opt`. If upstream changes the assets stage, keep the `/opt` destination. |
| `docker/Dockerfile` | `uploads.ini`: `upload_max_filesize=110M`, `post_max_size=112M` | PHP's 2M default kills mod uploads before Laravel sees them. |
| `docker/entrypoint.sh` | Keeps an `APP_KEY` from the environment; restores `/opt/solder-assets/build`; `chown -R www-data` of the mods dir | Dokploy always writes `.env`; archives copied over SSH arrive as root while php-fpm writes as www-data. |
| `docker/default.conf` | `resolver 127.0.0.11` + `set $php_fpm solder:9000` + `fastcgi_pass $php_fpm` | A literal upstream host is resolved once; after a host restart php-fpm got a new IP and the site answered **502 for days**. |
| `docker/default.conf` | `client_max_body_size 112m`; `location /mods/ { alias /var/www/mods/; }` | Upload size; nginx serves the mirror - Solder never serves archives. |
| `bootstrap/app.php` | `$middleware->trustProxies(at: '*')` | Behind TLS-terminating Traefik; without it redirects drop to `http` and `last_ip` records the proxy. |
| `compose.dev.yml`, `docs/contributing.md`, `docs/getting-started/docker.md` | Dev stack on `127.0.0.1:8434` | Avoids the common 8080. |
| `app/Libraries/ModArchiveStore.php`, `ArchiveExistsException.php`, upload actions in `Api/ModversionController` and `ModController`, `routes/api.php` + `routes/web.php` upload routes, `resources/views/mod/view.blade.php`, `docs/api/write/mods.md`, `tests/Feature/ModUploadTest.php` | Mod archive upload (UI + `POST /api/mod/{slug}/{version}/file`) | Fork feature. If upstream refactors these controllers or the mod view, re-apply the upload on top of their version rather than reverting theirs. |

Never add the fork's changes to `CHANGELOG.md` - it is upstream's and would conflict every time.
`.claude/rules/docker.md` (upstream's) still calls `compose.yml` a MariaDB stack; that is stale for
this fork and harmless - leave it, so it does not conflict.

**After resolving, audit the merged tree**, not just the conflicts - an auto-merge can still break the
contract above:

```bash
git diff upstream/main -- compose.yml docker/ bootstrap/app.php   # only the fork's rows should remain
grep -n "mysql\|ports:" compose.yml                               # expect nothing
grep -rn "ShouldQueue\|dispatch(" app/                            # production runs no queue worker
grep -rn "'mods/'" app/                                           # the path contract is unchanged
```

If upstream added a queued job, production needs a worker container - raise it with the owner.

## 4. Validate

Always the dev stack (`compose.dev.yml`, Postgres) - never `docker compose` without `-f`, never the
production `compose.yml` locally. Redirect long output to a file in the scratchpad and read the
verdict from it.

```bash
docker compose -f compose.dev.yml up -d --build      # builds the merged Dockerfile (assets stage too)
docker compose -f compose.dev.yml logs solder        # wait for "ready to handle connections"; new migrations listed as DONE
docker compose -f compose.dev.yml exec -T solder php artisan test --compact
APP_KEY=x APP_URL=https://x DB_HOST=x DB_PASSWORD=x SOLDER_MIRROR_URL=https://x/ \
  docker compose -f compose.yml config -q            # production compose still parses
docker compose -f compose.dev.yml down
```

The suite must be fully green, including `tests/Feature/ModUploadTest.php`. Report the real
`Tests:` line. A red test that upstream also has red is still a finding - say so, do not hide it.

## 5. Commit

```bash
git commit -m "upstream: merge TechnicPack/TechnicSolder vX.Y.Z"
```

Body only when a resolution is not obvious from the diff (e.g. "Drops upstream's pinned MariaDB
service again; this stack uses the managed Postgres."). Never mention Claude, sessions or specs.

## 6. Push and deploy - only with the owner's explicit go-ahead

Committing does not authorize pushing. Ask, and wait for a clear yes.

Before pushing, take a baseline of the live site so a regression is attributable:

```bash
curl -s https://solder.finaltech.com.br/api/        # current version
curl -s -o NUL -w "%{http_code}\n" https://solder.finaltech.com.br/login
```

If the merge brings an edited or destructive migration, have the owner take a Postgres backup
first (Dokploy's database backup or `pg_dump`) - this session has no shell on the server.

Then `git push origin main`. **The push deploys by itself**: the Dokploy compose service uses the
GitHub App provider with `autoDeploy`. Watch it through the Dokploy MCP:

| What | Call |
|---|---|
| Deploy started? | `deployment-allByCompose { composeId: "NvxCWYG4TD2K4DcIZbt0F" }` - an entry whose description is `Commit: <sha>` |
| No entry after ~2 min | `compose-deploy { composeId }` - **not** `compose-redeploy`, which reuses the old clone and reports success without pulling |
| Build log | `deployment-readLogs { deploymentId }` |
| Runtime logs | `compose-readLogs { composeId, containerId: "solder-kolzij-solder-1" }` (also `-nginx-1`, `-redis-1`) |

Done means all of these hold:

- `/api/` answers the new `version`;
- `/login` returns 200;
- the solder log shows migrations (or `Nothing to migrate`), `An admin user already exists`, and
  `ready to handle connections`;
- an existing archive under `/mods/` still downloads (proves the `files/mods` mount survived).

## Production facts (Dokploy)

- Compose service `solder`, appName **`solder-kolzij`**, composeId `NvxCWYG4TD2K4DcIZbt0F`; managed
  Postgres `solder-db`, appName `solder-db-e3yvlx` (that appName *is* the internal `DB_HOST`).
  Domain `solder.finaltech.com.br` (behind Cloudflare: requests over 100 MB are cut).
  The old Solder at `solder.finalcraft.com.br` is a different server - do not confuse the two.
- The service's **Command** field holds
  `compose -p solder-kolzij -f ./compose.yml up -d --build --remove-orphans --force-recreate`.
  It lives in the panel, not in git. Without `--force-recreate`, containers that keep their config
  keep a bind mount to the deleted old clone and php-fpm answers `File not found.`. The field
  prefixes `docker` itself - the value starts at `compose`.
- Environment (APP_KEY, DB_*, SOLDER_*) lives in the panel. Dokploy prepends `APP_NAME=<appName>`
  to the generated `.env`, so the block must redefine `APP_NAME`.
- On the host: `/etc/dokploy/compose/solder-kolzij/code/` (re-cloned every deploy) and `files/`
  (`mods/`, `valkey/` - survives deploys, not covered by Dokploy backups). Postgres data is the
  Docker volume `solder-db-e3yvlx-data`.

## Report to the owner

What upstream brought (versions, notable changes), conflicts and how each was resolved, anything
from the section 3 audit, the real test line, and - if deployed - the live version and checks.
