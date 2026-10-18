# FreeSaaS table ownership map

Runtime code uses **`base_*` tables only** (plus platform probes via `information_schema`). Each product module owns the tables listed in its `module.json` → `tables` — **exclusive ownership** for uninstall dump/DROP. Listing a table another module already owns is a validate error. Using or `ALTER`ing a foreign table is fine; document that here and **omit** it from `tables[]`.

| Table(s) | Owner module | Notes |
|---|---|---|
| `base_users`, `base_system`, `base_admin_statuses`, `base_modules`, `base_schema_migrations`, `base_news*`, `base_views`, `base_user_prefs` | core | Auth, registry, news/tickets, prefs |
| `base_comments` | comments | Comment threads on news items |
| `base_comments` / `base_users` / `base_news` (usage) | contracts (indexes/columns + UI) | No owned tables; offer workflow over comments/news; `IS_ACCEPTOR` on users; indexes on comments. Uninstall must not DROP news/comments |
| `base_users` site/tips (`photo`, `site_descr`, `link_ok`) | likespa (columns + UI) | Added by likespa migration; staff edit via `core.user_edit.*` |
| `base_users` procedure restricts (`RESTRICT_TYPES*`, `RESTRICT_PROCS`) | procedures (columns + UI) | Added by procedures migration; only when procedures enabled |
| `base_staff_bracelets` | terminals | Staff NFC UID ↔ user (no column on `base_users`) |
| `base_clients`, `base_client_statuses`, `base_shopusers_tags`, `base_client_tag_links` | clients | CRM clients; login = phone; `base_client_statuses.block_online` gates storefront booking |
| `base_categories`, `base_products`, `base_product_relations` | catalog | Categories / products / relations. Column `base_products.onec_id` is written via `terminals` (product edit section); catalog create/update leave it untouched so values survive module disable. |
| `base_units` | units | UoM dictionary |
| `base_suppliers` | suppliers | Suppliers dictionary |
| `base_stocks` | stocks | Warehouses + leftovers |
| `base_acts2_in` / `base_acts2_out` / `base_good_sticker_*` | stickers | Receipt/writeoff acts + sticker lines |
| `base_good_defaults` | norms | Procedure → material norms |
| `base_sheet_accounts` / `base_sheet_moves` | sheets | Legacy linen ledger (disableable; prefer `laundry`) |
| `base_laundry_accounts` / `base_laundry_item_types` / `base_laundry_moves` / `base_laundry_shipments*` / `base_laundry_returns*` | laundry | Wash-cycle linen SoT (ship/accept by type) |
| `base_term_scans` | likespa | Terminal scans / promoter conversion (disableable) |
| `base_staff_bracelets` / `base_comments` / `base_news` (usage) | likespa (staff debts scan) | No owned tables; cron/UI posts 1C folio debts as comments when `staff_debts_enabled` |
| `base_site_hosts` | sites | Public domains: theme, home promo, catalog root(s), phone; resolve by `HTTP_HOST` |
| `base_promopages` | sites | Public promo / LP pages (home content via `base_site_hosts.promo_id`) |
| `base_page_categories` | sites | Site articles tree; `site_host_id` (0 = all hosts) |
| `base_redirects` | sites | Site URL redirects; `site_host_id` (0 = all hosts) |
| `base_system` site fields (`name`, `title`, `keywords`, `tel`) | sites (write) / core (table) | Fallback SEO/contacts UI `/sites/seo`; per-host name/tel on `base_site_hosts` |
| `base_system` flyer / promo (`flyerEnabled`, `flyerDiscount`, `PROMO_*`) | likespa (write) / core (table) | `SystemFlyerPromo`; UI `/likespa/settings` |
| `base_system` work hours (`work_hour_start`, `work_hour_end`) | calendar (write) / core (table) | `CalendarTimeline::updateWorkHours`; UI `/calendar/settings` |
| `base_terminals`, `base_bracelets`, `base_terminals_activations` | terminals | Device registry + locker bracelet UIDs (disableable) |
| `base_termloads`, `base_fullstats` | terminals | Therm occupancy / ticket visits |
| `base_term_orders` | terminals (legacy) | Old Volga Termy feed; table may remain, UI/import removed |
| `base_client_onec_bindings` | terminals | Current 1C ticket ↔ client (ephemeral TTL; no ALTER `base_clients`) |
| `base_staff_bracelets` | terminals | Permanent staff NFC UID ↔ `base_users.id` (no ALTER `base_users`) |
| `base_staff_report_profiles` | staff_reports | Per-position quality report config (traffic lights) |
| `base_tables` | tables | Massage tables; optional `ID_TABLE` |
| `base_records*` (split by domain) | audio / audio_dicts / audio_pipeline / audio_reports / audio_llm | Shared naming; ownership by domain |
| `base_records_badges`, `base_badge_assignments`, `base_records_reviews`, `base_records_balances` | audio | Badges, assignments; storage settings in `cfg/modules/audio.ini` |
| `base_records_coverage_profiles` | audio_pipeline | Per-status / per-staff coverage overrides |
| `base_records_llm_studies`, `base_records_llm_study_results` | audio_llm | LLM studies over transcripts + cyclic templates |
| `base_events` / `base_events_changes` | calendar | Appointments + audit |
| `base_cabinets` | cabinets | Rooms; staff `skin`; stocks `ID_CABINET` |
| `base_workweeks` | workweeks | Shifts |
| `base_procent_history` | rates | Specialist pay rates |
| `base_procedures*` | procedures | Types + journal + `base_procedure_restores` schema; restore UI/settlement in likespa |
| `base_payments` / `base_payment_types` | payments | Staff settlements |
| `base_payment_move_links` | payments | Multi-allocation payments ↔ ledger moves |
| `base_bonus_moves` | bonuses | Client loyalty ledger (credit/debit, event settle) |
| `base_certificates` / `base_certificate_ledger` | certificates | Gift certificates + money ledger (issue/spend/expire/undo) |
| `base_orders.ID_SERT` | orders (column) / certificates (write) | Optional link to certificate used on checkout |
| `base_telegram_sends` | bots | Outbound TG/Max queue (`BOT_SOURCE` 1=client, 2=staff) |
| `base_bot_links` | bots | Messenger chat ids for staff/clients (no ALTER `base_users` / `base_clients`) |
| `base_bot_chat_state` | bots | Per-chat FSM pending mode for bot plugins |
| `base_fit_recipes` / `base_fit_recipe_items` | wellness | Own recipes + composition; synthetic food via `base_fit_foods.ID_SOURCE_RECIPE` |
| `base_vacations` | vacations | Leave register |
| `base_fit_*` / `base_healths_*` | wellness | Personal food/workouts/weight/goals + health diaries (strict `ID_USER`) |
| `base_accounts*` / `base_account_*` | ledger | Accounts, moves, reconciliations |
| `base_funds` / `base_fund_movements` | funds | Funds tree + movements |
| `base_tinvest_accounts` / `base_broker_snapshots` / `base_broker_positions` | liquid_assets | T-Invest bindings + broker portfolio snapshots |
| `base_bank_connections` / `base_bank_account_links` / `base_bank_tx_map` | banks | Bank OAuth connections, account links, statement→move map |
| — (read models) | finance / procents | Reports over ledger/rates/procedures |
| `base_bi_shares` | bi | Live share tokens for BI report families |
| `base_bi_family_defs` | bi | BI recipe overrides/customs (`RECIPE_JSON`); shipped defaults live in owning modules (`modules/*/recipes/bi/`) via EventBus `bi.recipes` |
| `base_client_mcp_sessions` | client_mcp | Public MCP client sessions (hashed `client_token`, `SITE_HOST_ID`, phone, session order ids) |
| `base_client_mcp_hosts` | client_mcp | Per-storefront-host MCP settings (`SITE_HOST_ID` → on/off, name, consent link, disabled tools) |
| `base_orders` | orders | Website/cart orders (admin UI + storefront `sites` checkout via `OrdersRepository::create`) |
| `base_polls` | polls | Poll definition tree (EL_TYPE poll/question/answer) |
| `base_poll_invites` | poll_invites | Poll invite instances + answers in DATA_OPTS |

Session keys: `user_id`, `login`, `user_name`, `permissions`, `status_id`, `status_flags`.

When adding tables: declare them in the owning module’s `module.json` `tables` and create via additive migrations.
