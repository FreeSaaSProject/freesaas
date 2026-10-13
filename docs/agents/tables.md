# Massage tables

Product module `tables`: Enable for table selection on procedures/calendar. Soft-used by procedures, calendar, workweeks. Optional — spa can run with default table id 1. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable tables --accept-risk --format=json
```

Or `[modules] tables=1` in `cfg/config.ini`.

Permissions: `tables.view`, `tables.edit`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `procedures` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_tables` | Owned by `tables` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/tables` — massage tables; optional proc types / gender.
- Without this module, `ID_TABLE` defaults to `1`.

| Path | Permission |
|------|------------|
| `/tables` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `tables.list` | GET | `/tables` |
| `tables.get` | GET | `/tables/{id}/edit` |
| `tables.create` | POST | `/tables/new` |
| `tables.update` | POST | `/tables/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/tables?format=json'
```

## Notes

- Soft procedures for type dictionary on edit.
