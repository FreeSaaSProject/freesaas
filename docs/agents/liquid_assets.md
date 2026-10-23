# Liquid assets

Product module `liquid_assets`: personal assets tracking for instances deployed for personal use — deposits, current accounts and a T-Invest (T-Bank only) broker portfolio in one monthly report. Not intended for corporate use: no per-user separation, everyone with module permissions sees the same report and accounts. T-Invest tokens are stored per account in `base_tinvest_accounts` (masked in UI/JSON). Catalog maturity: `release`.

## Enable

```bash
php bin/freesaas.php module enable liquid_assets --format=json
```

Or `[modules] liquid_assets=1` in `cfg/config.ini`.

Permissions: `liquid_assets.view`, `liquid_assets.edit`.

## Dependencies

- Requires: `core` >=1.0.0, `ledger` >=1.0.0
- Soft: `funds` >=1.0.0

## Tables

| Table | Role |
|-------|------|
| `base_tinvest_accounts` | Owned by `liquid_assets` |
| `base_broker_snapshots` | Owned by `liquid_assets` |
| `base_broker_positions` | Owned by `liquid_assets` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/liquid-assets` — liquid assets report + sync.
- `/liquid-assets/accounts` — T-Invest / broker accounts.

| Path | Permission |
|------|------------|
| `/liquid-assets` | `—` |
| `/liquid-assets/accounts` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (6 ops).

| Op | Method | Path |
|----|--------|------|
| `liquid_assets.report` | GET | `/liquid-assets` |
| `liquid_assets.sync` | POST | `/liquid-assets` |
| `liquid_assets.accounts.list` | GET | `/liquid-assets/accounts` |
| `liquid_assets.accounts.get_edit` | GET | `/liquid-assets/accounts/{id}/edit` |
| `liquid_assets.accounts.create` | POST | `/liquid-assets/accounts/new` |
| `liquid_assets.accounts.update` | POST | `/liquid-assets/accounts/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/liquid-assets?format=json'
curl -sS -b jar.txt 'https://example/liquid-assets/accounts?format=json'
```

## Notes

- Requires `ledger`; soft `funds` for REVAL.
