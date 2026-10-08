# Clientics / Клиентикс (pull)

Product module `clientics`: pull-only import from Clientics into FreeSaaS (`base_clients` + id map). Webhook upserts clients by phone. No push back.

## Enable

```bash
cp modules/clientics/config.example.ini cfg/modules/clientics.ini
php bin/freesaas.php module enable clientics --format=json
php bin/freesaas.php migrate run --module=clientics --format=json
```

Ini: `domain`, `account_id`, `user_id`, `access_token`, `worker_token`, `webhook_token`, `pull_enabled`.

## Agent JSON (session)

```bash
curl -sS -b jar.txt 'https://example/clientics?format=json'

curl -sS -b jar.txt -X POST 'https://example/clientics/settings?format=json' \
  -d 'pull_enabled=1&account_id=…&user_id=…&access_token=…&worker_token=…&webhook_token=…'

curl -sS -b jar.txt -X POST 'https://example/clientics?format=json' -d 'action=ping'

curl -sS -b jar.txt -X POST 'https://example/clientics/import?format=json' \
  -d 'action=clients&dry_run=1&from=2026-01-01&to=2026-01-07'
```

Webhook (Clientics eventHandlers → our URL):

`POST /clientics/webhook?token=WEBHOOK_TOKEN` (or header `X-Clientics-Webhook-Token`).

Cron: `X-Clientics-Token` on `/clientics/jobs/*` (`assistant: false`).

## Notes

- Client list API is day-scoped — import always needs `from`/`to` (max 400 days).
- Secrets masked in GET responses.
