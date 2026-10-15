# Account ledger (ДПС)

Product module `ledger`: company accounts, move types, account moves, bank reconciliations, promised payments, optional auto-DPS from `payments`.

## Enable

```bash
php bin/freesaas.php module enable ledger --format=json
# optional soft deps:
# php bin/freesaas.php module enable payments stickers suppliers funds --format=json
```

Mark payroll move types on `/account-move-types` via **FOT role** (`FOT_KIND`: bank=1 / cash=2). Names are free-form (any locale).

**No built-in chart seed.** After enable, if accounts/types are empty (or FOT/act-in missing for soft deps), the agent must interview the operator and create a structure — see **[ledger-chart.md](ledger-chart.md)** (variants, flags, anti-patterns). Do not hardcode brand-specific type names into modules.

Stickers receipt list: bulk **Create DPS…** picks a move type with `ID_ACT_IN_ENABLED` (needs `ledger.edit`); there are no fixed “consumables/tea/…” actions.

## Human UI

| Path | Role |
|------|------|
| `/accounts` | Company accounts |
| `/account-move-types` | Move types + field flags |
| `/ledger/moves` | Moves list, filters, recon when account selected |
| `/ledger/moves/new` · `/{id}/edit` | Create/edit (type-gated fields, promised dates) |
| `/ledger/moves/attach-act` | Attach stickers receipt act |
| `/ledger/transfers/new` · `/{id}/edit` | Paired transfer (both legs); linked moves redirect here from `/ledger/moves/{id}/edit` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. Ops: `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/ledger/moves?format=json&account_id=20&promise=3'
curl -sS -b jar.txt 'https://example/ledger/moves/123/edit?format=json'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves/new?format=json' \
  -d 'type_id=4&account_id=20&direction=-1&summ=1%20203%20323,23&dt=2026-10-31T12:00&descr=test'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves/123/edit?format=json' \
  -d 'action=copy'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves/123/edit?format=json' \
  -d 'action=delete'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves?format=json' \
  -d 'recon_action=save&recon_account_id=20&bank_balance=1000'
curl -sS -b jar.txt 'https://example/ledger/moves/attach-act?format=json&id=123'
curl -sS -b jar.txt -X POST 'https://example/ledger/moves/attach-act?format=json' \
  -d 'ids=123&act2_in=456'
```

### Transfers

Paired moves (`ID_MOVEMENT_LINKED`) open only via `/ledger/transfers/{id}/edit` (either leg). Opening `/ledger/moves/{id}/edit` redirects there.

### Copy / delete

| Op | Form |
|----|------|
| `ledger.moves.copy` | `POST /ledger/moves/{id}/edit` · `action=copy` → new move (dt=now, `(copy)` in descr), JSON `{id, source_id, row}` |
| `ledger.moves.delete` | `POST /ledger/moves/{id}/edit` · `action=delete` soft-delete |

UI bulk **Copy and open** is the same copy for a single selected row (`max_selection=1`).

Sums (`summ`, recon `bank_balance`) accept clipboard forms like `1 203 323,23`.

### Filters on `/ledger/moves`

`q`, `account_id`, `type_id`, `year`, `month`, `payment_id`, `supplier_id`, `promise` (`1` open / `2` done / `3` overdue).

With `account_id`, JSON includes `balance`, `recon_today`, `recs_by_move_id`, `orphan_recs`.

### Reconciliations (`ledger.reconcile`)

Advisory bank-balance markers only — they do **not** lock create/edit/delete of moves.

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
| `payments` | Payment ID + multi-allocation (`base_payment_move_links`) via `ledger.moves.edit.sections`; links cleared on `ledger.moves.deleted`; auto-DPS (`ledger.from_payment`) still available |
| `suppliers` | Counterparty field |
| `stickers` | Act-in attach |
| `funds` | Fund movement id |

## Health

`ledger.fot_types` — warns if no enabled move type has `FOT_KIND=1` (bank) and/or `FOT_KIND=2` (cash).
