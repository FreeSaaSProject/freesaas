# Self-update (core + private modules)

Instances pull release zips over HTTPS (optional token) without an external agent or git CLI. After overlay: existing migrate → health.

UI module: **`updates`** (rail: System → Updates, `/updates`). Engine: [`app/UpdateEngine.php`](../../app/UpdateEngine.php).

## Setup

1. Enable module `updates` (`[modules] updates=1` or Modules catalog).
2. Copy [`cfg/updates.example.ini`](../../cfg/updates.example.ini) → `cfg/updates.ini` (gitignored).
3. Point `[core]` at the public FreeSaaS release repo (or `provider=http` + `manifest_url`).
4. For private modules (e.g. `likespa`), add `[module.likespa]` with `repo` + read-only PAT / deploy token.
5. Ensure `var/updates/` is writable (created on first apply). PHP needs `curl` + `ZipArchive`.

Do **not** put update tokens in `module.json` or `cfg/modules/*.ini`. Optional public hint only:

```json
"update": {
  "repository": "https://github.com/org/likespa-private",
  "channel": "stable"
}
```

**Will a separate git module appear in the list automatically?**  
As a **candidate** (hints table) — yes, once `update.repository` is in that module’s `module.json` and the module is discovered on disk.  
As a **configured source** (check/apply) — **no**, until you add `[module.<id>]` in `cfg/updates.ini` (with token for private repos). Hints alone never download.

## Manifest

Release asset `freesaas-manifest.json` (or HTTP `manifest_url`):

```json
{
  "version": "0.3.1",
  "channel": "stable",
  "kind": "core",
  "url": "https://example.com/freesaas-core-0.3.1.zip",
  "sha256": "…",
  "artifact": "freesaas-core-0.3.1.zip"
}
```

Module manifests set `"kind":"module"` and `"module":"likespa"`. Build helpers: [`bin/build-release-artifacts.sh`](../../bin/build-release-artifacts.sh).

## Deny-list

Never overwritten: `cfg/` (including `updates.ini` / `updates.state.json`), `var/`, `log/`, `.git/`, `assets/uploads/`, `cfg/modules/*.ini`.

Core apply skips `modules/<id>/` when that id has its own `[module.<id>]` source (private overlay).

## CLI

```bash
php bin/freesaas.php update status --format=json
php bin/freesaas.php update check --format=json
php bin/freesaas.php update check --target=likespa --format=json
php bin/freesaas.php update apply --target=core --format=json
php bin/freesaas.php update apply --target=all --format=json
# skip migrations (rare):
php bin/freesaas.php update apply --target=core --no-migrate --format=json
```

`update status` includes `hints[]` (module.json candidates not yet in ini).

## HTTP platform (`app.agent_token`)

Catalog: `GET /agent` lists `update.status` / `update.check` / `update.apply`.

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/update"

curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/update/check?target=core"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"target":"likespa"}' \
  "https://example.com/agent/update/apply"
```

`GET /agent/system/status` includes `update_configured` + `update_sources`.

Same threat class as other `/agent/*` ops (can replace PHP). Prefer HTTPS; rotate tokens if leaked.

## Session / product JSON (`agent.ops`)

Module `updates` — session + `updates.view` / `updates.apply`. Also SUPERADMIN for apply.

| Op id | Call |
|-------|------|
| `updates.status` | `GET /updates?format=json` |
| `updates.check` | `GET /updates/check?format=json&target=…` |
| `updates.apply` | `POST /updates/apply?format=json` body `{target, migrate?}` |

```bash
curl -sS -b jar.txt 'https://example.com/updates?format=json'
curl -sS -b jar.txt 'https://example.com/updates/check?format=json&target=core'
curl -sS -b jar.txt -X POST -H 'Content-Type: application/json' \
  -d '{"target":"likespa"}' \
  'https://example.com/updates/apply?format=json'
```

## UI

**System → Updates** (`/updates`): configured sources + candidates from `module.json` → `update.repository`. Apply requires `updates.apply` + `SUPERADMIN_ACCESS`.

## Private git without git CLI

GitHub/GitLab archive or Release assets via HTTPS + Bearer / deploy token. Shared hosting does not need `git` or SSH keys.

## Publish flow

1. Tag / release on the public core repo → CI builds `freesaas-core-*.zip` + manifest (excludes private modules such as `likespa`).
2. Tag / release on the private module repo → `freesaas-module-likespa-*.zip` + manifest.
3. Instances with configured sources run `update check` / `apply` on their own schedule or via Updates UI / agent.
