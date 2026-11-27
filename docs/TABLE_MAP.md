# FreeSaaS table ownership map

Runtime code uses **`base_*` tables only** (plus platform probes via `information_schema`). Each product module owns the tables listed in its `module.json` → `tables` — **exclusive ownership** for uninstall dump/DROP. Listing a table another module already owns is a validate error. Using or `ALTER`ing a foreign table is fine; document that here and **omit** it from `tables[]`.

| Table(s) | Owner module | Notes |
|---|---|---|
| `base_users`, `base_admin_statuses`, `base_modules`, `base_schema_migrations`, `base_news*`, `base_views`, `base_user_prefs` | core | Auth, registry, news/tickets, prefs; staff `bday` (unix) + `show_bday_in_cal` (calendar markers) |
| `base_edge_hosts` | core | Edge workers hosts (gateway URL, key, secret, SPKI pin, services asr/video, per-service last status); `app/EdgeHosts.php` |
| `base_comments` | comments | Comment threads on news items |
| `base_comments` / `base_users` / `base_news` (usage) | contracts (indexes/columns + UI) | No owned tables; offer workflow over comments/news; `IS_ACCEPTOR` on users; indexes on comments. Uninstall must not DROP news/comments |
| `base_users` site/tips (`photo`, `site_descr`, `link_ok`) | likespa (columns + UI) | Added by likespa migration; staff edit via `core.user_edit.*` |
| `base_users` procedure restricts (`RESTRICT_TYPES*`, `RESTRICT_PROCS`) | procedures (columns + UI) | Added by procedures migration; only when procedures enabled |
| `base_staff_bracelets` | terminals | Staff NFC UID ↔ user (no column on `base_users`) |
| `base_clients`, `base_client_statuses`, `base_shopusers_tags`, `base_client_tag_links`, `base_client_channels` | clients | CRM clients; login = phone (optional — any channel is enough); `base_client_channels` = Telegram/Max/WhatsApp/email/extra phones; `base_client_statuses.block_online` gates storefront booking |
| `base_clients` source columns (`source_id`, `source_descr`, `referred_by_client_id`) | acquisition (ALTER + UI) | Soft; only when acquisition enabled; not in acquisition `tables[]` |
| `base_promo_sources`, `base_calls` | acquisition | First-touch channels + inquiry/call journal |
| `base_account_moves.ID_SOURCE` | acquisition (ALTER + UI) | Soft ROMI tag on DPS; not owned by acquisition |
| `base_crm_companies`, `base_crm_company_contacts`, `base_crm_pipelines`, `base_crm_stages`, `base_crm_deals`, `base_crm_deal_contacts`, `base_crm_custom_fields`, `base_crm_custom_values`, `base_crm_notes`, `base_crm_stage_history`, `base_crm_links` | crm | Sales CRM (companies/deals/leads via pipeline kind); contacts stay in `base_clients`; `base_crm_links` = deal/company → foreign records (tickets in `base_news`). Not `base_suppliers` / `base_orders`. |
| `base_bitrix_id_map`, `base_bitrix_sync_state`, `base_bitrix_outbox`, `base_bitrix_webhook_events` | bitrix | Bitrix24 dual-run bridge maps |
| `base_amocrm_id_map`, `base_amocrm_sync_state`, `base_amocrm_outbox`, `base_amocrm_webhook_events` | amocrm | AmoCRM/Kommo dual-run bridge maps |
| `base_clientics_id_map`, `base_clientics_sync_state`, `base_clientics_outbox`, `base_clientics_webhook_events` | clientics | Clientics dual-run bridge (clients/appointments; optional WaitingList→CRM) |
| `base_categories`, `base_products`, `base_product_relations` | catalog | Categories / products / relations. Column `base_products.onec_id` is written via `terminals` (product edit section); catalog create/update leave it untouched so values survive module disable. |
| `base_units` | units | UoM dictionary |
| `base_suppliers` | suppliers | Suppliers dictionary |
| `base_stocks` | stocks | Warehouses + leftovers |
| `base_acts2_in` / `base_acts2_out` / `base_good_sticker_*` | stickers | Receipt/writeoff acts + sticker lines |
| `base_good_defaults` | norms | Procedure → material norms |
| `base_laundry_accounts` / `base_laundry_item_types` / `base_laundry_moves` / `base_laundry_shipments*` / `base_laundry_returns*` | laundry | Wash-cycle linen SoT (ship/accept by type) |
| `base_term_scans` | likespa | Terminal scans / promoter conversion (disableable) |
| `base_staff_bracelets` / `base_comments` / `base_news` (usage) | likespa (staff debts scan) | No owned tables; cron/UI posts 1C folio debts as comments when `staff_debts_enabled` |
| `base_site_hosts` | sites | Public domains: theme, home promo, catalog root(s), phone; resolve by `HTTP_HOST` |
| `base_promopages` | sites | Public promo / LP pages (home content via `base_site_hosts.promo_id`) |
| `base_page_categories` | sites | Site articles tree; `site_host_id` (0 = all hosts) |
| `base_redirects` | sites | Site URL redirects; `site_host_id` (0 = all hosts) |
| ~~`base_system`~~ (legacy) | — | Settings moved to ini: company → `[app] instance`; SEO/tel → `[sites]`; work hours → `[calendar]`; flyer/promo/free ticket → `[likespa]`; day bounds → `[audio_reports]`. Copied by `*_from_base_system` migrations; the table is no longer owned by any module — drop it as a foreign table in `/db` after migrate |
| `base_terminals`, `base_bracelets`, `base_terminals_activations` | terminals | Device registry + locker bracelet UIDs (disableable) |
| `base_termloads`, `base_fullstats` | terminals | Therm occupancy / ticket visits |
| `base_term_orders` | terminals (legacy) | Old Volga Termy feed; table may remain, UI/import removed |
| `base_client_onec_bindings` | terminals | Current 1C ticket ↔ client (ephemeral TTL; no ALTER `base_clients`) |
| `base_staff_bracelets` | terminals | Permanent staff NFC UID ↔ `base_users.id` (no ALTER `base_users`) |
| `base_staff_report_profiles` | staff_reports | Per-position quality report config (traffic lights) |
| `base_tables` | tables | Massage tables; optional `ID_TABLE` |
| `base_records*` (split by domain) | audio / audio_dicts / audio_pipeline / audio_reports / audio_llm | Shared naming; ownership by domain |
| `base_records_badges`, `base_badge_assignments`, `base_records_reviews`, `base_records_balances`, `base_audio_video_effects` | audio | Badges, assignments; storage settings in `cfg/modules/audio.ini`; records disabled by video absence alerts (restored by an accepted appeal) |
| `base_records_coverage_profiles` | audio_pipeline | Per-status / per-staff coverage overrides |
| `base_asr_leases` | audio_pipeline | One active ASR lease per record across pull + push channels |
| `base_asr_push_workers` | audio_pipeline | Legacy push ASR workers; copied into `base_edge_hosts` (not read anymore) |
| `base_records_probe_runs` | audio_pipeline | Explicit ffprobe PLAYTIME/VOLUME probe journal (agent/LongJob only; not normal ASR) |
| `base_records_speakers`, `base_records_speaker_segments`, `base_records_cue_speakers` | audio_diarize | Per-record diarization (local speaker ids, embeddings, cue map) |
| `base_records_speaker_profiles` | audio_diarize | Staff voiceprint centroids (WeSpeaker embeddings) for match / orphan bind |
| `base_records_speaker_samples` | audio_diarize | Manual (and later diarize) enrollment clips → embed → profile build |
| `base_diarize_leases`, `base_diarize_requests` | audio_diarize | Edge embed leases (+ legacy file-diarize queue; product path is ASR-DIA) |
| `base_asr_dia_runs` | audio_diarize | ASR-DIA journal (Nemotron → ASR → CAM++; enrollment + matched ID) |
| `base_records_llm_studies`, `base_records_llm_study_results` | audio_llm | LLM studies over transcripts + cyclic templates |
| `base_script_coach_runs`, `base_script_coach_artifacts`, `base_script_coach_proposals` | audio_script_coach | Day investigation jobs + LLM dict enrichment proposals |
| `base_records_phrases`, `base_records_phrase_days` | audio_phrases | Top N-gram harvest (coach is soft successor for script enrichment) |
| `base_events` / `base_event_items` | calendar | Visit/block shell + planned basket |
| `base_event_bonus` | bonuses | Bonus intent per calendar visit |
| `base_cabinets` | cabinets | Rooms; staff `skin`; stocks `ID_CABINET` |
| `base_resources` / `base_product_resources` / `base_resource_allocations` | resources | Shared bookable capacity; product requirements; order/event/procedure occupancy |
| `base_workweeks` | workweeks | Shifts |
| `base_procent_history` | rates | Specialist pay rates |
| `base_staff_plans` | rates | Month plan ₽, norm ₽/hour, bonus tiers JSON |
| `base_procedures*` | procedures | Types + journal + `base_procedure_restores` schema; restore UI/settlement in likespa |
| `base_media_attachments` / `base_media_requirements` | media | Image attachments by owner table+id; photo requirements on type/product scopes |
| `base_payments` | payments | Staff settlements |
| `base_payment_move_links` | payments | Multi-allocation payments ↔ ledger moves |
| `base_bonus_moves` | bonuses | Client loyalty ledger (credit/debit, event settle) |
| `base_certificates` / `base_certificate_ledger` | certificates | Gift certificates + money ledger (issue/spend/expire/undo) |
| `base_orders.ID_SERT` | orders (column) / certificates (write) | Optional link to certificate used on checkout |
| `base_telegram_sends` | bots | Outbound TG/Max queue (`BOT_SOURCE` 1=client, 2=staff) |
| `base_bot_links` | bots | Messenger chat ids for staff/clients (no ALTER `base_users` / `base_clients`) |
| `base_bot_chat_state` | bots | Per-chat FSM pending mode for bot plugins |
| `base_mail_sends` | mail | Outbound email queue (`STATUS` 1 pending, 2 sent, 3 error, 100 skipped) |
| `base_bot_chats` | bots | Known messenger group chats (targets for notify / staff bots) |
| `base_notify_rules` | notify | Notification/confirm rules (conditions, targets, stages, template) |
| `base_notify_deliveries` | notify | Delivery journal (`CLIENT_ID` about / `RCPT_KIND`+`RCPT_ID` to whom) |
| `base_notify_tasks` | notify | Confirmation tasks (stages, outcomes, bot message edit) |
| `base_fit_recipes` / `base_fit_recipe_items` | wellness | Own recipes + composition; synthetic food via `base_fit_foods.ID_SOURCE_RECIPE` |
| `base_vacations` | vacations | Leave register |
| `base_fit_*` / `base_healths_*` | wellness | Personal food/workouts/weight/goals + health diaries (strict `ID_USER`) |
| `base_accounts*` / `base_account_*` | ledger | Accounts, moves, reconciliations (day board UI: optional module `recons`, no own tables) |
| `base_remittance_agreements` / `base_remittance_batches` | remittances | Agency remittance contracts + per-move reconcile batches (UI on `/ledger/moves`) |
| `base_funds` / `base_fund_movements` | funds | Funds tree + movements (incl. ACCRUE / transfers) |
| `base_fund_rules` / `base_fund_type_map` | funds | Accrual rules; ledger type → fund map |
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
| `base_doc_defs` / `base_doc_def_versions` | docs | Document definitions (kind, trigger, scope) + immutable schema/template versions |
| `base_doc_instances` / `base_doc_field_index` | docs | Filled documents incl. medcards (`PAYLOAD` JSON, frozen print, QR hashes) + narrow search index |
| `base_doc_proposals` | docs | Pending autofill values (audio/LLM/import) awaiting staff accept |
| `base_clients` / `base_procedures` / `base_events` / `base_records` (read) | docs | Merge context + spawn triggers; no medical columns added to these tables |
| `base_video_workers`, `base_video_models`, `base_video_posts`, `base_video_cameras`, `base_video_jobs`, `base_video_alerts`, `base_video_detections`, `base_video_train_sessions`, `base_video_appeals` | video | Posts (N cams), schedule in SETTINGS_JSON, YOLO jobs, interactive train, absence alerts (STAFF_ID = on-duty via workweeks) + appeals; media under `var/video/` |
| `base_video_label_projects`, `base_video_label_images` | video_label | LabelForge-style projects; requires `video` for storage/infer |
| `base_cron_job_state`, `base_cron_runs` | core | Platform cron: per-job state + run journal (`/cron/tick`, UI `/cron`) |
| `base_likes` | core | News / comment likes (`LikesRepository`) |
| `base_user_login_links` | core | One-time staff login links (`php bin/freesaas.php user login-link`) |
| `base_oauth_clients`, `base_oauth_codes`, `base_oauth_tokens` | llm | OAuth2 clients / codes / tokens for MCP `/llm/mcp` and external agents |
| `base_assistant_pending`, `base_assistant_dialog` | assistant | Pending write-tool confirmations; shared conversation per staff user (web + bots) |
| `base_bot_webhook_updates`, `base_bot_link_codes`, `base_bot_link_throttle` | bots | Webhook update dedupe, one-time link codes, link attempt throttle |
| `base_gcal_tokens`, `base_gcal_task_links`, `base_gcal_sync_queue` | gcal | Per-staff Google OAuth tokens, ticket ↔ calendar event links, sync queue |
| `base_yclients_id_map`, `base_yclients_sync_state` | yclients | YClients pull bridge: external id map + sync cursor |
| `base_bank_integrations` | banks | Per-environment bank integration credentials (sandbox / prod) |
| `base_acquiring_payments`, `base_acquiring_webhook_log` | acquiring | Online payments by provider + raw webhook journal |
| `base_client_portal_sessions`, `base_client_portal_resets` | client_portal | Client cabinet session journal + password reset tokens |
| `base_course_programs`, `base_course_modules`, `base_course_lessons`, `base_course_assets`, `base_course_enrollments`, `base_course_progress`, `base_course_media_hits` | courses | LMS programs → modules → lessons, protected media, enrollments and progress |
| `base_doc_fill_templates` | docs | Clinic and per-doctor default phrases for visit-sheet fields |
| `base_egisz_outbox`, `base_egisz_client_ids`, `base_egisz_staff`, `base_egisz_icd10`, `base_egisz_worker_jobs` | egisz | SEMD outbox journal, patient / staff identity (SNILS, OID), ICD-10 dictionary, edge worker job queue |
| `base_procedure_types` | procedures | Procedure type dictionary |
| `base_laundry_shipment_lines`, `base_laundry_return_lines` | laundry | Lines of linen shipments / returns |
| `base_qr_token_claims` | stickers | Atomic one-shot QR operation tokens (rolls back with the writeoff txn) |
| `base_fitlogs` | wellness | Workout log (strict `ID_USER`) |
| `base_backup_runs`, `base_backup_changes` | backups | Backup run journal + CDC change log for hourly increments |
| `base_demo_stand` | demo | Demo stand marker (single row); seed/wipe refuse to run without it |

Session keys: `user_id`, `login`, `user_name`, `permissions`, `status_id`, `status_flags`.

When adding tables: declare them in the owning module’s `module.json` `tables` and create via additive migrations.
