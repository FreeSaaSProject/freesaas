# Ledger chart seeding (agent-led)

FreeSaaS does **not** ship a hardcoded chart of accounts or move-type tree. Businesses differ; agents (or a director) design the starter structure after `module enable ledger`.

Related ops: [ledger.md](ledger.md). Stickers bulk **Create DPS** lists only types with `ID_ACT_IN_ENABLED=1`. Finance reports (module `finance`) read the same move types — structure the chart for a sensible **P&L** and **cash-flow (CF)** statement, not as a flat dump of bank labels.

In code/UI the CF matrix is often named `ODDS` and the P&L matrix `OPIU` (`FinanceBalanceRepository`). Prefer saying **cash flow** / **P&L** in agent dialogue; use `ODDS`/`OPIU` only when pointing at keys or screens.

## Policy

| Do | Do not |
|----|--------|
| Interview before inventing a large tree | Bake a fixed national/brand chart into ledger PHP |
| Seed only when charts are empty (or additive with explicit OK) | Wipe / replace a live chart without ack |
| Align leaf types with a generic P&L outline + CF class | Create dozens of types with wrong `odds_type` / `EXCLUDE_PL` |
| Put UI chrome in `modules/ledger/lang/{ru,en}.php` via `t()` | Treat DB `NAME` as i18n keys — `NAME` is reference data |
| Use integration flags (`FOT_KIND`, `ID_ACT_IN_ENABLED`, …) | Hardcode type **names** in stickers / payments / banks |
| Keep assigned IDs in session notes | Assume type/account numeric IDs are stable across installs |

**Bilocale:** ask working language (or `app.locale`). Write `NAME`/`DESCR` once in that language. Do not dual-write two languages into one `NAME`. Later UI locale changes do not rewrite names (edit in `/accounts`, `/account-move-types`).

## How types feed finance reports

Module `finance` builds the cash-flow matrix and P&L from `base_account_moves` joined to `base_account_move_types`.

### Cash-flow class — `ID_TYPE` on the **type** (`odds_type` in forms; UI label via `ledger.types.odds.*`)

| Value | Meaning | Typical content |
|------:|---------|-----------------|
| `1` | Operating | Sales, consumables, rent, payroll, bank fees, stock receipts |
| `2` | Investing | Capex, asset sales, long-term deposits tied to investments |
| `3` | Financing | Loans, owner draws/dividends, capitalization of equity |

CF sections are: inflows/outflows **per class**, then net CF. Each enabled leaf type becomes a **line** under its class (sign from `DIRECTION`: `+1` income, `-1` expense).

### P&L — `EXCLUDE_PL` and class

Rough shape in `FinanceBalanceRepository` (`buildOpiu`):

1. **Operating revenue** — sum of income leaves with `EXCLUDE_PL=0` (mostly `odds_type=1`).
2. **Operating costs** — sum of expense leaves with `EXCLUDE_PL=0` (mostly `odds_type=1`).
3. EBITDAS / EBITDA / fund adjustments / EBIT (funds module may add rows).
4. **Investing** block (funds / special lines; keep investing DPS as `odds_type=2`).
5. **Financing** block — leaves with `odds_type=3` listed explicitly (dividends via `IS_DIV` when used).

`EXCLUDE_PL=1`: type still appears in the CF report (marked excluded from P&L) but **does not** enter operating revenue/costs totals. Use for cash movements that must stay on the cash-flow statement but must not distort operating profit. Prefer correct `odds_type=3` for true financing rather than “operating + exclude_pl” unless the operator insists.

**Design rule:** name and group leaves the way a generic P&L should read — Revenue, Cost of goods / materials, Payroll, Rent, Marketing, Admin, Taxes, Other opex — then assign `odds_type=1` and `EXCLUDE_PL=0`. Add investing/financing leaves only when needed, with classes `2` / `3`.

## When to run the interview

After ledger enable (or health / empty type list for stickers DPS):

1. `base_accounts` empty (or no enabled rows), **or**
2. `base_account_move_types` empty / no useful leaves, **or**
3. Health `ledger.fot_types` warns while `payments` is on or planned, **or**
4. Stickers **Create DPS** has no types while receipt acts need DPS, **or**
5. `finance` is enabled but P&L would be empty/nonsense (no operating leaves).

If a chart already exists — **stop**; only add types the operator requests.

## Interview (ask before insert)

1. **Locale for names** — language of `NAME` strings.
2. **Money accounts** — bank and/or cash; free-form names. Account `ID_TYPE`: `1` credit, `2` debit (usual operating = `2`).
3. **P&L breadth** — minimal (Variant A) vs small generic P&L lines (Variant B). Prefer A unless they list categories.
4. **Payroll** — module `payments`? → need `FOT_KIND=1` and `FOT_KIND=2` types (`ID_PAYMENT_ENABLED=1`) on bank/cash accounts.
5. **Stock receipts** — module `stickers` + DPS from acts? → at least one expense type with `ID_ACT_IN_ENABLED=1`, `DIRECTION=-1`, `ID_ACCOUNT>0`, usually `ID_SUPPLIER_ENABLED=1`, `odds_type=1`, `EXCLUDE_PL=0` (materials as operating cost).
6. **Financing / investing** — loans, dividends, capex now? If not, skip classes `2`/`3` leaves.
7. **Folders** — optional parent types (`PID`) for tree UI (Income / Expenses / Financing); parents often `ID_ACCOUNT=0`.

## Hard requirements (soft deps)

### `payments` / FOT

- Type `FOT_KIND=1`, `ID_PAYMENT_ENABLED=1`, `ID_ACCOUNT` = bank.
- Type `FOT_KIND=2`, `ID_PAYMENT_ENABLED=1`, `ID_ACCOUNT` = cash.
- Recommend `odds_type=1`, `EXCLUDE_PL=0`, `DIRECTION=-1` (operating payroll cost).
- Health: `ledger.fot_types`.

### `stickers` act DPS

- At least one enabled type: `ID_ACT_IN_ENABLED=1`, `DIRECTION=-1`, `ID_ACCOUNT>0`.
- Recommend `odds_type=1`, `EXCLUDE_PL=0` (stock purchase = operating cost in P&L).
- Bulk create skips acts that already have a move or zero act sum.

### `finance`

- Operating P&L needs income and/or expense leaves with `EXCLUDE_PL=0` and sensible `odds_type`.
- Do not put ordinary opex under `odds_type=3` or they land in the financing block of P&L.

### General

- Day-to-day types: `ENABLED>0`, default `ID_ACCOUNT` when posting expects one.
- Transfers between company accounts → transfer API (`/ledger/transfers`), not a fake expense type (linked moves are excluded from normal CF type lines).

## Variant A — Minimal starter

Enough for ledger + stickers DPS + payments FOT + a readable tiny P&L.

**Accounts**

| Role | Example NAME | Account ID_TYPE |
|------|--------------|----------------:|
| Bank | Bank | 2 |
| Cash | Cash | 2 |

**Folders (optional)**

| Role | Example NAME | PID |
|------|--------------|----:|
| Income | Income | 0 |
| Expenses | Expenses | 0 |

**Leaves** (all operating `odds_type=1`, `EXCLUDE_PL=0` unless noted)

| Role | DIRECTION | Extra flags | Account | Example NAME |
|------|----------:|-------------|---------|--------------|
| Operating revenue (optional but useful) | +1 | — | Bank | Sales |
| Stock / materials | -1 | `act_in`, `supplier` | Bank | Stock receipts |
| Payroll bank | -1 | `payment`, `FOT_KIND=1` | Bank | Payroll (bank) |
| Payroll cash | -1 | `payment`, `FOT_KIND=2` | Cash | Payroll (cash) |

If the operator’s locale is not English, translate these example `NAME` values when inserting — do not leave English labels on a non-English site without asking.

## Variant B — Generic P&L-shaped chart

Start from A, then add **operating** expense leaves the operator confirms (still `odds_type=1`, `EXCLUDE_PL=0`, `DIRECTION=-1`):

| P&L idea | Example NAME |
|----------|--------------|
| Cost of materials (if not only via stickers) | Materials |
| Rent | Rent |
| Marketing | Advertising |
| Admin / office | Office & admin |
| Bank fees | Bank fees |
| Taxes (operating) | Taxes |
| Other opex | Other expenses |

Optional income leaves (`DIRECTION=+1`, `odds_type=1`): Sales, Other income.

Add only when asked:

| Class | odds_type | Examples |
|-------|----------:|----------|
| Investing | 2 | Capex; Asset sale |
| Financing | 3 | Loans in/out; Dividends (`IS_DIV=1` on dividend expense if used) |

Keep financing/investing out of “operating costs” by class, not by scattering `EXCLUDE_PL` on ordinary opex.

## How to insert

Prefer product UI/JSON:

```text
GET/POST /accounts, /accounts/new
GET/POST /account-move-types, /account-move-types/new
```

Fields of interest on types: `odds_type` (1|2|3), `exclude_pl`, `direction`, `account_id`, `pid`, `num`, flags (`act_in_enabled`, `payment_enabled`, `fot_kind`, `supplier_enabled`, …).

On virgin DB with SQL access: insert accounts first, then parents, then leaves (`PID` after parents exist). No second table-prefix family.

Verify:

```bash
php bin/freesaas.php health --module=ledger --format=json
# If finance enabled, open balance / P&L and confirm operating lines look right
```

Stickers: `/stickers/receipts` → bulk **Create DPS** → type list non-empty.

## Anti-patterns

- Hardcoding specific type **names** into product modules.
- Schema migration that always inserts a fixed chart.
- Naming in the wrong language without asking.
- `act_in` / FOT types with `ID_ACCOUNT=0`.
- Ordinary opex as `odds_type=3` (pollutes financing P&L).
- Marking normal operating costs `EXCLUDE_PL=1` “to hide them” — they disappear from P&L for the wrong reason.
- Assuming stable numeric IDs across instances.

## Checklist

1. Detect empty/incomplete chart (counts, `ledger.fot_types`, stickers type list, finance usefulness).
2. Interview (locale, accounts, P&L breadth, payroll, stock, invest/fin).
3. Propose Variant A or B mapped to P&L / cash flow; get OK.
4. Insert accounts → folders → leaves with `odds_type` / `EXCLUDE_PL` / integration flags.
5. Health + spot-check finance P&L/CF + one stickers/payments DPS if those modules are on.
6. Tell humans where to rename/reorder (`/account-move-types`, `NUM` / folders).
