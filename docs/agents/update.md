# Self-update (core + private modules)

Instances pull release zips over HTTPS (optional token) without an external agent or git CLI. After overlay: existing migrate → health.

## Setup

1. Copy [`cfg/updates.example.ini`](../../cfg/updates.example.ini) → `cfg/updates.ini` (gitignored).
2. Point `[core]` at the public FreeSaaS release repo (or `provider=http` + `manifest_url`).
3. For private modules (e.g. `likespa`), add `[module.likespa]` with `repo` + read-only PAT / deploy token.
4. Ensure `var/updates/` is writable (created on first apply). PHP needs `curl` + `ZipArchive`.

Do **not** put update tokens in `module.json` or `cfg/modules/*.ini`. Optional public hint only:

```json
"update": { "repository": "https://github.com/org/likespa-private", "channel": "stable" }
```

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

Session cookie + `core.settings`. Declared in `modules/core/module.json` → `agent.ops` (also on MCP / assistant tool catalog):

| Op id | Call |
|-------|------|
| `core.update.status` | `GET /settings/update?format=json` |
| `core.update.check` | `GET /settings/update/check?format=json&target=…` |
| `core.update.apply` | `POST /settings/update/apply?format=json` body `{target, migrate?}` (needs `SUPERADMIN_ACCESS`) |

```bash
curl -sS -b jar.txt 'https://example.com/settings/update?format=json'
curl -sS -b jar.txt 'https://example.com/settings/update/check?format=json&target=core'
curl -sS -b jar.txt -X POST -H 'Content-Type: application/json' \
  -d '{"target":"likespa"}' \
  'https://example.com/settings/update/apply?format=json'
```

Platform twin (token, no session): `/agent/update*`. In-system assistant / MCP: `AgentHttp::allOps()`.

## UI

**Settings → Self-update** (`/settings`): status table, Check, Apply (Apply requires `SUPERADMIN_ACCESS`).

## Private git without git CLI

GitHub/GitLab archive or Release assets via HTTPS + Bearer / deploy token. Shared hosting does not need `git` or SSH keys. Optional VPS `provider=git` is out of scope for v1.

## Publish flow

1. Tag / release on the public core repo → CI builds `freesaas-core-*.zip` + manifest (excludes private modules such as `likespa`).
2. Tag / release on the private module repo → `freesaas-module-likespa-*.zip` + manifest.
3. Instances with configured sources run `update check` / `apply` on their own schedule or via Settings / agent.
