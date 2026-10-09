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

No `agent_token` in config yet is OK while the install has **zero** staff users (`base_users` empty or missing).

```bash
curl -sS -X POST "https://example.com/agent/bootstrap" \
  -H "Content-Type: application/json" \
  -d '{
    "login": "admin",
    "password": "change-me-now-8+",
    "name": "Admin",
    "agent_token": "a-long-random-secret",
    "modules": ["units","suppliers","clients"]
  }'
```

Effects:

1. Saves `app.agent_token` into `cfg/config.ini`
2. Runs schema migrations (enabled modules + any listed in `modules`)
3. Ensures a Superadmin role (`SUPERADMIN_ACCESS`; shift flags `EVT_ASSIGN` / `CAN_BE_ASSIGNED` when present)
4. Creates the first staff user with all discovered permissions
5. Seeds `base_system` if empty
6. Returns health summary

Then sign in at `/login`. Further ops:

```bash
export TOKEN='a-long-random-secret'
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent"
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/health"
```

Catalog `GET /agent` is also open **only while virgin**; after the first user exists, token is required.

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

## FTP-only chicken-egg

| Step | Action |
|------|--------|
| 1 | FTP upload code + write `cfg/config.ini` with DB + salts |
| 2 | `POST /agent/bootstrap` with `login`, `password`, `agent_token` in JSON |
| 3 | Use header `X-FreeSaaS-Agent-Token` for `/agent/*` forever after |

If `cfg/` is not writable from PHP, pre-set `agent_token` in the ini via FTP, then call bootstrap with the same token in the header (or body).

## Background jobs (cron)

Bootstrap does **not** install crontab. For bots queue, bank/CRM syncs, audio pipeline workers, etc., schedule CLI or HTTP agent jobs on the host, for example:

```cron
*/5 * * * * cd /path/to/freesaas && php bin/freesaas.php health >/dev/null 2>&1
```

Use the module’s documented job entrypoints (`module.json` → `jobs`, or agent ops) rather than inventing new runners.

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
