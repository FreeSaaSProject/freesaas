# Settlements

Product module `payments`: Enable for paying specialists and settlement docs. Soft-used by ledger (auto-DPS) and likespa. Use with contracts for pay CTAs. Accounting side of payroll to staff. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable payments --accept-risk --format=json
```

Or `[modules] payments=1` in `cfg/config.ini`.

Permissions: `payments.view`, `payments.edit`, `payments.types`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `ledger` >=0.1.0, `bots` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_payments` | Owned by `payments` |
| `base_payment_types` | Owned by `payments` |
| `base_payment_move_links` | Owned by `payments` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/payments` — staff settlements.
- `/payment-types` — payment type dictionary.
- Contract pay CTA when contracts enabled; auto-DPS when ledger on.

| Path | Permission |
|------|------------|
| `/payments` | `—` |
| `/payment-types` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (9 ops).

| Op | Method | Path |
|----|--------|------|
| `payments.list` | GET | `/payments` |
| `payments.get_edit` | GET | `/payments/{id}/edit` |
| `payments.create` | POST | `/payments/new` |
| `payments.update` | POST | `/payments/{id}/edit` |
| `payments.move_links` | POST | `/payments/move-links/{moveId}` |
| `payments.types.list` | GET | `/payment-types` |
| `payments.types.get` | GET | `/payment-types/{id}/edit` |
| `payments.types.create` | POST | `/payment-types/new` |
| `payments.types.update` | POST | `/payment-types/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/payments?format=json'
curl -sS -b jar.txt 'https://example/payment-types?format=json'
```

## Notes

- Links to ledger moves via `/payments/move-links/{moveId}`.
