# Terminals & therms

Product module `terminals`: device registry (`base_terminals`, `base_bracelets`), therm occupancy analytics (`base_termloads`, `base_fullstats`), and HTTP sync jobs. Secrets/URLs live in `cfg/modules/terminals.ini` (not platform `config.ini`). Legacy table `base_term_orders` may still exist from older installs; UI/import removed (Volga Termy feed no longer used).

Calendar day/week: chill/rest procedure chips are painted by `calendar.timeline.enrich` in this module (`TerminalsConfig::chillProductId()` + `notice` `chill#…`) — calendar only passes `id_good`/`notice`/`class`, no hardcoded product id.

Live floor shell is **`GET|POST /t`** (frozen query `terminalid`=device CODE, `braceletid`, `token`; optional `category`, `adminId`, `gids`, `getTime`, `braceletNum`, `unlinkClient`, `oldProcedureOff`, `doConfirm`, `loginUser`, `disable1C`, `resetCache`, `flyerOn`). Staff NFC → day claim (`ID_ADMIN` / `DT_ADMIN_VALID`). Guest home (oper-parity): bracelet NUM prompt, **category** matrix (incl. `dop_cat` cross-category rows) with multi-gid cart → continue → slot pick (list grid: first + 15-min + after gap; dense 5-min still validates); procedures written with **TIME_NET** + optional **chill** product (`chill_product_id`, default 16); cancel disables linked chill. Lead: `lead_sec` for `/t` (default 180), `lead_sec_api` for `/terminals/floor` (default 600) — own ini, not booking. Soft-deps: `booking`, `procedures`, `workweeks`, optional **`likespa`** (flyer/promoter discounts + term scans). Termy/legacy HTTP drop-in lives in likespa (`/api_termy.php`).

### Discounts on `/t` (soft `likespa`)

Settings: `/likespa/settings` → `base_system` (`flyerEnabled` / `flyerDiscount`, `PROMO_POSITION_ID`, `PROMO_DISCOUNTS`, `DISCOUNTS_TYPES`). Per-terminal flyer button: `IS_TERMINAL_FLYER_DISCOUNT`.

| Mechanism | Behavior |
|-----------|----------|
| Client status % | From `base_client_statuses.discount` when client known |
| Flyer | Query `flyerOn=1` when terminal + system flyer enabled; replaces client % as base |
| Promoter | First scan that day by staff whose `status_id` ∈ `PROMO_POSITION_ID` writes `base_term_scans` with `DISCOUNT_ACTIVATED=1`; any later admin sees promo for that client (match `ID_1C` / `ID_USER` / `PHONE`) |
| Price | **max**(base, promo%) — not summed; promo capped by `DISCOUNTS_TYPES[type]`; sale uses paid `summ` + list `summ_default` |

Diagnose: `GET /terminals/t/status?format=json` → `terminals_t.discounts` (`promo_positions_configured`, `promo_mechanism_enabled`, `discounts_types_loaded`, flyer flags).

## Enable

```bash
# Copy modules/terminals/config.example.ini → cfg/modules/terminals.ini
# Set 1c_* / worker_token as needed

php bin/freesaas.php module enable terminals --format=json
php bin/freesaas.php module validate terminals
php bin/freesaas.php health --module=terminals
php bin/smoke.php
```

Ini flags: `registry_enabled`, `analytics_enabled`, `sync_enabled`.

Permissions: `terminals.view`, `terminals.edit`, `terminals.sync` (jobs use `worker_token`, not session).

## Human UI

| Path | Role |
|------|------|
| `/terminals` | Device list / CRUD |
| `/terminals/bracelets` | Bracelet UID ↔ NUM |
| `/terminals/termloads` | Day occupancy series |
| `/terminals/fullstats` | Ticket visits + classify |
| `/terminals/settings` | Superadmin: flags + secrets + floor/API lead + chill product |
| `/t` | Public NFC floor shell (no admin chrome) |
| `/terminals/t/status` | Staff diagnose for /t (claim / 1C / soft-deps) |
| `/terminals/1c` | **1C diagnostics** console (legacy `/1c`): bracelet/client lookup, price, sale/unsale, raw dump. Permission `terminals.edit`. Live API only (`onecLive`); no cheese-password — session auth. Modes: `/terminals/1c/{mode}` |
| `/terminals/floor` | Staff/agent procedure-mode book/cancel |

## Agent JSON (session)

Same routes as UI; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`. Surface B — not `agent_token`. Public `/t` is not an assistant tool; use `terminals.t.get`.

```bash
curl -sS -b jar.txt 'https://example/terminals?format=json'
curl -sS -b jar.txt 'https://example/terminals/t/status?terminalid=1&format=json'
curl -sS -b jar.txt 'https://example/terminals/1c?format=json'
curl -sS -b jar.txt 'https://example/terminals/1c/clientByCard?NFCUID=503AE7A6&format=json'
curl -sS -b jar.txt -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' \
  'https://example/terminals/floor' \
  -d '{"action":"book","product_id":9,"operator_id":51,"dt_start":1760500800,"terminal_id":1,"disable_1c":true}'
curl -sS -b jar.txt 'https://example/terminals/termloads?day=2026-10-06&format=json'
curl -sS -b jar.txt 'https://example/terminals/settings?format=json'
```

Smoke (no session): `GET /t/?terminalid=RealMe21V244&braceletid=STAFFUID` (or numeric id; token auto-issued via redirect). With `1c_enabled=0` stub bracelet is used; optional `disable1C=1` still works when 1C is on.

## Sync jobs (worker_token)

Header `X-Terminals-Token` or `?token=` (like audio workers).

```bash
curl -sS -X POST -H "X-Terminals-Token: $TOKEN" 'https://example/terminals/jobs/load'
curl -sS -X POST -H "X-Terminals-Token: $TOKEN" \
  'https://example/terminals/jobs/tickets?day=2026-10-06'
```

- `load` → 1C `club_workload` → `base_termloads` (minute sample)
- `tickets` → 1C `club_workload_detail` → replace day in `base_fullstats`

## Config keys

| Key | Purpose |
|-----|---------|
| `1c_enabled` | Global 1C link (legacy `1C_ENABLED`). `0` → stub/debug on `/t`, no live sale/unsale/bracelet; sync jobs skip |
| `1c_url`, `1c_auth`, `1c_apikey`, `1c_terminal`, `1c_uid` | 1C club API credentials |
| `worker_token` | Jobs auth |
| `booking_terminal_id`, `booking_admin_id` | Defaults for bots / floor tests |

Per-request override: `/t/?…&disable1C=1` (even when `1c_enabled=1`).

Domain smoke (stub claim/book/cancel): `php bin/check-terminals-floor.php` (also section `terminals_floor` in `php bin/smoke.php`).

Per-device `CODE` stays in `base_terminals` and is shown in the `/terminals` list and edit form (not treated as a secret).

## 1C client binding & staff bracelet

Own tables (no ALTER of `base_clients` / `base_users`):

| Table | Role |
|-------|------|
| `base_client_onec_bindings` | Current 1C ticket id for a client (`onec_id` + `dt_valid` unix). Upsert by `client_id`; expire via `ClientOnecBindingsRepository::expireStale`. Visit history stays in `base_fullstats` / `base_procedures`. |
| `base_staff_bracelets` | Permanent staff NFC UID ↔ `user_id`. Edit on staff user form via `core.user_edit.*` (persists in this table, not `UsersRepository`). |

Legacy import: data migrator `phpshop_shopusers_bracelets_to_base_staff_bracelets` copies oper staff NFC from `phpshop_shopusers.1C_BRACELET` where `ID_USER` points at the admin (`phpshop_users` / `base_users.id`). See [migrations.md](migrations.md) staff identity pack.

Product nomenclature `base_products.onec_id`: column stays on catalog table; UI/save via `catalog.product_edit.sections` / `.save` when `terminals` is on. Catalog create/update never touch the column, so values remain if the module is disabled.

Not the same as `base_bracelets` (locker UID↔NUM registry) or `base_procedures.1C_BRACELET` (sale-time snapshot).

Terminal writers resolve staff with `StaffBraceletsRepository::findByUid` before guest ticket → `ClientOnecBindingsRepository` / `OnecClient::getBraceletInfo`.

## Notes

- Column `IS_MASSЕUR_MODE` keeps Cyrillic Е for shared legacy DB compatibility.
- When `terminals` is enabled, likespa term-scans UI soft-resolves terminal names.
