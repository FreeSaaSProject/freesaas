# Calendar

Product module `calendar`: Enable for the operational day board. Requires clients, catalog, procedures, orders, workweeks. Soft cabinets/tables. Soft-used by bonuses/clientics. Central ops screen — enable after workweeks + procedures. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable calendar --accept-risk --format=json
```

Or `[modules] calendar=1` in `cfg/config.ini`.

Permissions: `calendar.view`, `calendar.edit`, `calendar.delete`.

## Event mode (`event_mode`)

`cfg/modules/calendar.ini` → `[calendar] event_mode`:

| Value | Meaning |
|-------|---------|
| `blocks` (default in example) | UI/agent create **blocks only** (`IS_BLOCK=1`). No client basket. Spa / likespa. |
| `visits` | Full visit: `base_event_items` basket, client, `createFromEvent`, bonuses/certs sections. |

Legacy `manual_create=0|1` is still read as fallback when `event_mode` is unset (`0`→blocks, `1`→visits).

## Tables

| Table | Role |
|-------|------|
| `base_events` | Visit / block shell (owned) |
| `base_event_items` | Planned basket lines (`ID_GOOD`, `PRICE`, `QTY`, `POS`) |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/calendar` week grid; `/calendar/day/{date}` day board.
- Shift strip: nazop / nazcab via workweeks.
- `/calendar/events/new` and `/calendar/events/{id}/edit` — event/block CRUD; in `visits` mode: client phone mask + CRM suggest, compact product search/frequent chips, `create_procedures` from items.
- `/calendar/clients?q=` / `/calendar/products?q=&staff=&date=&mode=catalog|frequent` — JSON autocomplete; products filtered by `BookingOperatorGate` when booking is on.
- Visit length follows basket `time_sec` (auto `dtend`; set `auto_duration=0` to keep a manual end).
- Save checks operator timeline occupancy (events + procedures + advance orders via `BookingOccupancy` when booking is on).
- Moving a visit excludes its own `ID_EVENT_PARENT` procedures from the busy check and shifts those journal rows (time / operator / client) with the event.
- Timed catalog goods (`time_sec > 0`) lock `qty=1` on the event form (no multi-procedure lines).
- Phone on `base_events.PHONE` is normalized `7XXXXXXXXXX` (VARCHAR 16); save binds existing client by `id_user` or phone digits.
- `/calendar/settings` — `event_mode` + hours (superadmin).
- Booking advance slots are `/booking/new` (separate module).

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

Event payload includes `items[]` (`good_id`, `price`, `qty`, `pos`), `real_summ` (derived), `event_mode`.

## Notes

- Procedures may exist with `ID_EVENT_PARENT=0` (floor / likespa). Mode does not force linking.
- Bonuses intent for a visit lives in `base_event_bonus` (bonuses module), not on `base_events`.
- `calendar.event.saved` (`id`, `is_new`, `data`) fires after create/update; `docs` uses it to spawn document / medcard shells.
