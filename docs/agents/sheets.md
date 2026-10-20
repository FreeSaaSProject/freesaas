# Sheets

Product module `sheets`: legacy linen ledger (`base_sheet_*`). Prefer [`laundry`](laundry.md) for new installs. Turn on only if the site still uses sheets separately from stickers. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable sheets --accept-risk --format=json
```

Or `[modules] sheets=1` in `cfg/config.ini`.

Permissions: `sheets.view`, `sheets.transfer`, `sheets.accounts`, `sheets.edit`, `sheets.delete`.

Optional `cfg/modules/sheets.ini` (see `modules/sheets/config.example.ini`):

| Key | Default | Role |
|-----|---------|------|
| `ticket_news_id` | `78` | Laundry ticket whose comments are the human log |
| `terms_account_id` | `1` | Terms / floor account |
| `laundry_account_id` | `2` | Laundry account |

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_sheet_accounts` | Owned by `sheets` |
| `base_sheet_moves` | Owned by `sheets` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

Balances are live `SUM` of signed non-deleted moves (no cached column). Transfers are linked debit/credit pairs (`ID_MOVEMENT_LINKED`). System-wide stock = sum of all account balances (purchases − writeoffs); transfers do not change the total.

## Human UI

- `/sheets/moves` — journal + balance strip for enabled accounts
- `/sheets/accounts` — accounts with balances
- `/sheets/transfers/new` — paired transfer (source balance shown)
- `/sheets/reconcile` — compare ticket `#78` comments («Сдала»/«Приняла») to transfers; apply missing returns (laundry→terms) or qty fixes on pairs. New returns stop when laundry balance is insufficient.

| Path | Permission |
|------|------------|
| `/sheets/moves` | `sheets.view` |
| `/sheets/accounts` | `sheets.accounts` |
| `/sheets/transfers/new` | `sheets.transfer` |
| `/sheets/reconcile` | `sheets.edit` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

| Op | Method | Path |
|----|--------|------|
| `sheets.accounts.list` | GET | `/sheets/accounts` |
| `sheets.moves.list` | GET | `/sheets/moves` |
| `sheets.transfers.create` | POST | `/sheets/transfers/new` |
| `sheets.reconcile.get` | GET | `/sheets/reconcile` |
| `sheets.reconcile.apply` | POST | `/sheets/reconcile` |

```bash
curl -sS -b jar.txt 'https://example/sheets/moves?format=json'
curl -sS -b jar.txt 'https://example/sheets/reconcile?format=json'
```

## Notes

- Disableable legacy linen pack; prefer `laundry`.
- Ticket comments are not FK-linked to moves; reconcile matches by date (±3d) and quantity.
- Editing a single leg of a linked transfer is blocked; use reconcile qty fix or `updateTransferPair`.
