# Funds

Product module `funds`: tree of internal funds (groups → funds), fund movements (CAPUSE / REFUND / REVAL / DEVAL / DESTROY), linked to ledger account moves (DPS), and **accumulating funding**: accrual rules, DPS-type auto expenses, transfers, statement, overdraft highlighting. Hard-requires `ledger`. Soft-used by finance reports and liquid assets for FUND_MATRIX / REVAL. Catalog maturity: `release`.

## Enable

```bash
php bin/freesaas.php module enable funds --format=json
```

Or `[modules] funds=1` in `cfg/config.ini`.

Permissions: `funds.view` (lists, fund card), `funds.edit` (create/edit, accrual tools, create from DPS), `funds.delete`.

## Model

- **Group** = fund with children (usually `PID=0`). Movements are never posted to a group; the group card shows children and their total.
- **Fund** (leaf) holds movements. A root fund without children is also a leaf.
- **Movement** types: `1` CAPUSE (capital use, needs linked DPS covering the full amount), `2` REFUND (incl. amortization/USN accruals), `3` REVAL, `4` DEVAL, `5` DESTROY, `6` ACCRUE (+, rule accrual), `7` TRANSFER_OUT (−), `8` TRANSFER_IN (+). Types 6–8 are internal: they never count as spend for finance and never appear in the DPS-linking selects.
- **Link to DPS**: `base_account_moves.ID_FUND_MOVEMENT` (ledger column). Several DPS may point to one movement; the movement is «covered» when the DPS sum equals its amount. CAPUSE with a different sum is flagged `mismatch`.

## Which DPS can be linked

A move type links to funds (`fund_link=1` in type meta) when:

- its flag `ID_FUND_ENABLED` (`fund_enabled`) is on, **or**
- its cash-flow class is Investments (`odds_type=2`).

Only then the DPS form shows the «Fund movement» select and the «Create fund movement from this DPS» button. For a type that should link but has another class, set the flag: `POST /account-move-types/{id}/edit?format=json` with `fund_enabled=1` (partial update, see [ledger.md](ledger.md)).

## Tables

| Table | Role |
|-------|------|
| `base_funds` | Owned by `funds` |
| `base_fund_movements` | Owned by `funds`; extra columns `RULE_ID`, `PERIOD_KEY`, `SRC` (0 manual, 1 rule, 2 DPS-auto, 3 transfer) |
| `base_fund_rules` | Accrual rules (percent/fixed, week/month, base mode) |
| `base_fund_type_map` | Ledger move type → fund (expense auto-posting) |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Accumulating funding (debit funds)

- **Rules** (`base_fund_rules`): per debit leaf fund, `kind=percent|fixed`, `period=week|month`, `base_mode=all_income|types`. Base = ledger DPS income (`DIRECTION>0`, not deleted, not linked-transfer) in the period; `all_income` = operating types (`ID_TYPE=1`, `EXCLUDE_PL=0`), `types` = chosen type ids (children included). Rules ship **disabled** unless `enabled=1`.
- **Accrual** is idempotent by `(RULE_ID, PERIOD_KEY)` (`2026-W47` ISO week / `2026-11` month): a re-run updates the amount, deletes it when the base fell to zero, and never duplicates. Default window: last 40 days, finished periods only (`include_open=1` for the running one). Cron `funds.accrue` daily 03:30; manual: `/funds/rules` or `POST /agent/funds/accrue`.
- **Expenses**: type → fund map (`/funds/type-map`). An expense DPS of a mapped type auto-creates a CAPUSE (`SRC=2`, `SUMM_OUT_FUND=summ`) via `ledger.moves.saved`; a manual fund link on the DPS wins; changing/deleting the DPS keeps the movement in sync. DPS saved before the map existed are posted by `POST /agent/funds/backfill` (`dry_run`, `from`, `to`, at most 5000 per call; already linked DPS are skipped).
- **Transfers**: paired TRANSFER_OUT/IN between debit leaves, no DPS, no effect on cash.
- **Overdraft**: a debit fund balance below zero is highlighted (list, card, statement), raises a notice, and `/funds/transfer?to={id}` pre-fills the cover amount.
- **Statement** (`/funds/statement`): per tree and week/month — opening, accrued, transfers in, other inflow, spent, transfers out, other outflow, closing.

Modularity: `funds` and `ledger` know nothing about product packs. A pack (e.g. a private spa instance module) can depend on `funds` and call `FundsStatement::build()` / `FundAccrualRunner::run()` / the repositories. `ledger` only emits `ledger.moves.saved` (`{id, is_new, source}`); no listeners is safe.

## Human UI

| Path | Permission | Role |
|------|------------|------|
| `/funds` | `funds.view` | Tree: groups with children, balances; click a name → card |
| `/funds/{id}` | `funds.view` | Card: fund movements with running total, sources, linked DPS, mismatch; group → children |
| `/funds/new` · `/funds/{id}/edit` | `funds.edit` | Fund form (`?pid=` preselects group) |
| `/funds/statement` | `funds.view` | Statement table (week/month, drill-down by fund) |
| `/funds/rules` · `/funds/rules/new` · `/funds/rules/{id}/edit` | `funds.edit` | Accrual rules; run/preview, enable/disable |
| `/funds/type-map` | `funds.edit` | Ledger type → fund map |
| `/funds/transfer` | `funds.edit` | Transfer between funds (`?to=` covers an overdraft) |
| `/fund-movements` | `funds.view` | Movements list; fund filter shows leaves grouped by parent |
| `/fund-movements/new` · `/{id}/edit` | `funds.edit` | Movement form (`?fund_id=` preselect, `?dps=` prefill from a DPS) |

On a DPS (`/ledger/moves/{id}/edit`) funds adds the select, a «Fund» section (link state, sums, create button) and a fund badge in the moves list — all via ledger hooks; ledger itself has no funds code.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

| Op | Method | Path |
|----|--------|------|
| `funds.list` | GET | `/funds` → `items`, `tree`, `leaf_options` |
| `funds.get` | GET | `/funds/{id}` → `fund`, `parent`, `children`, `movements[]` (with `dps[]`, `dps_sum`, `mismatch`, `running`), `totals` |
| `funds.accrual` | POST | `/funds` (`funds_action=calculate\|reprice`) |
| `funds.get_edit` | GET | `/funds/{id}/edit` |
| `funds.create` | POST | `/funds/new` |
| `funds.update` | POST | `/funds/{id}/edit` |
| `funds.movements.list` | GET | `/fund-movements` (`fund_id` of a group includes its children) |
| `funds.movements.get_edit` | GET | `/fund-movements/{id}/edit` |
| `funds.movements.create` | POST | `/fund-movements/new` (leaf `fund_id` only) |
| `funds.movements.from_dps` | GET | `/fund-movements/new?dps={id}` prefill |
| `funds.movements.update` | POST | `/fund-movements/{id}/edit` |
| `funds.movements.delete` / `funds.delete` | POST | `…/edit` with `action=delete` |
| `funds.statement` | GET | `/funds/statement?gran=week\|month&date=…&fund_id&hide_empty` → `period`, `rows[]`, `totals`, `negative[]` |
| `funds.rules.list` | GET | `/funds/rules` → `items[]` |
| `funds.rules.get_edit` / `create` / `update` | GET / POST | `/funds/rules/{id}/edit`, `/funds/rules/new` |
| `funds.rules.toggle` | POST | `/funds/rules` (`rules_action=toggle`, `id`, `enabled`) |
| `funds.rules.run` | POST | `/funds/rules` (`rules_action=run`, `dry_run`, `week`/`month`/`from`+`to`, `include_open`) |
| `funds.rules.delete` | POST | `/funds/rules` (`rules_action=delete`, `id`) |
| `funds.type_map.list` / `save` | GET / POST | `/funds/type-map` (`map_action=save\|delete`) |
| `funds.transfer.get` / `create` | GET / POST | `/funds/transfer` (`from_fund_id`, `to_fund_id`, `summ`, `note`, `date`) |

On a DPS, `GET /ledger/moves/{id}/edit?format=json` returns `extensions.funds`:

```json
{"fund_link": true, "fund_movement_id": 12, "create_url": "/fund-movements/new?dps=345",
 "linked": {"fund_movement_id": 12, "name": "…", "type_id": 1, "summ": 1000, "fund_id": 7,
            "fund_name": "…", "dps_sum": 1000, "dps_ids": [345], "mismatch": false, "missing": false}}
```

### Scenario: DPS → fund movement

1. `GET /ledger/moves/{dps}/edit?format=json` — check `extensions.funds.fund_link` and that `linked` is `null`.
2. `GET /funds?format=json` — pick a leaf from `leaf_options`.
3. `GET /fund-movements/new?format=json&dps={dps}` — prefill (CAPUSE + `summ_out_cap` for expenses, REFUND for income).
4. `POST /fund-movements/new?format=json` with `dps={dps}&fund_id={leaf}&type_id=1&summ=…&summ_out_cap=…` → `{id, dps_id}`; the DPS now has `fund_movement_id`.

To link an existing movement instead: `POST /ledger/moves/{dps}/edit?format=json` with the full move fields plus `fund_movement_id` (unknown ids → 400).

Errors (400): group `fund_id`; DPS type without `fund_link`; DPS already linked; unknown `fund_movement_id`.

```bash
curl -sS -b jar.txt 'https://example/funds/7?format=json'
curl -sS -b jar.txt 'https://example/fund-movements/new?format=json&dps=345'
```

## Token API (Surface A)

`X-FreeSaaS-Agent-Token` (see [agent-surfaces.md](agent-surfaces.md)) — same handlers as the session pages, for hosts without a session:

| Op | Method | Path |
|----|--------|------|
| `funds.agent.status` | GET | `/agent/funds/status` — counters, enabled rules, overdrawn funds |
| `funds.agent.tree` | GET | `/agent/funds/tree` |
| `funds.agent.rules` / `.rules.save` | GET / POST | `/agent/funds/rules` (`id` to update) |
| `funds.agent.rules.toggle` / `.rules.delete` | POST | `/agent/funds/rules/toggle` · `/rules/delete` |
| `funds.agent.accrue` | POST | `/agent/funds/accrue` (`dry_run`, `week`/`month`/`from`+`to`, `rule_id`, `include_open`) |
| `funds.agent.type_map` / `.save` / `.delete` | GET / POST | `/agent/funds/type-map` · `/type-map/delete` |
| `funds.agent.statement` | GET | `/agent/funds/statement` |
| `funds.agent.transfer` / `.create` | GET / POST | `/agent/funds/transfer` |
| `funds.agent.movements` | GET | `/agent/funds/movements` |

Rule JSON: `{fund_id, name, kind: "percent"|"fixed", percent, fixed_sum, period: "week"|"month", base_mode: "all_income"|"types", base_types: [int], dt_from, dt_to, enabled: bool, note}`.

## Verify

`php bin/check-funds-agent.php` (also part of `php bin/smoke.php`): runs the ops above in-process on throwaway fixtures, checks partial type update, ledger without funds listeners, and that `modules/ledger` has no funds classes/tables.
