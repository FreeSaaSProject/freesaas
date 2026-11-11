# Funds

Product module `funds`: tree of internal funds (groups → funds) and fund movements (CAPUSE / REFUND / REVAL / DEVAL / DESTROY), linked to ledger account moves (ДПС). Hard-requires `ledger`. Soft-used by finance reports and liquid assets for FUND_MATRIX / REVAL. Catalog maturity: `release`.

## Enable

```bash
php bin/freesaas.php module enable funds --format=json
```

Or `[modules] funds=1` in `cfg/config.ini`.

Permissions: `funds.view` (lists, fund card), `funds.edit` (create/edit, accrual tools, create from DPS), `funds.delete`.

## Model

- **Group** = fund with children (usually `PID=0`). Movements are never posted to a group; the group card shows children and their total.
- **Fund** (leaf) holds movements. A root fund without children is also a leaf.
- **Movement** types: `1` CAPUSE (capital use, needs linked DPS covering the full amount), `2` REFUND (incl. amortization/USN accruals), `3` REVAL, `4` DEVAL, `5` DESTROY.
- **Link to ДПС**: `base_account_moves.ID_FUND_MOVEMENT` (ledger column). Several DPS may point to one movement; the movement is «covered» when the DPS sum equals its amount. CAPUSE with a different sum is flagged `mismatch`.

## Which DPS can be linked

A move type links to funds (`fund_link=1` in type meta) when:

- its flag `ID_FUND_ENABLED` (`fund_enabled`) is on, **or**
- its cash-flow class is Investments (`odds_type=2`).

Only then the DPS form shows the «Fund movement» select and the «Create fund movement from this DPS» button. For a type that should link but has another class, set the flag: `POST /account-move-types/{id}/edit?format=json` with `fund_enabled=1` (partial update, see [ledger.md](ledger.md)).

## Tables

| Table | Role |
|-------|------|
| `base_funds` | Owned by `funds` |
| `base_fund_movements` | Owned by `funds` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

| Path | Permission | Role |
|------|------------|------|
| `/funds` | `funds.view` | Tree: groups with children, balances; click a name → card |
| `/funds/{id}` | `funds.view` | Card: fund movements with running total, sources, linked DPS, mismatch; group → children |
| `/funds/new` · `/funds/{id}/edit` | `funds.edit` | Fund form (`?pid=` preselects group) |
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

## Verify

`php bin/check-funds-agent.php` (also part of `php bin/smoke.php`): runs the ops above in-process on throwaway fixtures, checks partial type update, ledger without funds listeners, and that `modules/ledger` has no funds classes/tables.
