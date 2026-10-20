# Certificates

Product module `certificates`: prepaid gift certificates (money or abonnement) with a ledger as the money source of truth.

## Enable

```bash
php bin/freesaas.php module enable certificates --accept-risk --format=json
php bin/freesaas.php migrate run --module=certificates --format=json
```

Or `[modules] certificates=1` in `cfg/config.ini`. Role flag `EVT_SERT` shows apply UI on calendar events.

Optional one-shot cleanup of unused legacy tables:

```bash
php bin/data-migrate.php --module=certificates --id=drop_legacy_sert
```

## Tables

| Table | Role |
|-------|------|
| `base_certificates` | Certificate header (CODE, KIND, amounts, JSON content/restrictions) |
| `base_certificate_ledger` | Credits/debits (`issue` / `spend` / `expire` / `undo` / `adjust`) |

Owned exclusively by `certificates` (`module.json` → `tables`).

## Human UI

- `/certificates` — list / create / edit
- `/certificates/ledger` — journal; superadmin can undo a spend row
- Calendar event edit — certificate section when module on + `EVT_SERT`
- Storefront `/cart` — CODE block via `sites.cart.prepare`; apply/reset POST `/certificates/cart/apply|reset`
- Client profile — bought / active counts

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/certificates?format=json'
curl -sS -b jar.txt 'https://example/certificates/ledger?id_cert=1&format=json'
curl -sS -b jar.txt 'https://example/certificates/events/456/quote?code=ABC&format=json'
curl -sS -b jar.txt -X POST -d 'cert_code=ABC' 'https://example/certificates/events/456/apply?format=json'
```

## Notes

- Greenfield schema (not the old `base_sertificates` spelling). No serialize CONTENT, no regen, no `base_client_payments`.
- KIND `1` = money, `2` = abon. Ledger balance drives spend; `USED_AMOUNT` is a cache.
- Cron `certificates.expire` writes off overdue remainders.
- Soft deps: calendar, sites, orders, bonuses (`BONUS_FORBIDDEN` vs event bonus discount).
