# Bookable resources

Product module `resources` (alpha): shared capacity that blocks concurrent services across staff (equipment, room pools, couches). Bind resources on catalog products; the booking slot engine, order book/edit, calendar visits, and procedure edits respect capacity.

## Enable

```bash
php bin/freesaas.php module enable resources --accept-risk --format=json
php bin/freesaas.php migrate run --module=resources --format=json
```

Or `[modules] resources=1` in `cfg/config.ini`.

Permissions: `resources.view`, `resources.edit`, `resources.delete`.

## Dependencies

- Requires: `core` >=1.0.0
- Soft: `catalog` (product bind UI), `booking` / `orders` / `calendar` / `procedures` (slot + allocation sync)

## Tables

| Table | Role |
|-------|------|
| `base_resources` | Dictionary: name, qty (capacity), enabled |
| `base_product_resources` | Product → required resource (+ units needed) |
| `base_resource_allocations` | Occupancy SoT: source `order`\|`event`\|`procedure` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/resources` — CRUD dictionary (Dictionaries rail).
- Product edit (`/products/{id}/edit`) — section **Booking resources** when module is on.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

| Op | Method | Path |
|----|--------|------|
| `resources.list` | GET | `/resources` |
| `resources.get` | GET | `/resources/{id}/edit` |
| `resources.create` | POST | `/resources/new` |
| `resources.update` | POST | `/resources/{id}/edit` |
| `resources.delete` | POST | `/resources/{id}/edit` (`action=delete`) |

```bash
curl -sS -b jar.txt 'https://example/resources?format=json'
```

## Slot engine

When enabled, `BookingSlots::check` drops starts where any required resource would exceed `qty` (sum of overlapping allocations). Writers:

- Advance orders — `booking.book.after` / order update / `orders.cancelled` / `orders.completed`
- Calendar visits — create/update/delete on `EventsRepository`
- Procedures — create/update/delete (+ re-sync after event move)

Cross-staff races on book use day lock `book_res_Ymd` around the existing operator lock.

## Notes

- Not the same as `cabinets` (stocks/skin) or `tables` (shift/journal labels).
- Closest pre-existing limit without this module: `base_procedure_types.MULTIWORK` / `PARALLELS` (salon-wide same type_id), not a named resource.
- Alpha — expect instability; diagnose local slot conflicts before escalating.
