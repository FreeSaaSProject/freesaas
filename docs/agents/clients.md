# Clients CRM

Product module `clients`: slim CRM (`base_clients`), statuses (`base_client_statuses`), tags (`base_shopusers_tags` + `base_client_tag_links`). Login identity = phone (`tel`).

## Enable

```bash
php bin/freesaas.php module enable clients --format=json
php bin/freesaas.php module validate clients
php bin/smoke.php
```

Permissions: `clients.view`, `clients.edit`, `clients.statuses`, `clients.tags`.

## Human UI

| Path | Role |
|------|------|
| `/clients` | List / filters / bulk |
| `/clients/{id}` | Read-only card |
| `/clients/new`, `/clients/{id}/edit` | Create / edit |
| `/client-statuses` | Status dictionary |
| `/client-tags` | Tags dictionary |

Extension hooks (other modules): `clients.list`, `clients.edit.sections`, `clients.edit.save`, `clients.view.profile`. 1C visit bindings / staff NFC live in `terminals` (not on `base_clients`).

## Agent JSON (session)

Same routes as UI; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/clients?format=json'
curl -sS -b jar.txt 'https://example/clients?q=7900&enabled=1&status_id=2&format=json'
curl -sS -b jar.txt 'https://example/clients/123?format=json'
curl -sS -b jar.txt -X POST -d 'tel=79001234567&name=Test&enabled=1' \
  'https://example/clients/new?format=json'
curl -sS -b jar.txt 'https://example/client-statuses?format=json'
curl -sS -b jar.txt 'https://example/client-tags?format=json'
```

Password hashes are omitted from JSON (`ClientsRepository::forAgent`). Create/update still accept `password` in POST when set.

## Notes

- Default list filter `enabled=1` (active only); pass `enabled=` for all.
- Tag assignment on save: POST `tag_ids[]` (replaces set).
- Bulk delete/enable/disable/tag via HTML list form (same as other modules).
