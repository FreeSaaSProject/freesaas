# Install / bootstrap (autonomous)

Bring up a FreeSaaS instance with **no SSH** (shared hosting) or with CLI. After bootstrap: migrate schema, first superadmin, `app.agent_token`, health.

## Prerequisites

1. Upload / clone the project to the docroot.
2. **PHP ≥ 8.1** with extensions: `pdo_mysql`, `curl`, `zip`, `mbstring` (check: `php -m`).
3. MySQL / MariaDB database created; user can CREATE/ALTER.
4. Writable `cfg/` (config.ini + `cfg/modules/`).
5. Copy `cfg/config.example.ini` → `cfg/config.ini` and fill `[connect]` (`host_db`, `user_db`, `pass_db`, `dbase`) and `[salts] site=…`.

Module product secrets (optional): copy `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini` when needed.

### Shared hosting web root

- Prefer `DirectoryIndex index.php index.html` so a leftover host `index.html` does not hide the app.
- FreeSaaS `.htaccess` avoids `Options` / `DirectorySlash` (often forbidden on shared hosts).
- Docroot should be the project root (`index.php` visible); `app|cfg|bin|docs` stay blocked from the web.

## One-shot bootstrap (preferred)

### Shared hosting (HTTPS only)

Virgin install is **not** open: pre-set `app.agent_token` in `cfg/config.ini` (FTP), **or** upload `var/setup.key` (from `php bin/freesaas.php setup-key generate`).

```bash
# Option A — token already in cfg/config.ini [app] agent_token=…
curl -sS -X POST "https://example.com/agent/bootstrap" \
  -H "Content-Type: application/json" \
  -H "X-FreeSaaS-Agent-Token: a-long-random-secret" \
  -d '{
    "login": "admin",
    "password": "change-me-now-8+",
    "name": "Admin",
    "modules": ["units","suppliers","clients"]
  }'

# Option B — break-glass file (TTL 24h; auto-cleared after successful bootstrap)
curl -sS -X POST "https://example.com/agent/bootstrap" \
  -H "Content-Type: application/json" \
  -H "X-FreeSaaS-Setup-Key: $(cat var/setup.key)" \
  -d '{
    "login": "admin",
    "password": "change-me-now-8+",
    "name": "Admin",
    "agent_token": "a-long-random-secret",
    "modules": ["units","suppliers","clients"]
  }'
```

Effects:

1. Saves `app.agent_token` into `cfg/config.ini` when provided in JSON
2. Runs schema migrations (enabled modules + any listed in `modules`)
3. Ensures a Superadmin role (`SUPERADMIN_ACCESS`; shift flags `EVT_ASSIGN` / `CAN_BE_ASSIGNED` when present)
4. Creates the first staff user with all discovered permissions
5. Seeds `base_system` if empty
6. Clears `var/setup.key` if present; returns health summary

Then sign in at `/login`. Further ops:

```bash
export TOKEN='a-long-random-secret'
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent"
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/health"
```

`GET /agent` always requires `agent_token` (or is 503 until configured).

### CLI (SSH / local)

```bash
php bin/freesaas.php bootstrap \
  --login=admin \
  --password='change-me-now-8+' \
  --name=Admin \
  --agent-token='a-long-random-secret' \
  --modules=units,suppliers,clients \
  --format=json
```

Omit `--agent-token` if already set in `cfg/config.ini`. On a non-virgin DB, login/password are optional (migrate + health only; admin is not recreated).

After a first bootstrap (especially if migrate stopped mid-run), re-run idempotently:

```bash
php bin/freesaas.php migrate run --format=json
php bin/freesaas.php health --format=json
```

Expect `setup.pending: 0` (or no pending migrations) and health without `platform.schema_pending` fail.

## Lost login / break-glass

`/db` is **not** anonymous. If staff login is lost but the DB still has users:

```bash
# SSH / local — always available (no setup.key needed)
php bin/freesaas.php rescue --login=admin --password='new-secret-8+' --format=json

# Shared hosting without SSH: generate key locally, FTP-upload var/setup.key (0600, TTL 24h), then:
php bin/freesaas.php setup-key generate --format=json   # copy file to host
curl -sS -X POST "https://example.com/agent/rescue" \
  -H "Content-Type: application/json" \
  -H "X-FreeSaaS-Setup-Key: $(cat var/setup.key)" \
  -d '{"login":"admin","password":"new-secret-8+"}'
# setup.key is cleared automatically on success; health warns while the file remains
```

Pass `setup_key` only via header or JSON body — never query string.

Schema-only (safe) while oper still runs: `migrate run`. Oper→FreeSaaS **data** replace migrators are intentional and destructive — run later via CLI with `--module`/`--id` or `--i-understand-replace`, or via authenticated `/db` after rescue/login.

## Staff roles: least privilege

- Bootstrap creates the Superadmin role with `SUPERADMIN_ACCESS` for the first admin (director) only.
- For other administrators create a separate role **without** `SUPERADMIN_ACCESS` and grant module permissions explicitly (e.g. copy the director's set and trim it).
- Capability-gated screens (module settings, statuses and roles, updates, OAuth/MCP) remain director-only — expected, not a bug.
- Ordinary staff pickers use `listEnabledStaff()` / `staffNamesIncluding()`; `listForCopy()` is reserved for copyFrom.
- Checklist for a new role: `SUPERADMIN_ACCESS` = 0 (unless director), `ENABLED` = 1, assignment flags only for those who plan shifts, then grant permissions per user and verify one gated screen.

## FTP-only chicken-egg

| Step | Action |
|------|--------|
| 1 | FTP upload code + write `cfg/config.ini` with DB + salts **and** `app.agent_token=…` (or upload `var/setup.key`) |
| 2 | `POST /agent/bootstrap` with header `X-FreeSaaS-Agent-Token` (or `X-FreeSaaS-Setup-Key`) + `login` / `password` (+ `agent_token` in JSON if using setup.key) |
| 3 | Use header `X-FreeSaaS-Agent-Token` for `/agent/*` forever after; setup.key is auto-cleared |

There is no anonymous virgin bootstrap — a secret must exist before the first HTTP call.

## Dual host (admin + public storefront)

Same package, same `[connect]` database, two vhosts:

1. **Admin** — `DirectoryIndex index.php` (default `.htaccess`).
2. **Public** — `SetEnv FREESAAS_FRONT site` in the vhost, **or** use [`cfg/htaccess.storefront.example`](../../cfg/htaccess.storefront.example) / `site.php`.

## nginx (deny sensitive paths)

Apache ships path denials in root `.htaccess` (`app|cfg|bin|docs|var|log`, theme PHP, module PHP outside `assets/`). nginx ignores `.htaccess` — add an equivalent block to the vhost (document root = FreeSaaS package root):

```nginx
location ~ ^/(app|cfg|bin|docs|var|log)/ { deny all; }
location ~ ^/themes/[^/]+/(layouts|views|partials)/ { deny all; }
location ~ ^/modules/[^/]+/(?!assets/).*\.(php)$ { deny all; }
```

`var/` holds PHP sessions, DB dumps (`var/db_dumps/`), and optional `var/setup.key` — never serve it.

Enable module `sites` (alpha — `--accept-risk`) plus `catalog` / `orders` / `clients` as needed. Set `app.public_host` / `app.admin_host` (or `cfg/modules/sites.ini`). Full runbook: [sites.md](sites.md).

Bootstrap example with storefront modules:

```bash
php bin/freesaas.php bootstrap \
  --login=admin \
  --password='change-me-now-8+' \
  --agent-token='a-long-random-secret' \
  --modules=sites,catalog,orders,clients \
  --format=json
# sites is alpha: enable with risk ack if bootstrap did not
php bin/freesaas.php module enable sites --accept-risk --format=json
```

## Background jobs (cron)

Bootstrap does **not** install crontab. Prefer **one** host line that wakes the platform registry (see [cron.md](cron.md)):

```cron
*/5 * * * * curl -sS -H "X-FreeSaaS-Agent-Token: YOUR_TOKEN" "https://example.com/cron/tick" >/dev/null
```

CLI twin: `php bin/freesaas.php cron tick --format=json`. Modules register jobs with `Cron::register` (habits, bots drain, gcal, banks, terminals load, CRM pulls, …). Per-module `/…/jobs/*` worker_token endpoints remain for manual/external use. Whisper/ASR Mac workers still poll their own URLs — they are not part of `/cron/tick`.

## Self-update / remote module install

Copy `cfg/updates.example.ini` → `cfg/updates.ini` and set a GitHub token (and repo/ref as needed). Without it, health/update status shows `update_configured: false` and remote module install / self-update stay unavailable. Never commit real tokens.

## Safety

- Bootstrap **creates an admin only when there are no users**. Re-running on a live DB migrates / refreshes health; it does not wipe users.
- Password minimum length: 8.
- Treat `agent_token` like root; never commit it.
- Prefer HTTPS.

## Verify

```bash
php bin/freesaas.php system status --format=json
php bin/freesaas.php health --format=json
php bin/smoke.php --format=json
# or
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/system/status"
```

Public probe (no token): `GET /health`.

See also: [remote-api.md](remote-api.md), [migrations.md](migrations.md), [debugging.md](debugging.md).
