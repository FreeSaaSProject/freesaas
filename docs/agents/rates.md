# Specialist rates

Product module `rates`: commission % by specialist/procedure type — also the **skill SoT** (who may perform a type/product for booking / online / `/t`). Requires procedures. Required by procents. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable rates --accept-risk --format=json
```

Or `[modules] rates=1` in `cfg/config.ini`.

Permissions: `rates.view`, `rates.edit`, `rates.delete`.

## Dependencies

- Requires: `core` >=0.3.0, `procedures` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_procent_history` | Owned by `rates` — `content*` maps + `ban_booking` / `ban_online` / `ban_procs` |

## Skill model

- Type is **on** when `content[type_id] > 0` and not in `ban_booking` (online also checks `ban_online`).
- Product ban: `ban_procs[product_id]` blocks that product even when the type is enabled.
- **Zero %** (except SPEC/break type, default 38): saving a rates row auto-sets `ban_booking` + `ban_online` for that type.
- UI: `/rates/{id}/edit` — Skills section = % + type bans + per-product bans under enabled types.
- Live gate (`BookingOperatorGate`) reads rates only; legacy `RESTRICT_*` is ignored after migrator.
- Category `executors` no longer gates `/t`.

### Migrator

```bash
php bin/freesaas.php migrate run --module=rates
php bin/data-migrate.php --module=rates --id=restrict_to_bans
```

Creates a new midnight rates row per operator:

| Source | Target |
|--------|--------|
| `RESTRICT_TYPES` | `ban_booking` |
| `RESTRICT_TYPES_ONLINE` | `ban_online` |
| `RESTRICT_PROCS` | `ban_procs` (product ids) |
| `content[type]=0` (not SPEC) | `ban_booking` + `ban_online` |

Content maps are copied from the latest non-migrator row.

### Parity check (old vs new)

```bash
php bin/check-service-access-parity.php
php bin/check-service-access-parity.php --format=json
```

## Agent JSON

| Op | Method | Path |
|----|--------|------|
| `rates.list` | GET | `/rates` |
| `rates.get` | GET | `/rates/{id}/edit` |
| `rates.create` | POST | `/rates/new` |
| `rates.update` | POST | `/rates/{id}/edit` |
| `rates.skill.diagnose` | GET | `/rates/skill?operator_id=&type_id=` or `good_id=` |
| `rates.skill.grant` | POST | `/rates/skill` — body: `operator_id`, `type_id`\|`good_id`, optional `percent`, `dry_run=1` |

Grant never enables without `%`: uses explicit → existing → median of own other types → peer median → else `needs_percent`. Product bans (`ban_procs`) are preserved on grant.

```bash
curl -sS -b jar.txt 'https://example/rates/skill?format=json&operator_id=58&type_id=1'
curl -sS -b jar.txt -X POST 'https://example/rates/skill?format=json' \
  -d 'operator_id=58&type_id=1&dry_run=1'
```

## Notes

- Soft audio: `AUDIO_HOUR_RATE`, `SCRIPT_SCORE_RATE` when columns present.
- `LOSS_FIX` = fixed pay per shift (procents).
