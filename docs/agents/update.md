# Self-update (core + modules)

Product module `updates`: instances pull code over HTTPS (optional token) without git CLI. Facade: [`app/UpdateEngine.php`](../../app/UpdateEngine.php). Implementation: [`app/Update/`](../../app/Update/) (`UpdateApply`, `UpdateRollback`, `UpdateOverlay`, …).

## Human UI

- `/updates` — local/remote package versions, last check, available count
- `/updates/settings` — repo / channel / token (`cfg/updates.ini`), SUPERADMIN
- `/updates/check` — refresh remote versions (Contents API)
- `/updates/apply` — download overlay + migrate (SUPERADMIN)

Permissions: `updates.view`, `updates.apply`. Apply/settings save also need `SUPERADMIN_ACCESS`. Platform twin: `/agent/update*` + `agent_token` ([agent-surfaces.md](agent-surfaces.md)).

**Assistant / MCP:** same `agent.ops` (`updates.check`, `updates.apply`, …) appear for staff who pass those gates — no separate update channel.

## Enable

```bash
php bin/freesaas.php module enable updates --format=json
```

Or `[modules] updates=1` in `cfg/config.ini`.


### Apply safety

1. **Snapshot** files that overlay will touch → `var/updates/rollback/<id>/`.
2. **Overlay** into the live tree (deny-list still skipped).
3. Optional **DB dump** if `db_backup_before_migrate=1` in `[updates]` / `--db-backup` / settings checkbox (default **off** — full dumps are slow on large tables like `base_records`).
4. **Migrate** (unless `--no-migrate`).
5. **Health** — required for `ok` (UI job and sync apply).
6. On failure: restore files from snapshot; restore DB dump only if migrate failed **and** a dump was taken. Health failure after a successful migrate does not auto-restore DB (`needs_manual_db_review`). Sync multi-package stops on the first failure.

### Local edits on the instance

Overlay compares hashes before writing, so files a client changed are not silently overwritten.

- **Baseline:** `var/updates/installed-manifest.json` stores the sha1 of each file as the updater last wrote it. Apply pins branch refs to a commit (`commit` in `cfg/updates.state.json`), so the very first run after this feature can also use the GitHub tree of an installed tag/commit as baseline. No baseline at all → file is written and counted in `unverified_count`.
- **Per file:** local missing → write; local = new → nothing; local = baseline → write; local edited but upstream unchanged → keep local silently (`kept_local`); local edited **and** upstream changed → **conflict**: local kept, upstream parked in `var/updates/conflicts/<path>`, listed in `var/updates/conflicts.json`.
- Conflicts do not fail apply (`ok` still = migrate + health). Result fields: `conflicts[]`, `kept_local`, `unverified_count`, `skipped_frozen[]`, `skipped_new_modules[]`.
- **Resolve:** `/updates` → «Local changes» (take new / keep mine, one or all); CLI `update conflicts`, `update resolve --take=ours|mine --path=… | --all`; agent `GET /agent/update/conflicts`, `POST /agent/update/resolve`.
- **Freeze:** `[updates] frozen_modules = a,b` (or checkboxes in `/updates/settings`) — overlay never writes those modules; Check shows them as «frozen» and excludes them from apply-all; an explicit `--target=<frozen>` is refused. Prefer this over abusing `[module.x]`.
- Rollback restores the manifest and conflict index together with files.

## How it works

### Check (many small GETs — OK)

For each package, UpdateEngine reads the live file from the configured git ref via GitHub Contents API:

| Package | Remote / local version from (sole source) |
|---------|-------------------------------------------|
| `core` | `modules/core/module.json` → `version` |
| module in monorepo | `modules/<id>/module.json` → `version` |
| external `[module.x]` | that repo’s `module.json` (or path under `path_prefix`) |

Root `VERSION` is a **derived** copy of core (written by `module bump core` / release build). Agents must not edit it for update detection.

~1 request per module (~40–45 with a token is fine).

On a full check, UpdateEngine also refreshes **`modules-catalog.json`** from the same ref (fallback: list `modules/` + each `module.json`). Modules present remotely but **not on disk** become **available to install** (see below). Cached in `cfg/updates.state.json`.

`/updates` shows **local + remote** versions for every package, plus **last check** time (`cfg/updates.state.json`). A banner links to `/modules` when new modules exist.

### New modules (install, not update)

Updates are for versions of code already on the instance. A package that is not on disk yet is an **install**:

1. Repo ships `modules-catalog.json` (build: `php bin/build-modules-catalog.php`; also attached as `freesaas-catalog.json` on releases).
2. **`/modules`** section **Available to install** — cards with **Install** and **Hide**. Hidden modules move to a collapsed **Hidden** list (restorable) and stop counting in the `/updates` banner. State: `dismissed_available` in `cfg/updates.state.json`.
3. Install → `UpdateEngine::installModule` writes `[modules] <id>=0` first (a missing key means *enabled*), then downloads the monorepo archive and overlays only `modules/<id>/`. Module stays **disabled**; enable + migrate as usual.
4. A full apply (`core` / `all`) **never creates** `modules/<id>/` for modules absent on this instance; they are reported in `skipped_new_modules[]`.
5. `/updates` only shows a banner («N new modules → modules»).

CLI / agent: `php bin/freesaas.php module install|dismiss|undismiss <id>` · `POST /agent/modules/{id}/install`.

### Apply (one archive for the monorepo)

- Channel `main` / branch / tag → GitHub **zipball** of that ref (full tree).
- Channel `stable` / `latest` → latest Release: prefer one **full** asset (`freesaas-<ver>.zip` or `asset=`), else zipball of the release tag.
- Overlay skips deny-list (`cfg/`, `var/`, …) and any module that has its own `[module.<id>]` (private/external).
- External modules: download that repo’s archive and overlay only `modules/<id>/`.
- Success = migrate (if enabled) **and** health not `fail`.

Product modules are **not** shipped as separate zips from this monorepo. If a module later lives in another repo, add `[module.id]` and build with `bin/build-release-artifacts.sh --module=id`.

## Setup

1. Enable `updates` (`[modules] updates=1`).
2. Configure source in UI **Updates → Update settings** (`/updates/settings`) or copy [`cfg/updates.example.ini`](../../cfg/updates.example.ini) → `cfg/updates.ini`.
3. Point `[core]` at the FreeSaaS repo (`repo`, `channel`, optional `token` / `asset`).
4. Optional: `[module.x]` for a module in another repo.
5. Writable `var/updates/`; PHP needs `curl` + `ZipArchive`.

Disabled modules are omitted from check/UI (they still receive files when a full core zip is applied, unless frozen). Modules not present on disk receive nothing — install them from `/modules`.

Optional public hint in `module.json` (candidates table only — not enough for apply):

```json
"update": {
  "repository": "https://github.com/org/likespa-private",
  "channel": "stable"
}
```

## Release artifacts (stable)

CI / [`bin/build-release-artifacts.sh`](../../bin/build-release-artifacts.sh) builds:

- `freesaas-<VERSION>.zip` — whole tree minus `PRIVATE_MODULES` (default empty) and `PRIVATE_THEMES` (default `likespa,balance`; `themes/default` always kept)
- `freesaas-manifest.json` — `{kind:"full", version, sha256, artifact}`
- `freesaas-catalog.json` — installable modules catalog (same shape as repo-root `modules-catalog.json`)

Spa instance pack module (`modules/likespa`) ships with the public tree. Licensed storefront skins stay in private theme repos (gitignored; stripped from release zip):
[`freesaas-theme-likespa`](https://github.com/ilcommm/freesaas-theme-likespa), [`freesaas-theme-balance`](https://github.com/ilcommm/freesaas-theme-balance).

Attach them to the GitHub Release (see `.github/workflows/release-artifacts.yml`). When adding a product module, regenerate and commit `modules-catalog.json` so channel=`main` instances see it via Contents API.

## Deny-list

Never overwritten: `cfg/` (incl. `updates.ini` / `updates.state.json`), `var/`, `log/`, `.git/`, `assets/uploads/`, `cfg/modules/*.ini`.

Monorepo apply skips `modules/<id>/` when `[module.<id>]` is configured.

## CLI

```bash
php bin/freesaas.php update status --format=json
php bin/freesaas.php update check --format=json
php bin/freesaas.php update check --target=banks --format=json
php bin/freesaas.php update apply --target=core --format=json
php bin/freesaas.php update apply --target=all --format=json
php bin/freesaas.php update apply --target=core --no-migrate --format=json
php bin/freesaas.php update apply --target=core --db-backup --format=json
php bin/freesaas.php update conflicts --format=json
php bin/freesaas.php update resolve --take=ours --path=modules/orders/views/edit.php --format=json
php bin/freesaas.php update resolve --take=mine --all --format=json
```

## HTTP platform (`app.agent_token`)

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/update"
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/update/check"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" -d '{"target":"all"}' \
  "https://example.com/agent/update/apply"
```

## Session / product JSON

| Op | Call |
|----|------|
| `updates.status` | `GET /updates?format=json` |
| `updates.check` | POST `/updates` `action=update_check` or dedicated check route |
| `updates.apply` | job start/tick UI, or sync `update_apply` |

Apply requires `updates.apply` + `SUPERADMIN_ACCESS`.

## Publish flow

1. Bump the package that changed: `php bin/freesaas.php module bump <id>` (or `module bump core` for platform/`app/` changes). Commit & **push** (unpushed bumps are invisible to Check).
2. Tag / publish a GitHub Release → CI attaches `freesaas-*.zip` + manifest (for `channel=stable`).
3. Instances on `channel=stable` pull the release; `channel=main` can use zipball without a Release.
4. Private module in another repo → `[module.id]` + optional `--module=id` zip.
