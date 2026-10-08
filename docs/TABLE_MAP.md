# FreeSaaS table ownership map

Runtime code uses **`base_*` tables only** (plus platform probes via `information_schema`). Each product module owns the tables listed in its `module.json` → `tables`.

| Table(s) | Owner module | Notes |
|---|---|---|
| `base_users`, `base_system`, `base_admin_statuses`, `base_modules`, `base_schema_migrations`, `base_news*`, `base_views`, `base_user_prefs` | core | Auth, registry, news/tickets, prefs |
| `base_users` site/tips (`photo`, `site_descr`, `link_ok`) | likespa (columns + UI) | Added by likespa migration; staff edit via `core.user_edit.*` |
| `base_users` procedure restricts (`RESTRICT_TYPES*`, `RESTRICT_PROCS`) | procedures (columns + UI) | Added by procedures migration; only when procedures enabled |
| `base_staff_bracelets` | terminals | Staff NFC UID ↔ user (no column on `base_users`) |
| `base_clients`, `base_client_statuses`, `base_shopusers_tags`, `base_client_tag_links` | clients | CRM clients; login = phone |
| `base_categories`, `base_products`, `base_product_relations` | catalog | Categories / products / relations |
| `base_units` | units | UoM dictionary |
| `base_suppliers` | suppliers | Suppliers dictionary |
| `base_stocks` | stocks | Warehouses + leftovers |
| `base_acts2_in` / `base_acts2_out` / `base_good_sticker_*` | stickers | Receipt/writeoff acts + sticker lines |
| `base_good_defaults` | norms | Procedure → material norms |
| `base_sheet_accounts` / `base_sheet_moves` | sheets | Linen ledger (disableable) |
| `base_term_scans` | likespa | Terminal scans / promoter conversion (disableable) |
| `base_promopages` | likespa | Client-site promo / LP pages |
| `base_page_categories` | likespa | Client-site articles tree (migrator from `phpshop_page_categories`) |
| `base_redirects` | likespa | Site URL redirects (oper show_redirects) |
| `base_system` site fields (`name`, `title`, `keywords`, `tel`) | likespa (write) / core (table) | `SystemSite`; UI `/likespa/site`; company on `/settings` |
| `base_system` flyer / promo (`flyerEnabled`, `flyerDiscount`, `PROMO_*`) | likespa (write) / core (table) | `SystemFlyerPromo`; UI `/likespa/settings` |
| `base_system` work hours (`work_hour_start`, `work_hour_end`) | calendar (write) / core (table) | `CalendarTimeline::updateWorkHours`; UI `/calendar/settings` |
| `base_terminals`, `base_bracelets`, `base_terminals_activations` | terminals | Device registry + locker bracelet UIDs (disableable) |
| `base_termloads`, `base_fullstats`, `base_term_orders` | terminals | Therm occupancy / ticket visits / Volga Termy orders |
| `base_client_onec_bindings` | terminals | Current 1C ticket ↔ client (ephemeral TTL; no ALTER `base_clients`) |
| `base_staff_bracelets` | terminals | Permanent staff NFC UID ↔ `base_users.id` (no ALTER `base_users`) |
| `base_staff_report_profiles` | staff_reports | Per-position quality report config (traffic lights) |
| `base_tables` | tables | Massage tables; optional `ID_TABLE` |
| `base_records*` (split by domain) | audio / audio_dicts / audio_pipeline / audio_reports / audio_llm | Shared naming; ownership by domain |
| `base_records_badges`, `base_audio_settings`, `base_badge_assignments`, `base_records_reviews`, `base_records_balances` | audio | Badges, settings, assignments |
| `base_records_llm_studies`, `base_records_llm_study_results` | audio_llm | LLM studies over transcripts + cyclic templates |
| `base_events` / `base_events_changes` | calendar | Appointments + audit |
| `base_cabinets` | cabinets | Rooms; staff `skin`; stocks `ID_CABINET` |
| `base_workweeks` | workweeks | Shifts |
| `base_procent_history` | rates | Specialist pay rates |
| `base_procedures*` | procedures | Types + journal + `base_procedure_restores` schema; restore UI/settlement in likespa |
| `base_payments` / `base_payment_types` | payments | Staff settlements |
| `base_bonuspay` | bonuses | Client loyalty ledger (credit/debit, event settle) |
| `base_telegram_sends` | bots | Outbound TG/Max queue (`BOT_SOURCE` 1=client, 2=staff) |
| `base_bot_links` | bots | Messenger chat ids for staff/clients (no ALTER `base_users` / `base_clients`) |
| `base_vacations` | vacations | Leave register |
| `base_fit_*` / `base_healths_*` | wellness | Personal food/workouts/weight/goals + health diaries (strict `ID_USER`) |
| `base_accounts*` / `base_account_*` | ledger | Accounts, moves, reconciliations |
| `base_funds` / `base_fund_movements` | funds | Funds tree + movements |
| `base_tinvest_accounts` / `base_broker_snapshots` / `base_broker_positions` | liquid_assets | T-Invest bindings + broker portfolio snapshots |
| — (read models) | finance / procents | Reports over ledger/rates/procedures |
| `base_orders` | orders | Website/cart orders |
| `base_polls` | polls | Poll definition tree (EL_TYPE poll/question/answer) |
| `base_poll_invites` | poll_invites | Poll invite instances + answers in DATA_OPTS |

Session keys: `user_id`, `login`, `user_name`, `permissions`, `status_id`, `status_flags`.

When adding tables: declare them in the owning module’s `module.json` `tables` and create via additive migrations.
