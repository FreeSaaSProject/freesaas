# Calendar

Product module `calendar`: Enable for the operational day board. Requires clients, catalog, procedures, orders, workweeks. Soft cabinets/tables. Soft-used by bonuses/clientics. Central ops screen — enable after workweeks + procedures. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable calendar --accept-risk --format=json
```

Or `[modules] calendar=1` in `cfg/config.ini`.

Permissions: `calendar.view`, `calendar.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `clients` >=0.1.0, `catalog` >=0.1.0, `procedures` >=0.1.0, `orders` >=0.1.0, `workweeks` >=0.1.0
- Soft: `cabinets` >=0.1.0, `tables` >=0.1.0, `booking` >=0.1.0, `bonuses` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_events` | Owned by `calendar` |
| `base_events_changes` | Owned by `calendar` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/calendar` week grid; `/calendar/day/{date}` day board.
- Shift strip: nazop (who works) and, when `tables` is on, nazcab (massage sections) via `/workweeks/nazcab` dialog; table names shown on shift chips.
- `/calendar/events/new` and `/calendar/events/{id}/edit` — event CRUD; can create procedures.
- `/calendar/settings` — manual create / hours (superadmin).
- Booking advance slots are `/booking/new` (separate module), not slot-click on the day board.

| Path | Permission |
|------|------------|
| `/calendar` | `—` |
| `/calendar/settings` | `calendar.view` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (8 ops).

| Op | Method | Path |
|----|--------|------|
| `calendar.week` | GET | `/calendar` |
| `calendar.week.date` | GET | `/calendar/week/{date}` |
| `calendar.day` | GET | `/calendar/day/{date}` |
| `calendar.events.get` | GET | `/calendar/events/{id}/edit` |
| `calendar.events.create` | POST | `/calendar/events/new` |
| `calendar.events.update` | POST | `/calendar/events/{id}/edit` |
| `calendar.settings.get` | GET | `/calendar/settings` |
| `calendar.settings.update` | POST | `/calendar/settings` |

```bash
curl -sS -b jar.txt 'https://example/calendar?format=json'
curl -sS -b jar.txt 'https://example/calendar/settings?format=json'
```

## Notes

- Requires workweeks, clients, catalog, procedures, orders.
- Soft bonuses / cabinets / tables / booking for overlays.
