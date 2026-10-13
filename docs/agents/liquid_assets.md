# Liquid assets

Product module `liquid_assets`: Enable for a liquid-assets snapshot (cash, deposits, broker) and T-Invest account sync into the report. Requires ledger. Soft funds for REVAL. Use when management tracks liquidity beyond plain ДПС; needs broker API credentials in module ini when syncing T-Invest. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable liquid_assets --accept-risk --format=json
```

Or `[modules] liquid_assets=1` in `cfg/config.ini`.

Permissions: `liquid_assets.view`, `liquid_assets.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `ledger` >=0.1.0
- Soft: `funds` >=0.1.0

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
