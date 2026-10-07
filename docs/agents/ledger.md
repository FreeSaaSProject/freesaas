# Account ledger (ДПС)

Product module `ledger`: company accounts, move types, account moves, bank reconciliations, promised payments, optional auto-DPS from `payments`.

## Enable

```bash
php bin/freesaas.php module enable ledger --format=json
# optional soft deps:
# php bin/freesaas.php module enable payments stickers suppliers funds --format=json
```

Copy `modules/ledger/config.example.ini` → `cfg/modules/ledger.ini` if FOT type names differ from defaults.

## Human UI

| Path | Role |
|------|------|
| `/accounts` | Company accounts |
| `/account-move-types` | Move types + field flags |
| `/ledger/moves` | Moves list, filters, recon when account selected |
| `/ledger/moves/new` · `/{id}/edit` | Create/edit (type-gated fields, promised dates) |
| `/ledger/moves/attach-act` | Attach stickers receipt act |
| `/ledger/transfers/new` | Paired transfer |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. Ops: `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/ledger/moves?format=json&account_id=20&promise=3'
curl -sS -b jar.txt 'https://example/ledger/moves/123/edit?format=json'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves/new?format=json' \
  -d 'type_id=4&account_id=20&direction=-1&summ=100&dt=2026-10-31T12:00&descr=test'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves?format=json' \
  -d 'recon_action=save&recon_account_id=20&bank_balance=1000'
curl -sS -b jar.txt 'https://example/ledger/moves/attach-act?format=json&id=123'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves/attach-act?format=json' \
  -d 'ids=123&act2_in=456'
```

### Filters on `/ledger/moves`

`q`, `account_id`, `type_id`, `year`, `month`, `payment_id`, `supplier_id`, `promise` (`1` open / `2` done / `3` overdue).

With `account_id`, JSON includes `balance`, `recon_today`, `recs_by_move_id`, `orphan_recs`.

### Reconciliations (`ledger.reconcile`)

POST `/ledger/moves` with `recon_action`:

| Action | Fields |
|--------|--------|
| `save` | `recon_account_id`, `bank_balance` |
| `update` | `recon_id`, `recon_account_id`, `bank_balance` |
| `delete` | `recon_id`, `recon_account_id` (today only) |
| `disable` | `recon_id`, `reason` (past only) |

### Promised payments

- `dt` = posted/accounting date (balance & reports)
- `dt_promised` = physical execution day (empty = off)
- `promise_executed` only when promise date ≤ now

## Soft deps

| Module | Effect |
|--------|--------|
| `payments` | Payment ID links + auto-DPS (`ledger.from_payment`) |
| `suppliers` | Counterparty field |
| `stickers` | Act-in attach |
| `funds` | Fund movement id |

## Health

`ledger.fot_types` — warns if FOT type name keys empty in `cfg/modules/ledger.ini`.
