# Settlements

Product module `payments`: Enable for paying specialists and settlement docs. Soft-used by ledger (auto-DPS) and the spa instance pack. Use with contracts for pay CTAs. Payroll side of staff pay. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable payments --accept-risk --format=json
```

Or `[modules] payments=1` in `cfg/config.ini`.

Permissions: `payments.view`, `payments.edit`, `payments.types`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `ledger`, `bots`, `procents`, `procedures` (day pay calc), `contracts`

## Tables

| Table | Role |
|-------|------|
| `base_payments` | Owned by `payments` |
| `base_payment_types` | Owned by `payments` |
| `base_payment_move_links` | Owned by `payments` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP). Alters `base_admin_statuses.PAY_DAILY` (not owned).

## Human UI

- `/payments` — staff settlements.
- `/payments/settings` — payroll settings (superadmin): auto commission accrual, daily payroll (`day_pay_*` in `payments.ini`), soft contracts `deadline_penalty`.
- `/payment-types` — payment type dictionary.
- Role edit: flag **Daily payroll** (`PAY_DAILY`) on `base_admin_statuses`.
- Contract pay CTA when contracts enabled; auto-DPS when ledger on.

| Path | Permission |
|------|------------|
| `/payments` | `payments.view` |
| `/payments/settings` | `SUPERADMIN_ACCESS` |
| `/payment-types` | `payments.types` |

## Daily payroll (`DayPayroll`)

For staff whose role has `PAY_DAILY=1`:

1. Opening `/payments` (one staff, current month) or the staff bot runs `DayPayroll::syncOperator` — upserts day lines `Начисление ЗП ФЛ {j.m.Y}` from procents day totals (floor + `day_pay_min_pay`).
2. Monthly `% МЕСЯЦ YYYY` sync is **skipped** for these staff.
3. Cash request on `/payments` (and optionally bot `shift_salary`): pick issuer shift day (≥ today+2) + issuer; PIN shown in UI; confirm on the same page (issuer role) or bot `day_payroll_issuer`.
4. Settings: `day_pay_min_pay`, `day_pay_payout_account_id`, `day_pay_issuer_status2`.

Machine DESCR prefixes keep legacy history markers (`Начисление ЗП ФЛ`, `Выдача ФЛ на`).

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/payments?format=json'
curl -sS -b jar.txt 'https://example/payments/settings?format=json'
```

## Notes

- Links to ledger moves via `/payments/move-links/{moveId}`.
- Soft from `procents`: when `auto_procent_accrual=1` and one monthly staff is selected, upserts `% {MONTH_RU} {YYYY}` (ФОТ). Toggle on `/payments/settings`.
- Soft from `contracts`: `deadline_penalty` edited here (writes `cfg/modules/contracts.ini`). Legacy `/contracts/settings` redirects here.
