# Shifts

Product module `workweeks`: Enable for staff day shifts. Soft comments/cabinets/tables. Required by calendar, audio, audio_reports, procents. Foundation for schedule and commission day scope — enable before calendar/audio. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable workweeks --accept-risk --format=json
```

Or `[modules] workweeks=1` in `cfg/config.ini`.

Permissions: `workweeks.view`, `workweeks.edit`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `comments` >=0.1.0, `cabinets` >=0.1.0, `tables` >=0.1.0, `bots` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_workweeks` | Owned by `workweeks` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/workweeks` — day shifts; `/workweeks/day/{date}` day embed.
- `/workweeks/settings` — shift windows PRICE_COL 1–5 (superadmin).
- Embedded in calendar day when calendar on.

| Path | Permission |
|------|------------|
| `/workweeks` | `—` |
| `/workweeks/settings` | `workweeks.view` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (7 ops).

| Op | Method | Path |
|----|--------|------|
| `workweeks.list` | GET | `/workweeks` |
| `workweeks.get` | GET | `/workweeks/{id}/edit` |
| `workweeks.create` | POST | `/workweeks/new` |
| `workweeks.update` | POST | `/workweeks/{id}/edit` |
| `workweeks.day` | GET | `/workweeks/day/{date}` |
| `workweeks.settings.get` | GET | `/workweeks/settings` |
| `workweeks.settings.update` | POST | `/workweeks/settings` |

```bash
curl -sS -b jar.txt 'https://example/workweeks?format=json'
curl -sS -b jar.txt 'https://example/workweeks/settings?format=json'
```

## Notes

- Soft comments / cabinets / tables / bots.
