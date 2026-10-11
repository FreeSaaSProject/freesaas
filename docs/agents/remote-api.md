# Remote agent API (shared hosting)

Same operations as `php bin/freesaas.php`, over HTTPS. Use when the agent **cannot SSH** into the host.

**First install:** see [install.md](install.md) (`POST /agent/bootstrap`).  
**Auth vs product MCP:** [agent-surfaces.md](agent-surfaces.md). Diagnose: `GET /agent/doctor`.

## Setup

In `cfg/config.ini` (not committed):

```ini
[app]
agent_token="a-long-random-secret"
```

Or generate it in the UI: **Settings → Agent token** (`/settings`). Regenerating invalidates old dump links and `/agent/*` calls.

Copy from `cfg/config.example.ini` comment. Empty / `CHANGE_ME` → every `/agent/*` call returns **503** until you set a token (or use `var/setup.key` for bootstrap/rescue only).

## Auth

Send on every `/agent/*` request:

- Header: `X-FreeSaaS-Agent-Token: <secret>`
- or `Authorization: Bearer <secret>`

Do **not** put the agent token in the query string or POST body (`?token=` is ignored). It leaks into access logs, Referer, and browser history.

Dump download (`GET /agent/db/dump/file`): same headers, **or** a short-lived signed URL from `/db` → «Copy link» (`?file=&exp=&sig=` — HMAC, not the raw token).

**First install (HTTP):** there is **no** anonymous virgin door. Before `POST /agent/bootstrap` you must either:

1. Pre-set `app.agent_token` in `cfg/config.ini` (FTP) and send `X-FreeSaaS-Agent-Token`, **or**
2. Upload `var/setup.key` (from `php bin/freesaas.php setup-key generate`) and send `X-FreeSaaS-Setup-Key` (bootstrap JSON should still include `agent_token` for later calls).

Canonical steps: [install.md](install.md). CLI bootstrap does not need HTTP auth.

## Catalog

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent"
```

Returns `ops[]` with method/path (includes bootstrap), `auth.bootstrap_note`, plus top-level `maturity_policy` (alpha/beta/release agent rules). Module ops declare `query` / `body` where relevant (`module.list` filters, `module.enable` → `accept_risk`).

## Bootstrap

```bash
# Token already in cfg/config.ini
curl -sS -X POST "https://example.com/agent/bootstrap" \
  -H "Content-Type: application/json" \
  -H "X-FreeSaaS-Agent-Token: a-long-random-secret" \
  -d '{
    "login": "admin",
    "password": "change-me-now-8+",
    "name": "Admin",
    "modules": ["units","clients"]
  }'
```

Creates first admin + migrations (+ persists `agent_token` from JSON when using setup.key). Safe to re-run: skips admin creation if users already exist.

## Examples

```bash
# Status
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/system/status"

# Doctor (error_codes + issue_body for GitHub)
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/doctor"

# Modules catalog (filters: q, category, tag, status, maturity=alpha|beta|release)
# Response includes maturity_policy + per-module maturity / agent_note / requires_risk_ack
# MCP/assistant twin (session + core.modules): core.modules.list / core.modules.get on /modules
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules?category=finance&maturity=alpha"

# Inspect one module (bilocale name/description/summary + deps + catalog + maturity_policy)
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules/ledger"

# Install a module that is in the remote catalog but not on disk yet
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/modules/banks/install"

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

# Self-update (requires cfg/updates.ini) — see update.md
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/update"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"target":"core"}' \
  "https://example.com/agent/update/apply"

# Module repair (enabled modules register these into GET /agent catalog)
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/bots/settings"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"audience":"staff","platform":"max"}' \
  "https://example.com/agent/bots/webhook/subscribe"
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/agent/gcal/status"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":"main","api_key":"sk-…","set_default":true}' \
  "https://example.com/agent/llm/tokens"
```

JSON shape matches CLI `--format=json` (via shared `AgentOps`). HTTP status: 200 ok, 400 bootstrap validation, 401 bad token, 503 token unset / health fail, 422 validate fail, 404 unknown module/path, 409 update lock.

Module repair paths are still Surface A (same token) — not a fourth auth layer. See [agent-surfaces.md](agent-surfaces.md).

## Background cron (same agent token, not under `/agent/*`)

Platform job registry uses the **same** `X-FreeSaaS-Agent-Token`, but lives on product routes (like habits reminders). See [cron.md](cron.md).

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/cron/status"

curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  "https://example.com/cron/tick"
# optional: ?job=habits.reminders&force=1
```

CLI twin: `php bin/freesaas.php cron tick` — there is no `/agent/cron/*` alias; `/cron/*` is the HTTP twin.

## CLI vs HTTP

| | CLI | HTTP `/agent` |
|--|-----|----------------|
| Needs | PHP CLI + filesystem on box | Only HTTPS to docroot |
| Shared hosting | Often no | **Yes** |
| Code deploy | `update apply` or FTP/git/CI | `POST /agent/update/apply` or FTP/git/CI |
| First admin | `freesaas.php bootstrap` | `POST /agent/bootstrap` |
| Public `/health` | — | Minimal probe **without** token |
| Cron tick / status | `cron tick` | `POST /cron/tick`, `GET /cron/status` (agent token) |

Self-update (HTTPS zip + token): see [update.md](update.md). Or upload files manually, then enable/migrate/validate/health via Agent API or CLI.

## Security

- Use a long random token; rotate if leaked.
- Prefer HTTPS only.
- Do not commit `agent_token` or `var/setup.key`.
- Token grants full ops surface (enable/disable/migrate) — treat like root.
- No open virgin HTTP bootstrap: a secret (token or setup.key) must exist before the first `/agent/*` call.
