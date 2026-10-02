# Legacy → FreeSaaS table map

Rule: FreeSaaS code never queries `phpshop_*`. New tables use `base_*`.

| Legacy (oper) | FreeSaaS | Owner | Notes |
|---|---|---|---|
| `phpshop_users` | `base_users` | core | New copy; data migrator replace |
| `phpshop_system` | `base_system` | core | New copy; data migrator replace |
| `base_admin_statuses` | `base_admin_statuses` | core | Existing shared table |
| — | `base_modules` | core | Module registry |
| — | `base_schema_migrations` | core | Schema ledger |
| `phpshop_shopusers` | `base_clients` | clients | Slim copy (no medical/1C/beauty); login=`tel` |
| `phpshop_shopusers_status` | `base_client_statuses` | clients | Client status dictionary |
| `base_shopusers_tags` + `phpshop_shopusers.TAGS` | `base_shopusers_tags` + `base_client_tag_links` | clients | Shared tag dict (uppercase); assignments normalized from denormalized TAGS |
| `phpshop_categories` | `base_categories` | catalog | Slim CRM; keep `num`; `secure_groups`→`hidden`; no site SEO / MED_* / filter `sort` |
| `phpshop_products` | `base_products` | catalog | Slim CRM; keep `num` + last_admin_*; stock qty not on row |
| `phpshop_product_relations` | `base_product_relations` | catalog | addon / upgrade / component |
| `base_units` | `base_units` | units | Shared with oper; CRUD module; no rename |
| `base_suppliers` | `base_suppliers` | suppliers | Shared with oper; CRUD module; no rename |
| `base_stocks` | same | stocks | Warehouses dictionary + leftovers report |
| `base_acts2_in` / `base_acts2_out` / `base_good_sticker_*` | same | stickers | Receipt/writeoff acts + sticker lines |
| `base_good_defaults` | same | norms | Procedure → material consumption norms |
| `base_sheet_accounts` / `base_sheet_moves` | same | sheets | LikeSpa linen ledger (disableable) |
| `base_tables` | same | tables | Massage tables; optional `ID_TABLE` for calendar/workweeks/procedures |
| `1C_*` on shopusers | `base_client_onec` (planned) | onec | Deferred; not on `base_clients` |
| `base_records*` | `base_records*` | audio | Existing names |
| `base_video_anal*` | `base_video_anal*` | video | Existing names |
| `base_events` / `base_events_changes` | same | calendar | Shared appointment slots + audit |
| `base_cabinets` | same | cabinets | Rooms dictionary; staff `skin` stores cabinet ids; stocks bind via `ID_CABINET` |
| `base_workweeks` | same | workweeks | Shift assignments (calendar + audio/video analytics); `ID_TABLE` → massage tables when `tables` enabled |
| `base_procent_history` | same | rates | Specialist pay rates — not client `BONUS_PROCENT` |
| `base_procedures*` | `base_procedures*` | procedures | Existing names; types+journal in FreeSaaS; restores UI later (EventBus → payments) |
| `base_payments` / `base_payment_types` | same | payments | Staff settlements; auto-DPS deferred until ledger |
| `base_vacations` | same | vacations | Leave register + sign workflow |
| — (read-only) | procedures + rates + workweeks (+ optional sales/stocks/records) | procents | Commission accrual report |
| `base_accounts` / `base_account_moves*` / reconciliations | same | ledger (planned, wave 2) | Enterprise accounts + ДПС |
| `base_funds` / `base_fund_movements` | same | funds (planned, wave 2) | Capital funds; requires ledger |
| balance5 report (no inflation) | — | finance (planned, wave 2) | Management P&L/balance; no inflation / no amort schedule |
| `base_orders` | `base_orders` | orders | Shared with site/oper; website cart orders; `ID_EVENT_USE`→procedure; no phpshop_orders migrator |
| `base_promopages` | `base_promopages` | likespa-promopages (planned) | Promo codes referenced by `base_orders.ID_PROMOCODE` |

Session keys in FreeSaaS: `user_id`, `login` (no `idPHPSHOP`).

## Core MVP screens (v0.2)

| Path | Purpose | Tables |
|------|---------|--------|
| `/` | CRM home + feed preview | base_news, base_users |
| `/news`, `/news/{id}` | Лента read-only | base_news, base_news_statuses |
| `/users`, `/users/{id}` | Сотрудники | base_users, base_admin_statuses |
| `/settings` | Инстанс | base_system |
| Menu | из `module.json` → ModuleManager::menuGrouped() | — |
