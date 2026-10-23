# Demo mutation audit

Generated: 2026-10-23T20:21:14+04:00

Mutating routes inventoried: **352**

| Method | Path | Module | Allowlisted |
|---|---|---|---|
| POST | `/account-move-types` | ledger |  |
| POST | `/account-move-types/new` | ledger |  |
| POST | `/account-move-types/{id}/edit` | ledger |  |
| POST | `/accounts` | ledger |  |
| POST | `/accounts/new` | ledger |  |
| POST | `/accounts/{id}/edit` | ledger |  |
| POST | `/agent/bootstrap` | core | yes |
| POST | `/agent/bots/link-code` | bots | yes |
| POST | `/agent/bots/settings` | bots | yes |
| POST | `/agent/bots/unlink` | bots | yes |
| POST | `/agent/bots/webhook/subscribe` | bots | yes |
| POST | `/agent/db/check` | core | yes |
| POST | `/agent/db/data-migrate/run` | core | yes |
| POST | `/agent/db/dump` | core | yes |
| POST | `/agent/db/dump/delete` | core | yes |
| POST | `/agent/db/migrate/run` | core | yes |
| POST | `/agent/db/optimize` | core | yes |
| POST | `/agent/db/repair` | core | yes |
| POST | `/agent/db/restore` | core | yes |
| POST | `/agent/gcal/queue/drain` | gcal | yes |
| POST | `/agent/gcal/settings` | gcal | yes |
| POST | `/agent/llm/ping` | llm | yes |
| POST | `/agent/llm/tokens` | llm | yes |
| POST | `/agent/llm/tokens/delete` | llm | yes |
| POST | `/agent/modules/{id}/disable` | core | yes |
| POST | `/agent/modules/{id}/enable` | core | yes |
| POST | `/agent/modules/{id}/install` | core | yes |
| POST | `/agent/modules/{id}/uninstall` | core | yes |
| POST | `/agent/rescue` | core | yes |
| POST | `/agent/update/apply` | core | yes |
| POST | `/agent/update/resolve` | core | yes |
| PATCH | `/api1c.php` | likespa |  |
| POST | `/api1c.php` | likespa |  |
| PUT | `/api1c.php` | likespa |  |
| PATCH | `/api1c.php/{*path}` | likespa |  |
| POST | `/api1c.php/{*path}` | likespa |  |
| PUT | `/api1c.php/{*path}` | likespa |  |
| POST | `/asr/voice-transcribe/file` | asr |  |
| POST | `/asr/voice-transcribe/worker` | asr |  |
| POST | `/assistant-groups/settings` | assistant_groups |  |
| POST | `/assistant-groups/webhook/max` | assistant_groups |  |
| POST | `/assistant/chat` | assistant |  |
| POST | `/assistant/clear` | assistant |  |
| POST | `/assistant/confirm` | assistant |  |
| POST | `/assistant/eval` | assistant |  |
| POST | `/assistant/fab/hide` | assistant |  |
| POST | `/assistant/feedback` | assistant |  |
| POST | `/assistant/prefs` | assistant |  |
| POST | `/audio` | audio |  |
| POST | `/audio/api/badge` | audio |  |
| POST | `/audio/api/dict` | audio |  |
| POST | `/audio/asr/chunk-claim` | audio_pipeline |  |
| POST | `/audio/asr/chunk-result` | audio_pipeline |  |
| POST | `/audio/asr/claim` | audio_pipeline |  |
| POST | `/audio/asr/result` | audio_pipeline |  |
| POST | `/audio/assignments/new` | audio |  |
| POST | `/audio/assignments/{id}/edit` | audio |  |
| POST | `/audio/badges` | audio |  |
| POST | `/audio/calendar` | audio_reports |  |
| POST | `/audio/calendar/reparse` | audio_reports |  |
| POST | `/audio/collections` | audio_reports |  |
| POST | `/audio/dicts` | audio_dicts |  |
| POST | `/audio/dicts/new` | audio_dicts |  |
| POST | `/audio/dicts/{id}/edit` | audio_dicts |  |
| POST | `/audio/ingest` | audio |  |
| POST | `/audio/jobs/collect` | audio_pipeline |  |
| POST | `/audio/jobs/ingest` | audio_pipeline |  |
| POST | `/audio/jobs/ingest/run` | audio_pipeline |  |
| POST | `/audio/jobs/stem` | audio_pipeline |  |
| POST | `/audio/jobs/stem/run` | audio_pipeline |  |
| POST | `/audio/keys` | audio_dicts |  |
| POST | `/audio/keys/new` | audio_dicts |  |
| POST | `/audio/keys/{id}/edit` | audio_dicts |  |
| POST | `/audio/llm-studies` | audio_llm |  |
| POST | `/audio/llm-studies/new` | audio_llm |  |
| POST | `/audio/llm-studies/work` | audio_llm |  |
| POST | `/audio/llm-studies/{id}` | audio_llm |  |
| POST | `/audio/phrases` | audio_phrases |  |
| POST | `/audio/phrases/day` | audio_phrases |  |
| POST | `/audio/phrases/{id}/status` | audio_phrases |  |
| POST | `/audio/review-moderate` | audio_llm |  |
| POST | `/audio/script-coach/day` | audio_script_coach |  |
| POST | `/audio/script-coach/proposal/{id}` | audio_script_coach |  |
| POST | `/audio/script-coach/run/{id}` | audio_script_coach |  |
| POST | `/audio/script-coach/work` | audio_script_coach |  |
| POST | `/audio/scripts` | audio_dicts |  |
| POST | `/audio/scripts/import` | audio_dicts |  |
| POST | `/audio/scripts/new` | audio_dicts |  |
| POST | `/audio/scripts/{id}` | audio_dicts |  |
| POST | `/audio/scripts/{id}/copy` | audio_dicts |  |
| POST | `/audio/scripts/{id}/parts` | audio_dicts |  |
| POST | `/audio/settings` | audio |  |
| POST | `/audio/summaries` | audio_reports |  |
| POST | `/audio/summaries/new` | audio_reports |  |
| POST | `/audio/summaries/work` | audio_reports |  |
| POST | `/audio/summaries/{id}` | audio_reports |  |
| POST | `/audio/tags` | audio_dicts |  |
| POST | `/audio/tags/new` | audio_dicts |  |
| POST | `/audio/tags/{id}/edit` | audio_dicts |  |
| POST | `/audio/trashes` | audio_dicts |  |
| POST | `/audio/trashes/new` | audio_dicts |  |
| POST | `/audio/trashes/{id}/edit` | audio_dicts |  |
| POST | `/audio/voice-transcribe/file` | asr |  |
| POST | `/audio/voice-transcribe/worker` | asr |  |
| POST | `/audio/{id}` | audio |  |
| POST | `/banks/connections/{id}` | banks |  |
| POST | `/banks/connections/{id}/sync` | banks |  |
| POST | `/banks/jobs/sync` | banks |  |
| POST | `/banks/settings` | banks |  |
| POST | `/bi/families/new` | bi |  |
| POST | `/bi/families/{id}` | bi |  |
| POST | `/bi/run` | bi |  |
| POST | `/bi/s/{token}` | bi |  |
| POST | `/bi/shares` | bi |  |
| POST | `/bi/work` | bi |  |
| POST | `/bonuses` | bonuses |  |
| POST | `/bonuses/events/{id}/apply` | bonuses |  |
| POST | `/bonuses/new` | bonuses |  |
| POST | `/bonuses/{id}/edit` | bonuses |  |
| POST | `/booking/book` | booking |  |
| POST | `/booking/cancel` | booking |  |
| POST | `/booking/new` | booking |  |
| POST | `/booking/settings` | booking |  |
| POST | `/bots/message` | bots |  |
| POST | `/bots/sends/test` | bots |  |
| POST | `/bots/sends/work` | bots |  |
| POST | `/bots/settings` | bots |  |
| POST | `/bots/webhook/max/{channel}` | bots |  |
| POST | `/bots/webhook/telegram/{channel}` | bots |  |
| POST | `/cabinets` | cabinets |  |
| POST | `/cabinets/new` | cabinets |  |
| POST | `/cabinets/{id}/edit` | cabinets |  |
| POST | `/calendar` | calendar |  |
| POST | `/calendar/day/{date}` | calendar |  |
| POST | `/calendar/events/new` | calendar |  |
| POST | `/calendar/events/{id}/edit` | calendar |  |
| POST | `/calendar/nazop` | calendar |  |
| POST | `/calendar/settings` | calendar |  |
| POST | `/calendar/week/{date}` | calendar |  |
| POST | `/cart` | sites |  |
| POST | `/cart/checkout` | sites |  |
| POST | `/catalog` | catalog |  |
| POST | `/catalog/{id}` | catalog |  |
| POST | `/categories/new` | catalog |  |
| POST | `/categories/{id}/edit` | catalog |  |
| POST | `/client-mcp/settings` | client_mcp |  |
| POST | `/client-statuses/new` | clients |  |
| POST | `/client-statuses/{id}/edit` | clients |  |
| POST | `/client-tags` | clients |  |
| POST | `/client-tags/new` | clients |  |
| POST | `/client-tags/{id}/edit` | clients |  |
| POST | `/clients` | clients |  |
| POST | `/clients/new` | clients |  |
| POST | `/clients/{id}/edit` | clients |  |
| POST | `/contracts/action` | contracts |  |
| POST | `/contracts/settings` | contracts |  |
| POST | `/core/jobs/habits-reminders` | core |  |
| POST | `/cron` | core |  |
| POST | `/cron/tick` | core | yes |
| POST | `/dashboard` | core |  |
| POST | `/db` | core |  |
| POST | `/documents` | docs |  |
| POST | `/documents/defs/import` | docs |  |
| POST | `/documents/defs/new` | docs |  |
| POST | `/documents/defs/{id}/edit` | docs |  |
| POST | `/documents/new` | docs |  |
| POST | `/documents/qr/{hash}` | docs |  |
| POST | `/documents/settings` | docs |  |
| POST | `/documents/{id}/edit` | docs |  |
| POST | `/documents/{id}/print` | docs |  |
| POST | `/fund-movements` | funds |  |
| POST | `/fund-movements/new` | funds |  |
| POST | `/fund-movements/{id}/edit` | funds |  |
| POST | `/funds` | funds |  |
| POST | `/funds/new` | funds |  |
| POST | `/funds/{id}/edit` | funds |  |
| POST | `/gcal/enqueue` | gcal |  |
| POST | `/gcal/settings` | gcal |  |
| POST | `/gcal/work` | gcal |  |
| POST | `/laundry/accept` | laundry |  |
| POST | `/laundry/accounts` | laundry |  |
| POST | `/laundry/accounts/new` | laundry |  |
| POST | `/laundry/accounts/{id}/edit` | laundry |  |
| POST | `/laundry/inventory` | laundry |  |
| POST | `/laundry/settings` | laundry |  |
| POST | `/laundry/ship` | laundry |  |
| POST | `/laundry/types` | laundry |  |
| POST | `/laundry/types/new` | laundry |  |
| POST | `/laundry/types/{id}/edit` | laundry |  |
| POST | `/ledger/moves` | ledger |  |
| POST | `/ledger/moves/attach-act` | ledger |  |
| POST | `/ledger/moves/new` | ledger |  |
| POST | `/ledger/moves/{id}/edit` | ledger |  |
| POST | `/ledger/transfers/new` | ledger |  |
| POST | `/ledger/transfers/{id}/edit` | ledger |  |
| POST | `/likes/mark-viewed` | core |  |
| POST | `/likespa/restores` | likespa |  |
| POST | `/likespa/restores/new` | likespa |  |
| POST | `/likespa/restores/{id}/edit` | likespa |  |
| POST | `/likespa/settings` | likespa |  |
| POST | `/likespa/staff-debts` | likespa |  |
| POST | `/llm/api/tools/call` | llm |  |
| POST | `/llm/chat` | llm |  |
| DELETE | `/llm/mcp` | llm |  |
| POST | `/llm/mcp` | llm |  |
| POST | `/llm/oauth` | llm |  |
| POST | `/llm/oauth/new` | llm |  |
| POST | `/llm/oauth/{id}` | llm |  |
| POST | `/llm/tokens` | llm |  |
| POST | `/llm/tokens/new` | llm |  |
| POST | `/llm/tokens/{id}` | llm |  |
| POST | `/locale` | core | yes |
| POST | `/login` | core | yes |
| POST | `/logout` | core | yes |
| DELETE | `/mcp` | client_mcp |  |
| POST | `/mcp` | client_mcp |  |
| POST | `/modules` | core |  |
| POST | `/modules/{id}` | core |  |
| POST | `/news-statuses/new` | core |  |
| POST | `/news-statuses/{id}/edit` | core |  |
| POST | `/news/new` | core |  |
| POST | `/news/{id}` | core |  |
| POST | `/news/{id}/edit` | core |  |
| POST | `/norms` | norms |  |
| POST | `/norms/new` | norms |  |
| POST | `/norms/{id}/edit` | norms |  |
| POST | `/oauth/authorize` | llm |  |
| POST | `/oauth/register` | llm |  |
| POST | `/oauth/revoke` | llm |  |
| POST | `/oauth/token` | llm |  |
| POST | `/orders` | orders |  |
| POST | `/orders/settings` | orders |  |
| POST | `/orders/{id}/edit` | orders |  |
| POST | `/payments` | payments |  |
| POST | `/payments/move-links/{moveId}` | payments |  |
| POST | `/payments/new` | payments |  |
| POST | `/payments/settings` | payments |  |
| POST | `/payments/{id}/edit` | payments |  |
| POST | `/poll-invites` | poll_invites |  |
| POST | `/poll-invites/new` | poll_invites |  |
| POST | `/poll-invites/oral-asked` | poll_invites |  |
| POST | `/poll-invites/{id}/edit` | poll_invites |  |
| POST | `/poll-invites/{id}/fill` | poll_invites |  |
| POST | `/polls` | polls |  |
| POST | `/polls/new` | polls |  |
| POST | `/polls/settings` | polls |  |
| POST | `/polls/{id}/edit` | polls |  |
| POST | `/procedure-types` | procedures |  |
| POST | `/procedure-types/new` | procedures |  |
| POST | `/procedure-types/{id}/edit` | procedures |  |
| POST | `/procedures` | procedures |  |
| POST | `/procedures/new` | procedures |  |
| POST | `/procedures/{id}/edit` | procedures |  |
| POST | `/products/new` | catalog |  |
| POST | `/products/{id}/edit` | catalog |  |
| POST | `/qr2.php` | stickers |  |
| POST | `/rates` | rates |  |
| POST | `/rates/new` | rates |  |
| POST | `/rates/skill` | rates |  |
| POST | `/rates/{id}/edit` | rates |  |
| POST | `/settings` | core |  |
| POST | `/shop` | sites |  |
| POST | `/shop/c/{id}` | sites |  |
| POST | `/shop/{id}` | sites |  |
| POST | `/sites/hosts` | sites |  |
| POST | `/sites/hosts/new` | sites |  |
| POST | `/sites/hosts/{id}/edit` | sites |  |
| POST | `/sites/pages` | sites |  |
| POST | `/sites/pages/new` | sites |  |
| POST | `/sites/pages/{id}/edit` | sites |  |
| POST | `/sites/promopages` | sites |  |
| POST | `/sites/promopages/new` | sites |  |
| POST | `/sites/promopages/{id}/edit` | sites |  |
| POST | `/sites/redirects` | sites |  |
| POST | `/sites/redirects/new` | sites |  |
| POST | `/sites/redirects/{id}/edit` | sites |  |
| POST | `/sites/seo` | sites |  |
| POST | `/sites/settings` | sites |  |
| POST | `/splash/ack` | core |  |
| POST | `/staff-reports/generate` | staff_reports |  |
| POST | `/staff-reports/profiles` | staff_reports |  |
| POST | `/statuses/new` | core |  |
| POST | `/statuses/{id}/edit` | core |  |
| POST | `/stickers/ins` | stickers |  |
| POST | `/stickers/ins/new` | stickers |  |
| POST | `/stickers/ins/print` | stickers |  |
| POST | `/stickers/ins/{id}/edit` | stickers |  |
| POST | `/stickers/outs` | stickers |  |
| POST | `/stickers/receipts` | stickers |  |
| POST | `/stickers/receipts/new` | stickers |  |
| POST | `/stickers/receipts/{id}/edit` | stickers |  |
| POST | `/stickers/settings` | stickers |  |
| POST | `/stickers/writeoff` | stickers |  |
| POST | `/stickers/writeoffs` | stickers |  |
| POST | `/stickers/writeoffs/new` | stickers |  |
| POST | `/stickers/writeoffs/{id}/edit` | stickers |  |
| POST | `/stocks` | stocks |  |
| POST | `/stocks/new` | stocks |  |
| POST | `/stocks/{id}/edit` | stocks |  |
| POST | `/suppliers` | suppliers |  |
| POST | `/suppliers/new` | suppliers |  |
| POST | `/suppliers/{id}/edit` | suppliers |  |
| POST | `/t` | terminals |  |
| POST | `/tables` | tables |  |
| POST | `/tables/new` | tables |  |
| POST | `/tables/{id}/edit` | tables |  |
| POST | `/terminals` | terminals |  |
| POST | `/terminals/1c` | terminals |  |
| POST | `/terminals/1c/{mode}` | terminals |  |
| POST | `/terminals/bracelets` | terminals |  |
| POST | `/terminals/bracelets/new` | terminals |  |
| POST | `/terminals/bracelets/{id}/edit` | terminals |  |
| POST | `/terminals/floor` | terminals |  |
| POST | `/terminals/fullstats/{id}/edit` | terminals |  |
| POST | `/terminals/jobs/load` | terminals |  |
| POST | `/terminals/jobs/tickets` | terminals |  |
| POST | `/terminals/new` | terminals |  |
| POST | `/terminals/settings` | terminals |  |
| POST | `/terminals/{id}/edit` | terminals |  |
| POST | `/units` | units |  |
| POST | `/units/new` | units |  |
| POST | `/units/{id}/edit` | units |  |
| POST | `/updates` | updates |  |
| POST | `/updates/apply` | updates |  |
| POST | `/updates/check` | updates |  |
| POST | `/updates/settings` | updates |  |
| POST | `/users` | core |  |
| POST | `/users/new` | core |  |
| POST | `/users/{id}` | core |  |
| POST | `/users/{id}/edit` | core |  |
| POST | `/vacations` | vacations |  |
| POST | `/vacations/new` | vacations |  |
| POST | `/vacations/{id}/edit` | vacations |  |
| POST | `/video-label/new` | video_label |  |
| POST | `/video-label/{id}/edit` | video_label |  |
| POST | `/video-label/{id}/workspace` | video_label |  |
| POST | `/video/cameras` | video |  |
| POST | `/video/cameras/new` | video |  |
| POST | `/video/cameras/{id}/edit` | video |  |
| POST | `/video/models` | video |  |
| POST | `/video/settings` | video |  |
| POST | `/video/worker/claim` | video |  |
| POST | `/video/worker/file` | video |  |
| POST | `/video/worker/frame` | video |  |
| POST | `/video/worker/hello` | video |  |
| POST | `/video/worker/result` | video |  |
| POST | `/workweeks` | workweeks |  |
| POST | `/workweeks/nazcab` | workweeks |  |
| POST | `/workweeks/nazop` | workweeks |  |
| POST | `/workweeks/new` | workweeks |  |
| POST | `/workweeks/settings` | workweeks |  |
| POST | `/workweeks/{id}/edit` | workweeks |  |

Re-run with `--url=https://demoru.freesaas.ru --cookie-jar=…` for live 403 checks.
