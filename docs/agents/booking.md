# Booking (slots + advance orders)

Alpha product module: shared **slot engine** and **order-mode** book into `base_orders`. Storefront cart wizard uses the same engine when `booking` is enabled (see Phase 2 below). Termy API and live `/t` come later.

## Entities — do not mix

Three different things look like “запись” in the UI. Keep them separate:

| Entity | Table | Module / UI | Role |
|--------|--------|-------------|------|
| **Calendar event** | `base_events` | `calendar` → `/calendar/events/new` | Staff appointment on the timeline (who is busy when). Created by slot click or «Новое событие» when `manual_create` + `EVT_CREATE_NEW`. |
| **Booking (order)** | `base_orders` | `booking` → `/booking/new` | Client advance order with specialist + slot (`ID_COSMETOLOG`, `DT_EVENT_NEW`, status in `OrdersRepository::NEW_STATUSES`). Rail: Calendar group → «Новая запись». Also buttons on day/week headers. |
| **Procedure** | `base_procedures` | `procedures` / floor `/t` / “create procedures” from an event | Delivered service (journal). Occupancy for slots; not created by `/booking/new` in v0.1. |

**No duplication with slot click:** timeline empty-hour links stay `/calendar/events/new?date=&hour=&staff=&ret=` (events only). Booking never hijacks that path. Staff who need a client order use `/booking/new` (menu or «Новая запись» on day/week).

Cross-links on the booking form point to event create when the operator meant a calendar appointment instead.

## Enable

```bash
php bin/freesaas.php module enable booking --accept-risk --format=json
php bin/freesaas.php migrate run --module=booking --format=json
# deps usually already on: catalog, clients, workweeks, calendar; soft: orders, procedures
```

Grant staff `booking.view` / `booking.edit` (or use SUPERADMIN).

## Staff UI

| Path | Who | What |
|------|-----|------|
| `/booking/new` | `booking.edit` | Wizard: date → products → slots → phone → `BookingBook::createOrder` → redirect to `/orders/{id}/edit` |
| Query | optional | `date`, `product_ids[]`, `operator_id` or `staff`, `hour` (prefer nearest slot), `ret` |
| Day/week | same perm + module on | Secondary button «Новая запись» → `/booking/new?date=…` |
| Rail | Calendar group | Menu item `booking.menu.new` |

## Domain (order mode)

| Mode | Writes | When |
|------|--------|------|
| Order (this module) | `base_orders` with `ID_COSMETOLOG`, `DT_EVENT_NEW`, `STATUS=cashComplete` | Website / agent / staff advance book |
| Procedure | `base_procedures` | Floor terminal + 1C (not in v0.1) |

**Catalog picker:** enabled products, `is_material=0`, category not `hidden` (no consumables / disabled folders).

**Who can do the service:** on shift, enabled, status `SHOW_IN_LOADS`, not `ASSIST_TEAM`, not in `RESTRICT_TYPES` / `RESTRICT_PROCS`, and rates matrix `base_procent_history.content[type_id] > 0` (not `"2"`). Soft-dep `rates`. **`IS_HIDDEN` does not block booking** (hidden only for payroll / other filters). Empty reasons: `no_shifts` / `none_can_do` / `no_slots`.

**Slot engine (single):** `BookingSlots` + `BookingOccupancy` + `BookingAvailability` / `BookingOperatorGate` / `BookingValidate`. Used by `/booking/new`, agent book, and **order edit** (`OrdersRepository::update` → `BookingValidate::assertOrderUpdate`). Edit excludes the current order from occupancy and ignores “now+lead” so today’s existing slot can be kept.

**Date-only change policy:** if staff is not on shift / cannot do services / slot busy on the new datetime → **reject** with a clear error. Staff is **not** cleared automatically; pick another specialist or another time.

**Client:** `/booking/clients?q=digits` search; book creates `base_clients` when no match.

Occupancy for slots merges:

- `base_events` (calendar)
- `base_procedures` (journal)
- `base_orders` in `OrdersRepository::NEW_STATUSES`

Workday hours: `base_system.work_hour_start` / `work_hour_end`.

## Agent ops

```bash
# Availability
curl -sS -H "Cookie: …" \
  "https://admin.example/booking/availability?date=2026-10-15&product_ids=9,12&operator_id="

# Book (order mode)
curl -sS -X POST -H "Cookie: …" -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  "https://admin.example/booking/book" \
  -d '{"phone":"79061234567","product_ids":[9],"operator_id":51,"dt_start":1760500800}'
```

JSON: send `Accept: application/json` (or AgentHttp session). Permissions: `booking.view` / `booking.edit`.

Hooks: `booking.book.before` / `booking.book.after` (likespa FREE_TICKET overlays later).

## Frozen terminal URL (later phase)

Phones open `/t/?terminalid=…&braceletid=…&token=…` — do not rename. Staff day claim: `ID_ADMIN` + `DT_ADMIN_VALID`; one staff → one terminal.

## Storefront cart wizard (Phase 2)

When `booking` is enabled and **every** cart line is a bookable service (`BookingCatalog`), `/cart` shows date → specialist → slot → phone, then `POST /cart/checkout` calls `BookingBook::createOrder` (prices from session cart lines).

| Path | Who | What |
|------|-----|------|
| `GET /cart/availability` | public | JSON slots for `date` + `product_ids` (cart flat ids if omitted) |
| `POST /cart/checkout` | public | With `operator_id` + `dt_start` (+ optional `free_ticket`) → order book |

Goods-only / mixed carts (any non-bookable line) keep the simple checkout (`OrdersRepository::create`, no slot). Slot math stays in `booking` — themes only render fields + `cart-booking.js`.

LikeSpa `FREE_TICKET`: threshold `base_system.FREE_TICKET`; hooks `sites.cart.booking` + `booking.book.before` (not today; sum ≥ threshold).

## Related

- [`sites.md`](sites.md) — storefront cart + booking wizard
- [`terminals.md`](terminals.md) — registry; live `/t` still on legacy site
- Legacy: `likespa.hm/inc/api.php` `checkTimeEvents`, `shop.inc.php` `getTimesAndDates`
