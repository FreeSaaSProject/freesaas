# Self-update (core + modules)

Instances pull code over HTTPS (optional token) without git CLI. After overlay: existing migrate → health.

UI: **`updates`** (`/updates`). Engine: [`app/UpdateEngine.php`](../../app/UpdateEngine.php).

## How it works

### Check (many small GETs — OK)

For each package, UpdateEngine reads the live file from the configured git ref via GitHub Contents API:

| Package | Remote version from |
|---------|---------------------|
| `core` | `VERSION` (fallback: `modules/core/module.json`) |
| module in monorepo | `modules/<id>/module.json` → `version` |
| external `[module.x]` | that repo’s `module.json` (or path under `path_prefix`) |

~1 request per module (~40–45 with a token is fine). No registry / catalog file required.

`/updates` shows **local + remote** versions for every package, plus **last check** time (`cfg/updates.state.json`).

### Apply (one archive for the monorepo)

- Channel `main` / branch / tag → GitHub **zipball** of that ref (full tree).
- Channel `stable` / `latest` → latest Release: prefer one **full** asset (`freesaas-<ver>.zip` or `asset=`), else zipball of the release tag.
- Overlay skips deny-list (`cfg/`, `var/`, …) and any module that has its own `[module.<id>]` (private/external).
- External modules: download that repo’s archive and overlay only `modules/<id>/`.

Product modules are **not** shipped as separate zips from this monorepo. If a module later lives in another repo, add `[module.id]` and build with `bin/build-release-artifacts.sh --module=id`.

## Setup

1. Enable `updates` (`[modules] updates=1`).
2. Copy [`cfg/updates.example.ini`](../../cfg/updates.example.ini) → `cfg/updates.ini`.
3. Point `[core]` at the FreeSaaS repo (`repo`, `channel`, optional `token` / `asset`).
4. Optional: `[module.likespa]` for a private module in another repo.
5. Writable `var/updates/`; PHP needs `curl` + `ZipArchive`.

Optional public hint in `module.json` (candidates table only — not enough for apply):

```json
"update": {
  "repository": "https://github.com/org/likespa-private",
  "channel": "stable"
}
```

## Release artifacts (stable)

CI / [`bin/build-release-artifacts.sh`](../../bin/build-release-artifacts.sh) builds:

- `freesaas-<VERSION>.zip` — whole tree minus private modules (`PRIVATE_MODULES=likespa`)
- `freesaas-manifest.json` — `{kind:"full", version, sha256, artifact}`

Attach both to the GitHub Release (see `.github/workflows/release-artifacts.yml`).

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

1. Bump `VERSION` (+ `modules/core/module.json`); commit & push.
2. Tag / publish a GitHub Release → CI attaches `freesaas-*.zip` + manifest.
3. Instances on `channel=stable` pull the release; `channel=main` can use zipball without a Release.
4. Private module in another repo → `[module.id]` + optional `--module=id` zip.
