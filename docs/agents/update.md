# Self-update (core + modules)

Instances pull code over HTTPS (optional token) without git CLI. After overlay: existing migrate → health.

UI: **`updates`** (`/updates`). Engine: [`app/UpdateEngine.php`](../../app/UpdateEngine.php).

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
2. **`/modules`** section **Available to install** — cards with **Install**.
3. Install → `UpdateEngine::installModule` downloads the monorepo archive and overlays only `modules/<id>/`. Module stays **disabled**; enable + migrate as usual.
4. `/updates` only shows a banner («N new modules → modules»).

CLI / agent: `php bin/freesaas.php module install <id>` · `POST /agent/modules/{id}/install`.

### Apply (one archive for the monorepo)

- Channel `main` / branch / tag → GitHub **zipball** of that ref (full tree).
- Channel `stable` / `latest` → latest Release: prefer one **full** asset (`freesaas-<ver>.zip` or `asset=`), else zipball of the release tag.
- Overlay skips deny-list (`cfg/`, `var/`, …) and any module that has its own `[module.<id>]` (private/external).
- External modules: download that repo’s archive and overlay only `modules/<id>/`.

Product modules are **not** shipped as separate zips from this monorepo. If a module later lives in another repo, add `[module.id]` and build with `bin/build-release-artifacts.sh --module=id`.

## Setup

1. Enable `updates` (`[modules] updates=1`).
2. Configure source in UI **Updates → Update settings** (`/updates/settings`) or copy [`cfg/updates.example.ini`](../../cfg/updates.example.ini) → `cfg/updates.ini`.
3. Point `[core]` at the FreeSaaS repo (`repo`, `channel`, optional `token` / `asset`).
4. Optional: `[module.x]` for a module in another repo.
5. Writable `var/updates/`; PHP needs `curl` + `ZipArchive`.

Disabled modules are omitted from check/UI (they still receive files when a full core zip is applied).

Optional public hint in `module.json` (candidates table only — not enough for apply):

```json
"update": {
  "repository": "https://github.com/org/likespa-private",
  "channel": "stable"
}
```

## Release artifacts (stable)

CI / [`bin/build-release-artifacts.sh`](../../bin/build-release-artifacts.sh) builds:

- `freesaas-<VERSION>.zip` — whole tree minus `PRIVATE_MODULES` (default empty) and `PRIVATE_THEMES` (default `likespa`; `themes/default` always kept)
- `freesaas-manifest.json` — `{kind:"full", version, sha256, artifact}`
- `freesaas-catalog.json` — installable modules catalog (same shape as repo-root `modules-catalog.json`)

LikeSpa **module** (`modules/likespa`) ships with the public tree. Instance **theme** `themes/likespa` stays local/private (gitignored; stripped from release zip).

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
