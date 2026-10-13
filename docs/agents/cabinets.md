# Rooms

Product module `cabinets`: Enable when warehouses or calendar need room assignment. Soft-used by stocks, calendar, workweeks. Without it, some ID_CABINET fields fall back to defaults. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable cabinets --accept-risk --format=json
```

Or `[modules] cabinets=1` in `cfg/config.ini`.

Permissions: `cabinets.view`, `cabinets.edit`.

## Dependencies

- Requires: `core` >=0.3.0

## Tables

| Table | Role |
|-------|------|
| `base_cabinets` | Owned by `cabinets` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/cabinets` — rooms used by stocks (`ID_CABINET`) and staff skin.

| Path | Permission |
|------|------------|
| `/cabinets` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `cabinets.list` | GET | `/cabinets` |
| `cabinets.get` | GET | `/cabinets/{id}/edit` |
| `cabinets.create` | POST | `/cabinets/new` |
| `cabinets.update` | POST | `/cabinets/{id}/edit` |

```bash
curl -sS -b jar.txt 'https://example/cabinets?format=json'
```

## Notes

- Shared dictionary; other modules reference cabinet ids.
