# Financial reports

Product module `finance`: Enable for managerial finance reports (balance, ODDS/OPIU views) built from ledger account moves. Requires ledger. Soft-depends on funds for FUND_MATRIX rows. Read-only reporting — does not replace ledger or banks. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable finance --accept-risk --format=json
```

Or `[modules] finance=1` in `cfg/config.ini`.

Permissions: `finance.view`.

## Dependencies

- Requires: `core` >=0.3.0, `ledger` >=0.1.0
- Soft: `funds` >=0.1.0

## Human UI

- `/finance/balance` — balance / ODDS / OPIU report (no inflation schedule).
- `/finance` — redirect to `/finance/balance` (alias for invented module-root links).

| Path | Permission |
|------|------------|
| `/finance/balance` | `finance.view` |
| `/finance` | `finance.view` (redirect) |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (1 ops).

| Op | Method | Path |
|----|--------|------|
| `finance.balance` | GET | `/finance/balance` |

```bash
curl -sS -b jar.txt 'https://example/finance/balance?format=json'
```

## Notes

- Soft `funds` for FUND_MATRIX.
- Requires ledger data context when funds matrix used.
