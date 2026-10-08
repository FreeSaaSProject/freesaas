# YClients (pull)

Product module `yclients`: pull-only import from YClients into FreeSaaS (`base_clients` + id map). No push back.

## Enable

```bash
cp modules/yclients/config.example.ini cfg/modules/yclients.ini
php bin/freesaas.php module enable yclients --format=json
php bin/freesaas.php migrate run --module=yclients --format=json
```

Ini: `company_id`, `partner_token`, `user_token`, `worker_token`, `pull_enabled`.

## Agent JSON (session)

Same routes as UI; `?format=json` or `Accept: application/json`. Ops: `module.json` → `agent.ops`. Settings require `SUPERADMIN_ACCESS`.

```bash
# Checklist (missing fields + next_steps)
curl -sS -b jar.txt 'https://example/yclients?format=json'

# Save tokens (empty secret field = keep previous)
curl -sS -b jar.txt -X POST 'https://example/yclients/settings?format=json' \
  -d 'pull_enabled=1&company_id=123&partner_token=…&user_token=…&worker_token=…'

# Verify API without cron token
curl -sS -b jar.txt -X POST 'https://example/yclients?format=json' -d 'action=ping'

# Dry-run clients pull
curl -sS -b jar.txt -X POST 'https://example/yclients/import?format=json' \
  -d 'action=clients&dry_run=1'
```

Cron (not for assistant catalog): `POST /yclients/jobs/ping` and `/yclients/jobs/import-clients` with header `X-Yclients-Token`.

## Notes

- Identity match: external id map → normalized phone → create.
- Secrets are masked in GET settings/status; never returned plaintext.
