# Sheets

Product module `sheets`: Enable for linen/sheet inventory specific to LikeSpa. Standalone warehouse-adjacent module. Turn on only if the site tracks sheets separately from stickers. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable sheets --accept-risk --format=json
```

Or `[modules] sheets=1` in `cfg/config.ini`.

Permissions: `sheets.view`, `sheets.edit`.

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_sheet_accounts` | Owned by `sheets` |
| `base_sheet_moves` | Owned by `sheets` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/sheets/accounts`, `/sheets/moves`, `/sheets/transfers` — linen accounts and moves.

| Path | Permission |
|------|------------|
| `/sheets/moves` | `—` |
| `/sheets/accounts` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (10 ops).

| Op | Method | Path |
|----|--------|------|
| `sheets.accounts.list` | GET | `/sheets/accounts` |
| `sheets.accounts.get_edit` | GET | `/sheets/accounts/{id}/edit` |
| `sheets.accounts.create` | POST | `/sheets/accounts/new` |
| `sheets.accounts.update` | POST | `/sheets/accounts/{id}/edit` |
| `sheets.moves.list` | GET | `/sheets/moves` |
| `sheets.moves.get_edit` | GET | `/sheets/moves/{id}/edit` |
| `sheets.moves.create` | POST | `/sheets/moves/new` |
| `sheets.moves.update` | POST | `/sheets/moves/{id}/edit` |
| `sheets.transfers.get` | GET | `/sheets/transfers/new` |
| `sheets.transfers.create` | POST | `/sheets/transfers/new` |

```bash
curl -sS -b jar.txt 'https://example/sheets/accounts?format=json'
curl -sS -b jar.txt 'https://example/sheets/moves?format=json'
```

## Notes

- Disableable LikeSpa linen pack.
