# Funds

Product module `funds`: Enable after ledger when you need internal funds (tree of funds) and fund movements (CAPUSE/REFUND). Hard-requires ledger. Soft-used by finance reports and liquid assets for FUND_MATRIX / REVAL. Do not enable without ledger accounts already set up. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable funds --accept-risk --format=json
```

Or `[modules] funds=1` in `cfg/config.ini`.

Permissions: `funds.view`, `funds.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `ledger` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_funds` | Owned by `funds` |
| `base_fund_movements` | Owned by `funds` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/funds` — fund tree.
- `/fund-movements` — CAPUSE / REFUND movements.

| Path | Permission |
|------|------------|
| `/funds` | `—` |
| `/fund-movements` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (9 ops).

| Op | Method | Path |
|----|--------|------|
| `funds.list` | GET | `/funds` |
| `funds.accrual` | POST | `/funds` |
| `funds.get_edit` | GET | `/funds/{id}/edit` |
| `funds.create` | POST | `/funds/new` |
| `funds.update` | POST | `/funds/{id}/edit` |
| `funds.movements.list` | GET | `/fund-movements` |
| `funds.movements.get_edit` | GET | `/fund-movements/{id}/edit` |
| `funds.movements.create` | POST | `/fund-movements/new` |
| `funds.movements.update` | POST | `/fund-movements/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/funds?format=json'
curl -sS -b jar.txt 'https://example/fund-movements?format=json'
```

## Notes

- Requires `ledger`.
