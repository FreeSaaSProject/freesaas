# Remote agent API (shared hosting)

Same operations as `php bin/freesaas.php`, over HTTPS. Use when the agent **cannot SSH** into the host.

**First install:** see [install.md](install.md) (`POST /agent/bootstrap`).

## Setup

In `cfg/config.ini` (not committed):

```ini
[app]
agent_token="a-long-random-secret"
```

Copy from `cfg/config.example.ini` comment. Empty / `CHANGE_ME` → `/agent/*` returns **503** (except virgin catalog + bootstrap).

## Auth

Send on every request (after bootstrap):

- Header: `X-FreeSaaS-Agent-Token: <secret>`
- or query/body: `?token=<secret>` (prefer header)

**Virgin install** (no staff users yet):

- `GET /agent` (catalog) and `POST /agent/bootstrap` work **without** a prior header.
- Bootstrap JSON **must** include `agent_token` (or it must already be set in config) so later calls can authenticate.

## Catalog

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent"
```

Returns `ops[]` with method/path (includes bootstrap).

## Bootstrap

```bash
curl -sS -X POST "https://example.com/agent/bootstrap" \
  -H "Content-Type: application/json" \
  -d '{
    "login": "admin",
    "password": "change-me-now-8+",
    "name": "Admin",
    "agent_token": "a-long-random-secret",
    "modules": ["units","clients"]
  }'
```

Creates first admin + migrations + persists token. Safe to re-run: skips admin creation if users already exist.

## Examples

```bash
# Status
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/system/status"

# Modules
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules"

# Enable + migrate (POST)
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules/equipment/enable"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/migrate/run?module=equipment"

# Health / validate
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/health?module=equipment"

curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules/equipment/validate"
```

JSON shape matches CLI `--format=json` (via shared `AgentOps`). HTTP status: 200 ok, 400 bootstrap validation, 401 bad token, 503 token unset / health fail, 422 validate fail, 404 unknown module/path.

## CLI vs HTTP

| | CLI | HTTP `/agent` |
|--|-----|----------------|
| Needs | PHP CLI + filesystem on box | Only HTTPS to docroot |
| Shared hosting | Often no | **Yes** |
| Code deploy | Still FTP/git/CI | Still FTP/git/CI |
| First admin | `freesaas.php bootstrap` | `POST /agent/bootstrap` |
| Public `/health` | — | Minimal probe **without** token |

Deploying new module **files** still requires upload. Enable/migrate/validate/health after upload → Agent API or CLI.

## Security

- Use a long random token; rotate if leaked.
- Prefer HTTPS only.
- Do not commit `agent_token`.
- Token grants full ops surface (enable/disable/migrate) — treat like root.
- Close the virgin window ASAP (bootstrap creates the first user).
