# YClients (pull)

Product module `yclients`: pull-only import from YClients into FreeSaaS (`base_clients` + id map). No push back.

## Credentials (order matters)

| Field | Source | Notes |
|-------|--------|--------|
| `partner_token` | Marketplace developer account → API key | Required for **every** API call, including auth. |
| `user_token` | `POST /api/v1/auth` with salon login/password, **or** app system user after marketplace «Подключить» | **Not** the salon password. |
| `company_id` | URL `yclients.com/company/{id}/` | Filial / salon id. |
| `worker_token` | Invent in FreeSaaS | Cron secret for `/yclients/jobs/*` only — **not** from YClients. |

Header for data calls: `Authorization: Bearer <partner_token>, User <user_token>` + `Accept: application/vnd.yclients.v2+json`.

**Dev / cutover:** save partner → `action=auth_login` (login+password) → set company_id → ping → dry-run import.

**Marketplace product:** one webhook/callback/registration URL per **app** (not per FreeSaaS host). Multi-instance needs a hub that routes by `salon_id`.

## Enable

```bash
cp modules/yclients/config.example.ini cfg/modules/yclients.ini
php bin/freesaas.php module enable yclients --format=json --accept-risk
php bin/freesaas.php migrate run --module=yclients --format=json
```

Ini: `company_id`, `partner_token`, `user_token`, `worker_token`, `pull_enabled`.

## Agent JSON (session)

Same routes as UI; `?format=json` or `Accept: application/json`. Ops: `module.json` → `agent.ops`. Settings require `SUPERADMIN_ACCESS`.

```bash
# Checklist (missing fields + next_steps + token_hints)
curl -sS -b jar.txt 'https://example/yclients?format=json'

# Save tokens (empty secret field = keep previous)
curl -sS -b jar.txt -X POST 'https://example/yclients/settings?format=json' \
  -d 'pull_enabled=1&company_id=123&partner_token=…&user_token=…&worker_token=…'

# Or obtain user_token from salon login (password not stored)
curl -sS -b jar.txt -X POST 'https://example/yclients/settings?format=json' \
  -d 'action=auth_login&login=user@example.com&password=…'

# Verify API without cron token
curl -sS -b jar.txt -X POST 'https://example/yclients?format=json' -d 'action=ping'

# Dry-run clients / staff pull
curl -sS -b jar.txt -X POST 'https://example/yclients/import?format=json' \
  -d 'action=clients&dry_run=1'
curl -sS -b jar.txt -X POST 'https://example/yclients/import?format=json' \
  -d 'action=staff&dry_run=1'
```

Cron (not for assistant catalog): `POST /yclients/jobs/ping` and `/yclients/jobs/import-clients` with header `X-Yclients-Token`.

## Notes

- Clients: id map → normalized phone → create.
- Staff: id map → login `yc{phone}` / `ycstaff_{id}` → `base_users` with first non-`SUPERADMIN` status; random password (reset later).
- Secrets are masked in GET settings/status; never returned plaintext.
- Ping errors name missing fields (`company_id` / `partner_token` / `user_token`) and hint auth_login when only user_token is missing.
- Some YClients endpoints (clients/services/records) require an active salon license; staff/company may still work when the filial is unpaid.

