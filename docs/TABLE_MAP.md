# FreeSaaS table ownership map

Runtime code uses **`base_*` tables only** (plus platform probes via `information_schema`). Each product module owns the tables listed in its `module.json` → `tables`.

| Table(s) | Owner module | Notes |
|---|---|---|
| `base_users`, `base_system`, `base_admin_statuses`, `base_modules`, `base_schema_migrations`, `base_news*`, `base_views`, `base_user_prefs` | core | Auth, registry, news/tickets, prefs |
| `base_clients`, `base_client_statuses`, `base_shopusers_tags`, `base_client_tag_links` | clients | CRM clients; login = phone |
| `base_categories`, `base_products`, `base_product_relations` | catalog | Categories / products / relations |
| `base_units` | units | UoM dictionary |
| `base_suppliers` | suppliers | Suppliers dictionary |
| `base_stocks` | stocks | Warehouses + leftovers |
| `base_acts2_in` / `base_acts2_out` / `base_good_sticker_*` | stickers | Receipt/writeoff acts + sticker lines |
| `base_good_defaults` | norms | Procedure → material norms |
| `base_sheet_accounts` / `base_sheet_moves` | sheets | Linen ledger (disableable) |
| `base_term_scans` | likespa | Terminal scans / promoter conversion (disableable) |
| `base_terminals`, `base_bracelets`, `base_terminals_activations` | terminals | Device registry + bracelet UIDs (disableable) |
| `base_termloads`, `base_fullstats`, `base_term_orders` | terminals | Therm occupancy / ticket visits / Volga Termy orders |
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
| `base_vacations` | vacations | Leave register |
| `base_accounts*` / `base_account_*` | ledger | Accounts, moves, reconciliations |
| `base_funds` / `base_fund_movements` | funds | Funds tree + movements |
| — (read models) | finance / procents | Reports over ledger/rates/procedures |
| `base_orders` | orders | Website/cart orders |

Session keys: `user_id`, `login`, `user_name`, `permissions`, `status_id`, `status_flags`.

When adding tables: declare them in the owning module’s `module.json` `tables` and create via additive migrations.
