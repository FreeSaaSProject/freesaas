# LikeSpa instance pack

Product module `likespa`: restores, FREE_TICKET, flyer/promo, term scans, calendar overlays. Soft-depends on `sites` / `booking` / `procedures` / … — does not own the storefront kernel ([`sites.md`](sites.md)).

## Cutover from legacy likespa2 / PHPShop / Balance

1. Enable `sites` (+ catalog/orders/clients) on the shared FreeSaaS DB; enable `likespa` when you need instance features.
2. Run sites migrations `006`/`007` — seeds default host from `public_host` / `mothersite`.
3. Install licensed skins from private theme repos into `themes/<id>/` ([`themes.md`](themes.md)) — not into public `freesaas`:
   - `likespa` ← https://github.com/ilcommm/freesaas-theme-likespa
   - `balance` ← https://github.com/ilcommm/freesaas-theme-balance
4. Storefront trees (same package, `app.role=storefront`, storefront `.htaccess` → `site.php`):
   - `likespa2.hm` — host theme `likespa`, catalog roots as needed, FREE_TICKET on
   - `balance-spa2.hm` — host theme `balance`, promo `balance` (#10), catalog root **16**, FREE_TICKET off
5. Symlink or sync `UserFiles/` from the legacy tree for promo/media URLs.
6. Keep old PHPShop trees until parity; do not keep writing `phpshop_*` from the new runtime.

## Procedure restores

Port of oper `show_procedure_restores` / `edit_procedure_restore`. Schema `base_procedure_restores` stays owned by `procedures`. UI + автовзаиморасчёт live in likespa.

### Enable

- `[modules] likespa=1` and `procedures=1` (soft: `payments=1` for settlement).
- Ini: `cfg/modules/likespa.ini` — `restores_enabled=1`, `restores_lookback_days=4`, `restores_payment_type=1`.
- Permission: `likespa.restores`. Bulk delete / act signature: `SUPERADMIN_ACCESS`.

### Human UI

- `/likespa/restores` — list (menu under procedures group)
- `/likespa/restores/new` — create (cancelled procs: `ENABLED<1`, `SUMM=0`, `SUMM_DEFAULT>0`; window = today, or today−N days for superadmin)
- `/likespa/restores/{id}/edit` — edit reason/summ/sign

On save:

1. Set `ID_OPERATOR_PAID` (operator, or admin if `NOT_IN_TEAM`)
2. If payments on and no row with `DESCR = procedureId` → create staff debit `DIRECTION=-1`, **`ACCOUNT=0`** (internal settlement, no DPS)
3. `ProceduresRepository::applyRestore` → `RESTORED=1`, `SUMM=SUMM_DEFAULT`, `ENABLED=1`

### Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

```bash
curl -sS -b jar.txt 'https://example/likespa/restores?format=json'
curl -sS -b jar.txt 'https://example/likespa/restores/new?format=json'
curl -sS -b jar.txt -X POST 'https://example/likespa/restores/new?format=json' \
  -d 'reason=test&summ=500&id_procedure=123'
curl -sS -b jar.txt 'https://example/likespa/restores/1/edit?format=json'
```

## Legacy HTTP API drop-in (cutover)

Optional facade for DNS cutover without changing Termy/NFC clients. Off by default (`legacy_api_enabled=0`). New instances without `likespa` do not get these routes.

| Path | Methods | Role |
|------|---------|------|
| `/api_termy.php?endpoint=` | GET | Termy order-mode (catalog category from ini, default 16) → `booking` / `orders` |
| `/api.php?endpoint=` | GET | Broader legacy ops incl. procedure book when `terminalId` set |
| `/api1c.php` and `/api1c.php/{*path}` | GET/POST/… | Reverse proxy → `terminals` `OnecClient::proxy` (PATH_INFO e.g. `/api1c.php/client_by_card?card=`) |

Threat model: legacy Termy was open GET — empty `legacy_api_key` keeps that. Optional key via `X-Likespa-Api-Key` or `api_key=`. Secrets never returned. Public routes are allow-listed in `bin/check-routes.php`. Product agent layer is **`likespa.legacy_api.status`** (session SUPERADMIN), not the drop-in paths as chat tools. See [`agent-surfaces.md`](agent-surfaces.md).

```bash
# Enable in /likespa/settings or cfg/modules/likespa.ini
curl -sS 'https://site.example/api_termy.php?endpoint=health'
curl -sS -b jar.txt 'https://admin.example/likespa/legacy-api/status?format=json'
```

### `/api_termy.php` (Volga Termy — thin list contract)

Endpoints: `health` (`status: ok`), `get_categories`, `get_services`, `get_personnel`, `get_client`, `book_service`, `get_my_orders`, `cancel_order`.

Shapes stay **arrays**: `{categories:[{id,name}]}`, `{services:[{id,name,price,duration,category_id}], date}`. Category scoped by `legacy_api_termy_category` (default 16). Do not change these to Android map shapes.

### `/api.php` (Android kiosk — old phpshop contract)

Endpoints: Termy subset plus `cancel_booking`, `free_terminal`, `get_employee_status`, `log_event` (stub `{ok:1}`).

| Endpoint | Notes |
|----------|--------|
| `health` | `status: "OK"` (uppercase; Termy keeps `"ok"`) |
| `get_categories` | **Map** keyed by id: `id`, `name`, `num`, `parent_to`, `contentstart`, `NO_CART` |
| `get_services` | **Map** keyed by id: `id`, `category`, `id1c`, descriptions; `addons`/`upgrades` = **arrays** `[{id,benefit}]` (not object-maps); `is_addon` int `0`/`1`. Filter enabled + non-material + non-empty `onec_id`. `duration` = chill-capped booking span. Top-level `work_hours` = salon hours. Optional `include_booking_eligibility=true` → top-level `booking_eligibility` map `{serviceId: bookable\|no_masters\|no_time_slots}`. Promo query → `promo.valid=false` |
| `get_personnel` | Empty `date` → today; always `massage_therapists` / `equipment` / `administrators`; `gender` int `0`. Therapists may include `price_col` / `shift_end` (informational); **slots stay salon-window**, not clipped to shift I |
| `book_service` | With `terminalId`/`terminalID`: procedure via `TerminalBooking` only when **all** of `client_id`, `document_id`, `bracelet_id`, `price` (legacy gate). Else soft-reserve terminal (`reserved=1`, no `base_procedures` row). `specialist_id` → operator, `adminId` → admin. Without identity, order-only booking stays on `/api_termy.php`. |
| `free_terminal` | Accepts `terminalID` / `terminalId`; `{result:1}` |

Implementation: `modules/likespa/lib/LikespaLegacyApi.php` (`dispatchFull` vs `dispatchTermy`).

## Settings / FREE_TICKET

LikeSpa **product** feature (not part of sites/booking core). Overlay hooks: `sites.cart.booking`, `sites.diagnose`, `sites.host_edit.sections` / `.save`, `booking.book.before`. Health check: `likespa.free_ticket`.

Superadmin: `/likespa/settings` (`likespa.settings.get` / `.save`).

| Field | Effect |
|-------|--------|
| `free_ticket_threshold` | `base_system.FREE_TICKET`; `0` = off storefront-wide |
| flyer / promo fields | Flyer + promoter discount blob |

Per-domain enable: POST `free_ticket=1` on host save (likespa injects the checkbox on `/sites/hosts/{id}/edit`). Cart eligibility: sum ≥ threshold, not today, host flag on.

```bash
curl -sS -b jar.txt 'https://admin.example/likespa/settings?format=json'
curl -sS -b jar.txt -X POST 'https://admin.example/likespa/settings?format=json' \
  -d 'free_ticket_threshold=1900'
# Overlay appears in sites diagnose when likespa is booted:
curl -sS -b jar.txt 'https://admin.example/sites/status?format=json'   # → free_ticket{…}, issues with module=likespa
php bin/freesaas.php health --module=likespa --format=json
```

### Notes (restores)

- No auto-DPS / ledger moves for these payments.
- Deleting a restore row does not reverse the payment or procedure flags.
