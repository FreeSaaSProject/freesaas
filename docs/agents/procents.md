# Commission accruals

Product module `procents`: Enable for commission accrual reporting. Requires rates + procedures + workweeks. No edit UI — reporting only. Use after rates are filled for the period. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable procents --accept-risk --format=json
```

Or `[modules] procents=1` in `cfg/config.ini`.

Permissions: `procents.view`.

## Dependencies

- Requires: `core` >=0.3.0, `rates` >=0.1.0, `procedures` >=0.1.0, `workweeks` >=0.1.0

## Human UI

- `/procents` — read-only commission accrual report. Default window: **current + previous month** (`limit=2`). Older months: year/month filters, or **Load older months** (`limit+=2`).
- Superadmin (`SUPERADMIN_ACCESS`) only: under each month, **Day audit** button loads the day tree (`/procents/days`); open a day for line-level accruals (`/procents/day`). Other roles keep the month aggregates only.

| Path | Permission |
|------|------------|
| `/procents` | `procents.view` |
| `/procents/days` | `procents.view` + `SUPERADMIN_ACCESS` |
| `/procents/day` | `procents.view` + `SUPERADMIN_ACCESS` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (3 ops).

| Op | Method | Path |
|----|--------|------|
| `procents.report` | GET | `/procents` |
| `procents.days_audit` | GET | `/procents/days` |
| `procents.day_audit` | GET | `/procents/day` |

```bash
curl -sS -b jar.txt 'https://example/procents?operator_id=150&format=json'
curl -sS -b jar.txt 'https://example/procents?operator_id=150&limit=6&format=json'
curl -sS -b jar.txt 'https://example/procents?operator_id=150&year=2025&month=3&format=json'
curl -sS -b jar.txt 'https://example/procents/days?operator_id=150&ym=2026-03&format=json'
curl -sS -b jar.txt 'https://example/procents/day?operator_id=150&day=2026-03-15&format=json'
```

## Notes

- Requires `rates` + `procedures` + `workweeks`.
- Soft audio (when `base_records` exists): accrues accounted OffRecord hours × `AUDIO_HOUR_RATE` and `SUM(collections.SCORE)` × `SCRIPT_SCORE_RATE`. Day calendar chips: `А` / `С`.
- Shift fix: `LOSS_FIX` × shift units from `base_workweeks` (composite I+II / covering window = 2 via workweeks; not hardcoded slot 3). Day chip `Ф`.
- Soft `payments`: when `payments.auto_procent_accrual=1`, opening `/procents` or `/payments` for one staff upserts current-month total into `base_payments` as `% {MONTH_RU} {YYYY}` (ФОТ). Past months not rewritten. Toggle on `/payments/settings`. Director must pick staff first.
- Day audit lines use the same filters as the monthly report (no `ENABLED=1` on procedures); procedure/terminal rows are per journal line; shifts/sales/audio/scripts stay as day aggregates.
