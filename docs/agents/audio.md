# Audio analytics

Product module `audio`: Enable for cabin audio recordings. Soft workweeks/clients/audio_dicts. Required by audio_dicts, pipeline, reports. Cabin audio stack foundation (messenger ASR is module asr). Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable audio --accept-risk --format=json
```

Or `[modules] audio=1` in `cfg/config.ini`.

Permissions: `audio.view`, `audio.edit`, `audio.badges`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `workweeks` >=0.1.0, `clients` >=0.1.0, `audio_dicts` >=0.1.0, `audio_pipeline` >=0.1.0, `bots` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_records` | Owned by `audio` |
| `base_records_badges` | Owned by `audio` |
| `base_badge_assignments` | Owned by `audio` |
| `base_records_balances` | Owned by `audio` |
| `base_records_reviews` | Owned by `audio` |

Storage/FTP settings: `cfg/modules/audio.ini` (`[storage]` / `[audio]`), not a DB table.

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

### `base_records.KEY_TYPES` (legacy column name)

Legacy column name: chip cache of dictionary hits, not “key types”. Do **not** rename/drop or recompute on read.

Denormalized chip cache written once by stem (`StemRunner`): `##dictId-diversity-instances##…` per dictionary that hit the record. PHP alias / UI: `key_dicts` / `parseKeyDicts()`. Readers: list/view chips, `SummaryRunner` (ids in the blob = **dictionary** ids). Rating/`SCORE` comes from scripts/collections, not this column.

## Human UI

- `/audio` — cabin records list / player.
- `/audio/badges`, `/audio/assignments` — dictophone badges and shift assignments.
- `/audio/settings` — storage/FTP (superadmin capability on route).

| Path | Permission |
|------|------------|
| `/audio` | `—` |
| `/audio/badges` | `—` |
| `/audio/assignments` | `—` |
| `/audio/settings` | `audio.view` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (17 ops).

| Op | Method | Path |
|----|--------|------|
| `audio.list` | GET | `/audio` |
| `audio.get` | GET | `/audio/{id}` |
| `audio.transcripts.batch` | GET | `/audio/transcripts` |
| `audio.activity.summary` | GET | `/audio/activity` |
| `audio.badges.list` | GET | `/audio/badges` |
| `audio.badges.write` | POST | `/audio/badges` |
| `audio.assignments.list` | GET | `/audio/assignments` |
| `audio.assignments.get_new` | GET | `/audio/assignments/new` |
| `audio.assignments.get` | GET | `/audio/assignments/{id}/edit` |
| `audio.assignments.create` | POST | `/audio/assignments/new` |
| `audio.assignments.update` | POST | `/audio/assignments/{id}/edit` |
| `audio.settings.get` | GET | `/audio/settings` |
| `audio.settings.save` | POST | `/audio/settings` |
| `audio.api.blocks` | GET | `/audio/api/blocks` |
| `audio.api.dict` | POST | `/audio/api/dict` |
| `audio.api.badge` | POST | `/audio/api/badge` |
| `audio.reviews.mark` | POST | `/audio/reviews/mark` |

Review queue («moderation»): pending = `base_records_reviews.DT_REVIEW < 1` (list via `audio.list` `review=pending`). Mark reviewed with `audio.reviews.mark` (`ids`, or `date` + optional `admin`). `audio_llm.review_moderate` only produces LLM analysis text and never changes status.

### Reading transcripts in bulk (`audio.transcripts.batch`)

`audio.list` returns metadata only and `audio.get` returns one record per call. To read a day or a set of records, use `audio.transcripts.batch` (route `GET /audio/transcripts`):

- **Selection:** `ids` (max 200, array or CSV; other filters ignored) or `date` (`YYYY-MM-DD`), optionally with `time_from` / `time_to` (`HH:MM`), `admin`, `unassigned`, `badge`, `review=pending|marked`, `disabled` (default `0` = all), `search`.
- **Context:** `context_before=N` (0..5) adds up to N preceding records of the same admin (or badge when unassigned) on the same day, flagged `context: true`. Context records are never repeated as selected records.
- **Output:** `items[]` with `time` (HH:MM:SS from `dt_recorded`), `admin_name`, `id_badge`, `review` (`pending|reviewed|none`), `lines` (total cleaned lines), and `transcription` (cleaned, same as `audio.get` with `include_transcription=1`). Lines look like `[MM:SS->MM:SS]text`; the offset is relative to the record start, and `dt_recorded` gives the wall-clock start.
- **Two independent completeness signals:**
  - *Selection:* `next_offset` is the index of the next selected record; `null` means every selected record was delivered **completely** (`selection_complete: true`).
  - *Transcript:* every item has `complete`, `line_from`, `line_to` (1-based, inclusive) and, when partial, `next_line_from`. The response has `transcripts_complete` (no partial item on the page).
- **Line paging:** a record that does not fit the budget is delivered in slices of whole lines (always at least one line, so the cursor moves). The cursor is `(offset, line_from)`: while a record is partial, `next_offset` stays on that same record and `next_line_from` is the first undelivered line. Call again with the same arguments, `offset=next_offset` and `line_from=next_line_from`. `line_from` applies to the first selected record of the page; context is not repeated when it is above 1.
- **Context:** context records are delivered whole or not at all. When the first record of a page is large, context gets at most 40% of the budget (nearest predecessors first); the rest is listed in `context_omitted[]`. Read any record in full with `ids=<id>` (and `line_from` to continue a long one) — this works for selected and context records alike.
- **Budget:** `limit` (1..10, default 5) selects records per call. `max_bytes` (4000..30000, default 12000) caps the response (a single line may exceed a tiny budget so the cursor always advances). Keep the defaults: 3–5 records with context fit in one answer, and larger results get cut by MCP clients. The in-system assistant compacts tool results above 12000 bytes into a summary, so anything above the default is only safe over MCP (about 30000 works for ChatGPT MCP).
- **Envelope:** `ok`, `total` (selected records), `returned` (items incl. context), `offset`, `line_from`, `limit`, `next_offset`, `next_line_from`, `selection_complete`, `transcripts_complete`, `context_omitted[]`, `bytes`, `max_bytes`, `missing_ids[]`, optional `hint`.

Recipe for a day audit: `audio.activity.summary date=… detail=totals with_ids=1` for the overview, `audio.list date=… review=pending` for the queue, then `audio.transcripts.batch date=… context_before=2` (defaults: 5 records per page) and follow `next_offset` / `next_line_from` until `selection_complete` is true. Do not raise `limit` or `max_bytes` to fetch more per call. Shift checks: add `admin=<id>` and `time_from` / `time_to`.

### Day summary by employee and hour (`audio.activity.summary`)

`GET /audio/activity?date=YYYY-MM-DD` answers "who worked when and how much was recognised" without reading transcripts. Per employee (totals plus `hours[]`, bucketed by the hour of `dt_recorded`):

- `records`, `transcribed`, `untranscribed`, `disabled`, `pending_review` — counts (disabled records are counted; use `disabled=1` to exclude them).
- `playtime_sec` — recorded audio length; `speech_sec` — sum of line durations of the cleaned transcript (garbage dropped, capped at record playtime); `speech_pct` = `speech_sec` / `playtime_sec`.
- `asr_records_pct` = `transcribed` / `records`; `asr_coverage_pct` = playtime of transcribed records / `playtime_sec`.
- `dict_hits` and `dicts{dictionary id: hits}` (legend in top-level `dicts`); a cell without `dicts` has no hits.
- `speakers{diarized_records, speaker_rows, talk_sec, staff_talk_sec, other_talk_sec, dominant_self|other|unmatched}` when diarization exists (`dominant_*` classify each record's dominant speaker against the record's employee); a cell without `speakers` has no diarized records.
- `record_ids` per hour cell with `with_ids=1` (up to 200 per cell; feed them to `audio.transcripts.batch` `ids`).

Options: `admin` (one employee), `time_from` / `time_to` (`HH:MM`, a shift), `disabled` (0 all, 1 active, 2 disabled), `detail=totals` (no hour cells; ids move to the employee level), `limit` / `offset` / `max_bytes` (same budget rules as above). Employees are ordered by record count and paged under the byte budget: follow `next_offset` until `selection_complete`. `totals` (whole selection) are only on the first page. An unassigned bucket appears as `id_admin: 0` with `unassigned: true`.

MCP tool names: `audio__transcripts__batch`, `audio__activity__summary` (see [connect-mcp.md](connect-mcp.md)). MCP clients cache tool schemas: reconnect after an update to see new ops and parameters.

Regression check: `php bin/check-audio-transcripts.php` (also in `bin/smoke.php`) rebuilds every transcript from the cursor and compares all lines with `audio.get` and with the stored source, and checks the summary against independent SQL. It skips when there are no transcripts.

```bash
curl -sS -b jar.txt 'https://example/audio?format=json'
curl -sS -b jar.txt 'https://example/audio/badges?format=json'
curl -sS -b jar.txt 'https://example/audio/transcripts?date=2026-07-11&context_before=2&limit=5&format=json'
curl -sS -b jar.txt 'https://example/audio/transcripts?ids=296758&line_from=13&format=json'
curl -sS -b jar.txt 'https://example/audio/activity?date=2026-07-11&detail=totals&with_ids=1&format=json'
```

## Notes

- Soft workweeks / clients / audio_dicts / audio_pipeline / bots.
- Reviews and balances live on record pages.
