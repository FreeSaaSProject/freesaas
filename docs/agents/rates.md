# Specialist rates

Product module `rates`: Enable to maintain commission % by specialist/procedure. Requires procedures. Required by procents. Set rates before expecting accrual reports. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable rates --accept-risk --format=json
```

Or `[modules] rates=1` in `cfg/config.ini`.

Permissions: `rates.view`, `rates.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `procedures` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_procent_history` | Owned by `rates` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/rates` — specialist commission rate history (`base_procent_history`).

| Path | Permission |
|------|------------|
| `/rates` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `rates.list` | GET | `/rates` |
| `rates.get` | GET | `/rates/{id}/edit` |
| `rates.create` | POST | `/rates/new` |
| `rates.update` | POST | `/rates/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/rates?format=json'
```

## Notes

- Requires `procedures`.
- Soft audio (when `audio` enabled / `base_records` present): `AUDIO_HOUR_RATE` (rub/hour) and `SCRIPT_SCORE_RATE` (rub per collection `SCORE` point) on `base_procent_history`. Consumed by `procents`.
- `LOSS_FIX` UI label = fixed pay per shift; accrued in `procents` as shifts × rate (`PRICE_COL=3` / I+II = 2).
