# Procedures

Product module `procedures`: Enable for spa procedure catalog and journal. Requires clients + catalog. Soft tables/bots. Required by calendar, rates, procents, bonuses, staff_reports. Core spa domain — enable before calendar/rates. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable procedures --accept-risk --format=json
```

Or `[modules] procedures=1` in `cfg/config.ini`.

Permissions: `procedures.view`, `procedures.edit`, `procedures.types`.

## Dependencies

- Requires: `core` >=0.3.0, `clients` >=0.1.0, `catalog` >=0.1.0
- Soft: `tables` >=0.1.0, `bots` >=0.1.0, `procents` >=0.1.0, `audio_pipeline` / `audio_reports` / `audio_dicts` (script activator `proc_created`)

## Tables

| Table | Role |
|-------|------|
| `base_procedure_types` | Owned by `procedures` |
| `base_procedures` | Owned by `procedures` |
| `base_procedure_restores` | Owned by `procedures` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/procedures` journal; `/procedure-types` dictionary.
- `/procedures/day-stats` day stats.
- Restore UI for spa packs lives in the optional spa instance pack (not this menu).

| Path | Permission |
|------|------------|
| `/procedures` | `—` |
| `/procedure-types` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (9 ops).

| Op | Method | Path |
|----|--------|------|
| `procedures.list` | GET | `/procedures` |
| `procedures.get_edit` | GET | `/procedures/{id}/edit` |
| `procedures.create` | POST | `/procedures/new` |
| `procedures.update` | POST | `/procedures/{id}/edit` |
| `procedures.types.list` | GET | `/procedure-types` |
| `procedures.types.get_edit` | GET | `/procedure-types/{id}/edit` |
| `procedures.types.create` | POST | `/procedure-types/new` |
| `procedures.types.update` | POST | `/procedure-types/{id}/edit` |
| `procedures.day_stats` | GET | `/procedures/day-stats` |

```bash
curl -sS -b jar.txt 'https://example/procedures?format=json'
curl -sS -b jar.txt 'https://example/procedure-types?format=json'
```

## Notes

- Schema includes `base_procedure_restores`; restore product UI is in the optional spa instance pack.
- Soft audio hook: `ProcedureCreatedCollect` listens to `audio_pipeline.collect.after_admin` and `audio_reports.day_quality.part_metric` for script parts with `COND_TYPE=proc_created`. Matches `base_procedures` by `ID_ADMIN` + `TYPE` (ids from part `IDS_DICTONARYS`) + `DTCREATE`. When the part is a trigger (`IS_REQ`), unmatched creates **seed** openings (CRM-as-opener); same-client creates within `TIMEOUT` glue into one episode. Sibling dictionary parts (e.g. upsell speech) attach from instances in the same window. No hard require on audio modules — disable this module and the activator becomes a skipped day-quality part.
