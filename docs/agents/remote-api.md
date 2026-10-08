# Remote agent API (shared hosting)

Same operations as `php bin/freesaas.php`, over HTTPS. Use when the agent **cannot SSH** into the host.

**First install:** see [install.md](install.md) (`POST /agent/bootstrap`).

## Setup

In `cfg/config.ini` (not committed):

```ini
[app]
agent_token="a-long-random-secret"
```

Or generate it in the UI: **Settings → Agent token** (`/settings`). Regenerating invalidates old dump links and `/agent/*` calls.

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

Returns `ops[]` with method/path (includes bootstrap), plus top-level `maturity_policy` (alpha/beta/release agent rules). Module ops declare `query` / `body` where relevant (`module.list` filters, `module.enable` → `accept_risk`).

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

# Modules catalog (filters: q, category, tag, status, maturity=alpha|beta|release)
# Response includes maturity_policy + per-module maturity / agent_note / requires_risk_ack
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules?category=finance&maturity=alpha"

# Inspect one module (bilocale name/description/summary + deps + catalog + maturity_policy)
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules/ledger"

# Enable + migrate (POST). Alpha/beta require accept_risk=1 (query, form, or JSON body)
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"accept_risk":true}' \
  "https://example.com/agent/modules/banks/enable"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules/equipment/enable"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/db/migrate/run?module=equipment"

# Apply ALL pending schema (preferred after DB restore)
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/db/migrate/run"

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
