# Speech polls (`poll_speech`)

Product module: oral guest feedback without SMS. Admins mark «review asked» on calendar procedures; after Re-®, score phrases from an `audio_dicts` dictionary fill `base_poll_invites.DATA_OPTS` (`source=speech`). Catalog maturity: `alpha`.

## Enable

```bash
php bin/freesaas.php module enable poll_speech --accept-risk --format=json
# Copy modules/poll_speech/config.example.ini → cfg/modules/poll_speech.ini if missing.

# One-shot: oral poll + dict 50 + settings + Oct–Nov speech backfill
php bin/data-migrate.php --module=poll_speech --id=seed_and_backfill_oct_nov
# Optional period override (default 2026-10-01 … 2026-11-30):
POLL_SPEECH_BACKFILL_FROM=2026-10-01 POLL_SPEECH_BACKFILL_TO=2026-11-06 \
  php bin/data-migrate.php --module=poll_speech --id=seed_and_backfill_oct_nov
```

Step-by-step (same pieces, manual):

```bash
php bin/data-migrate.php --module=poll_speech --id=oral_poll_10
php bin/data-migrate.php --module=poll_speech --id=seed_dict50_scores
php bin/data-migrate.php --module=poll_speech --id=repoint_oral_poll1_to_55   # if old marks on poll #1
# then in PHP/agent: PollSpeechDictEnrich::enrichRange + PollSpeechFiller::fillRangeFromSpeech
```

Or `[modules] poll_speech=1` in `cfg/config.ini`.

Permissions: `poll_speech.view`, `poll_speech.edit` (settings: `SUPERADMIN_ACCESS`).

## Dependencies

- Requires: `core`, `polls`, `poll_invites`
- Soft: `audio`, `audio_dicts`, `audio_pipeline`, `audio_reports`, `calendar`, `procedures`

## Flow

1. Calendar chip «собран отзыв» (if `oral_btn_enabled=1`) → `POST /poll-invites/oral-asked` → invite with empty `CODE`.
2. Re-® emits `audio_reports.day.analyzed`.
3. `PollSpeechFiller` searches cabin audio around **invite `DT_CREATE` ± `window_sec`** (default 900 / ±15 min): procedure **executor** cabin first, then linked/floor admin. Picks the cabin record **closest** to the oral-ask moment; each record is claimed once so overlapping procedures with the same floor admin do not share one transcript.
   **Score ratchet (per record):** among transcript extracts + dict keys, the **highest** score wins («10» + «хорошо»→8 + «три»→3 ⇒ **10**); softer keys never lower an explicit digit. A fill needs a non-empty ask→reply snippet in `DATA_TEXT`.
   On save, denormalizes procedure staff + digits: `ID_PROC_OPERATOR` / `ID_PROC_ADMIN` from the procedure, `SCORE_STAFF` / `SCORE_SERVICE` (1–10) from answer maps, and refreshes `RESP_USERS` as `#executor#,#admin#`.
   **No spoken score / no dialogue → no fill** (empty oral polls may be dropped when cabin audio exists). Day rebuild: `POST /poll-speech/fill-day` with `day=YYYY-MM-DD` + `recreate=1` (superadmin) or `PollSpeechFiller::recreateAllFromSpeech(['date'=>$day])` (preserves original oral-ask `DT_CREATE`; scopes deletes/creates to that procedure day).
   Cleanup key-only junk (safe): `php bin/data-migrate.php --module=poll_speech --id=purge_without_snippet`.
   Skips fills younger than 12h (`POLL_SPEECH_PURGE_MIN_AGE_HOURS`) and any invite whose cabin `record=` still has empty `TRANSCRIPTION` (ASR pending). Tries snippet backfill before DELETE.
   Period backfill without wiping: `PollSpeechFiller::fillRangeFromSpeech(['from'=>'YYYY-MM-DD','to'=>'YYYY-MM-DD'])` + `PollSpeechDictEnrich::enrichRange([...])`. One cabin `record` fills at most one invite (`dedupeSharedSpeechRecords()`).
4. `admin_field=both` (default): mark may be clicked by a supervisor (`ID_OPERATOR_LINKED`) while ASR is on the floor `proc_admin`. List/BI use procedure executor/admin, not the linked marker.

Instance default dictionary: **50** («Отзывы 10»). Oral 10-point poll via migrator `oral_poll_10`. Legacy oral marks on poll `#1` → `#55`: migrator `repoint_oral_poll1_to_55`. Dict mining: `PollSpeechDictEnrich` (transcripts near invite create ±15 min).

## Human UI

Day list / fill / recreate live on **`/poll-invites`** (hub). This module keeps settings + filler API.

| Path | Permission |
|------|------------|
| `/poll-speech/settings` | `poll_speech.edit` + superadmin |
| `/polls/settings` | same fields via EventBus `polls.settings.sections` / `.save` |
| `/poll-speech/fill-day` | `poll_speech.edit` (POST); recreate scopes to posted `day`; `ret=/poll-invites…` returns to invites list |

## Agent JSON

Surface B (staff session / MCP). Settings require `SUPERADMIN_ACCESS`. `settings.save` is a **patch**: omit keys to keep current values (checkbox-safe). Live ini: `cfg/modules/poll_speech.ini` (from `config.example.ini`).

| Op | Method | Path | Notes |
|----|--------|------|-------|
| `poll_speech.settings` | GET | `/poll-speech/settings` | returns `cfg` |
| `poll_speech.settings.save` | POST | `/poll-speech/settings` | patch fields → `cfg` |
| `poll_speech.fill_day` | POST | `/poll-speech/fill-day` | `day=YYYY-MM-DD`; `recreate=1` superadmin |
| (list) | GET | `/poll-invites` | day hub — use `poll_invites.list` |

## Hooks

- Subscribes: `calendar.timeline.enrich`, `audio_reports.day.analyzed`, `bi.datasets`
- Uses invite API from `poll_invites` (does not own `base_poll_invites`; score columns live on that table via poll_invites migration `003_speech_staff_scores`)

## BI

Dataset `poll_speech_scores`: period `DT_COMPLETED`, filters by executor/admin/poll, fields `score_staff` / `score_service` (agg), joins `users_staff` + `procedures`. Use for master rating averages.

## Notes

- Do not treat `base_records.KEY_TYPES` as scores — use `base_records_instances`.
- Ask phrases in dict 50 stay unmapped; only `key_score_map` keys fill answers.
- Speech analytics core stays generic; this module is the product channel.
- Oral invites list / scores UI: `/poll-invites` (not a separate speech path).
