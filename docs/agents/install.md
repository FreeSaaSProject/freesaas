# Install / bootstrap (autonomous)

Bring up a FreeSaaS instance with **no SSH** (shared hosting) or with CLI. After bootstrap: migrate schema, first superadmin, `app.agent_token`, health.

## Prerequisites

1. Upload / clone the project to the docroot.
2. MySQL database created; user can CREATE/ALTER.
3. Writable `cfg/` (config.ini + `cfg/modules/`).
4. Copy `cfg/config.example.ini` → `cfg/config.ini` and fill `[connect]` (`host_db`, `user_db`, `pass_db`, `dbase`) and `[salts] site=…`.

Module product secrets (optional): copy `modules/<id>/config.example.ini` → `cfg/modules/<id>.ini` when needed.

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
3. Ensures a Superadmin role (`SUPERADMIN_ACCESS`)
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

## FTP-only chicken-egg

| Step | Action |
|------|--------|
| 1 | FTP upload code + write `cfg/config.ini` with DB + salts |
| 2 | `POST /agent/bootstrap` with `login`, `password`, `agent_token` in JSON |
| 3 | Use header `X-FreeSaaS-Agent-Token` for `/agent/*` forever after |

If `cfg/` is not writable from PHP, pre-set `agent_token` in the ini via FTP, then call bootstrap with the same token in the header (or body).

## Safety

- Bootstrap **creates an admin only when there are no users**. Re-running on a live DB migrates / refreshes health; it does not wipe users.
- Password minimum length: 8.
- Treat `agent_token` like root; never commit it.
- Prefer HTTPS.

## Verify

```bash
php bin/freesaas.php system status --format=json
php bin/freesaas.php health --format=json
# or
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/system/status"
```

Public probe (no token): `GET /health`.

See also: [remote-api.md](remote-api.md), [migrations.md](migrations.md), [debugging.md](debugging.md).
