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
- `/workweeks/nazop` — who works / shift / M-MAX matrix (`EVT_ASSIGN`). Toolbar **Set shift** applies the selected lane to all checked cells (then Save).
- `/workweeks/nazcab` — multi-day matrix (~30 days): **tables/sections only on today’s row** (needs `tables`); **badges for the whole period** (soft `audio`). Hidden staff omitted. Calendar embeds via fragment dialog (`data-table-open`). Gate for ordinary admins: `tables.view` and/or `audio.view`|`audio.badges` — **not** `EVT_ASSIGN` (shift nazop stays senior). Route still needs `workweeks.view`.
- `/workweeks/settings` — shift windows (PRICE_COL lanes, extensible beyond I–V) + schedule log target + `log_table_changes` (superadmin).
- Role status flag `DEFAULT_PRICE_COL` — default lane when assigning that role on nazop (0 = day default).
- Embedded in calendar day when calendar on.

| Path | Permission |
|------|------------|
| `/workweeks` | `—` |
| `/workweeks/nazop` | `workweeks.view` (+ assign gate) |
| `/workweeks/nazcab` | `workweeks.view` (+ assign gate; tables on) |
| `/workweeks/settings` | `workweeks.view` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (8 ops).

| Op | Method | Path |
|----|--------|------|
| `workweeks.list` | GET | `/workweeks` |
| `workweeks.get` | GET | `/workweeks/{id}/edit` |
| `workweeks.create` | POST | `/workweeks/new` |
| `workweeks.update` | POST | `/workweeks/{id}/edit` |
| `workweeks.day` | GET | `/workweeks/day/{date}` |
| `workweeks.nazcab` | GET/POST | `/workweeks/nazcab` |
| `workweeks.settings.get` | GET | `/workweeks/settings` |
| `workweeks.settings.update` | POST | `/workweeks/settings` |

```bash
curl -sS -b jar.txt 'https://example/workweeks?format=json'
curl -sS -b jar.txt 'https://example/workweeks/settings?format=json'
```

## Notes

- Soft comments / cabinets / tables / audio (nazcab badges) / bots.
- `ID_TABLE` on shifts = massage section; bulk edit via nazcab (not nazop).
- Nazcab table changes log as «Tables change» (not shift update). Ini `log_table_changes`: `1`/`all` (default), `assign` (EVT_ASSIGN only), `off`.
- Lane times live in `cfg/modules/workweeks.ini` (`shift_ids` + `shift_N_{start,end,label}`). Booking clips public slots to **staff lane ∩ salon hours** (`BookingAvailability` `respect_staff_shift`, default on). Terminals may ignore lane ends via `ignore_staff_shift_windows` (see [terminals.md](terminals.md)). Breaks/chill may still end after shift/salon close.
