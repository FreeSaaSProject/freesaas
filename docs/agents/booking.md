# Booking (slots + advance orders)

Alpha product module: shared **slot engine** and **order-mode** book into `base_orders`. Storefront cart wizard uses the same engine when `booking` is enabled. Termy drop-in HTTP lives in optional `likespa` (`/api_termy.php`); live floor `/t` is in `terminals`.

## Entities — do not mix

Three different things look like “запись” in the UI. Keep them separate:

| Entity | Table | Module / UI | Role |
|--------|--------|-------------|------|
| **Calendar event** | `base_events` (+ `base_event_items` in visits mode) | `calendar` → `/calendar/events/new` | Timeline block or visit. `event_mode=blocks|visits` + `EVT_CREATE_NEW`. |
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
| `/booking/settings` | SUPERADMIN | Staff `lead_sec` (default 180) |
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

**Slot engine (single):** `BookingSlots` + `BookingOccupancy` + `BookingAvailability` / `BookingOperatorGate` / `BookingValidate`. Used by `/booking/new`, agent book, **order edit** (`OrdersRepository::update` → `BookingValidate::assertOrderUpdate`), and **procedure edit** when `booking` is enabled (`excludeProcedureId` + staff lead). Order edit excludes the current order from occupancy and ignores “now+lead” so today’s existing slot can be kept.

**Lead (seconds):** engine accepts `$leadSec` override only — no product channel keys in `booking.ini`. Staff default: `booking.lead_sec` (UI `/booking/settings`, default **180**). Callers pass their own: `terminals.lead_sec` (/t), `terminals.lead_sec_api` (/terminals/floor), `likespa.lead_sec_api` / `lead_sec_termy`, `sites.booking_lead_sec` (storefront).

**List vs pack grid:** packing stays **5 min**. Listing thins via `BookingSlots::filterListSlots` (first free + `:00/:15/:30/:45` + first after a gap). `BookingValidate::assertSlot` always uses the dense grid so a non-round 5-min `dt_start` still books.

**Date-only change policy:** if staff is not on shift / cannot do services / slot busy on the new datetime → **reject** with a clear error. Staff is **not** cleared automatically; pick another specialist or another time.

**Client:** `/booking/clients?q=digits` search; book creates `base_clients` when no match.

Occupancy for slots merges:

- `base_events` (calendar)
- `base_procedures` (journal)
- `base_orders` in `OrdersRepository::NEW_STATUSES`

Workday hours: `base_system.work_hour_start` / `work_hour_end`.

**Staff shift windows:** when `workweeks` is on, `BookingAvailability::forDay` (default `respect_staff_shift=true`) clips each operator to **PRICE_COL lane ∩ salon hours**. Site, MCP, legacy API, `/booking/*`, and order/procedure validates use that. Terminals may pass `respect_staff_shift=false` via `terminals.ignore_staff_shift_windows`. Breaks/chill still may end after salon/shift close (`allow_past_work_end` / `allow_past_shift_end`).

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

Hooks: `booking.book.before` / `booking.book.after` (product modules may overlay order payload).

## Floor `/t` and Termy API

Phones open `/t/?terminalid=…&braceletid=…&token=…` — do not rename ([`terminals.md`](terminals.md)). Staff day claim: `ID_ADMIN` + `DT_ADMIN_VALID`. Legacy Termy clients: enable `likespa` `legacy_api_enabled` → `/api_termy.php?endpoint=` ([`likespa-restores.md`](likespa-restores.md), [`agent-surfaces.md`](agent-surfaces.md)).

Staff cancel/list advance orders: `GET /booking/orders?client_id=`, `POST /booking/cancel` (`agent.ops` `booking.orders` / `booking.cancel`).

## Storefront cart wizard (Phase 2)

When `booking` is enabled and **every** cart line is a bookable service (`BookingCatalog`), `/cart` shows a **stepped** wizard above contact fields. Modes: **date-first** (date → specialist cards → time) or **master-first** (specialist → dates with slots → time). Later steps stay locked until the previous is done; phone/mail unlock after a slot is picked. Public cards show **first name + photo** only (no full FIO). `GET /cart/availability` modes: `day` (default), `masters`, `dates`. `POST /cart/checkout` → `BookingBook::createOrder`.

**Phone gate (before wizard):** guest must enter phone into session (`SitesGuest` / `$_SESSION['sites_guest']`). Lookup `base_clients` by digits; if the client status has `block_online=1` (`base_client_statuses`), wizard and checkout are blocked (“book only at the centre”). Unknown client / no status → allowed. `BookingBook::createOrder` and `/cart/checkout` re-check server-side. Staff set the ban via Clients status flag or **Block online** on `/orders/{id}/edit` (`clients.edit`). No auto no-show analysis.

**Storefront slots:** engine stays on a 5-minute grid for packing; the cart UI shows ~30-minute starts plus the first free slot and the first slot after a gap (uneven prior end). Default master choice is **Any specialist** (union of free times; checkout assigns a free operator). Advance-order occupancy uses real cart duration (not a forced 1h block).

Storefront must boot slot deps (`runtime: both`): `workweeks`, `calendar`, `procedures`, `rates` (plus `catalog` / `orders` / `clients`). If those stay admin-only, the form may appear but slots stay empty (`no_shifts`).

| Path | Who | What |
|------|-----|------|
| `GET /cart/availability` | public | JSON slots for `date` + `product_ids` (cart flat ids if omitted) |
| `POST /cart/checkout` | public | With `operator_id` + `dt_start` → order book |

Goods-only / mixed carts (any non-bookable line) keep the simple checkout (`OrdersRepository::create`, no slot). Slot math stays in `booking` — themes only render fields + `cart-booking.js`. Cart may carry extra fields from EventBus `sites.cart.booking` / `booking.book.before` (product modules).

**Agent diagnose:** `GET /sites/status?format=json` (`sites.status`) — booking active, bookable count, soft deps. See [sites.md](sites.md)#agent-api-session-json.

## Related

- [`sites.md`](sites.md) — storefront cart + booking wizard + agent diagnose
- [`terminals.md`](terminals.md) — registry; live `/t` still on legacy site
- Legacy: `likespa.hm/inc/api.php` `checkTimeEvents`, `shop.inc.php` `getTimesAndDates`
