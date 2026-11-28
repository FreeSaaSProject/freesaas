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

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (16 ops).

| Op | Method | Path |
|----|--------|------|
| `audio.list` | GET | `/audio` |
| `audio.get` | GET | `/audio/{id}` |
| `audio.transcripts.batch` | GET | `/audio/transcripts` |
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
- **Output:** `items[]` with `time` (HH:MM:SS from `dt_recorded`), `admin_name`, `id_badge`, `review` (`pending|reviewed|none`), and `transcription` (cleaned, same as `audio.get` with `include_transcription=1`). Lines look like `[m:ss.s->m:ss.s]text`; the offset is relative to the record start, and `dt_recorded` gives the wall-clock start.
- **Budget and paging:** `limit` (1..10, default 5) selects records per call. `max_bytes` (4000..30000, default 12000) caps the response. Keep the defaults: 3–5 records with context fit in one answer, and larger results get cut by MCP clients. The in-system assistant compacts tool results above 12000 bytes into a summary, so anything above the default is only safe over MCP. When `next_offset` is not `null`, call again with `offset=next_offset`. A single oversized record is cut at a line boundary with `transcription_truncated: true`; read the rest with `audio.get`.
- **Envelope:** `ok`, `total` (selected records), `returned` (items incl. context), `offset`, `next_offset`, `truncated`, `bytes`, `missing_ids[]`.

Recipe for a day audit: `audio.list date=… review=pending` to see the queue, then `audio.transcripts.batch date=… context_before=2` (defaults: 5 records per page) and follow `next_offset` until it is `null`. Do not raise `limit` or `max_bytes` to fetch more per call. Shift checks: add `admin=<id>` and `time_from` / `time_to`.

MCP tool name: `audio__transcripts__batch` (see [connect-mcp.md](connect-mcp.md)). MCP clients cache tool schemas: reconnect after an update to see the new op.

```bash
curl -sS -b jar.txt 'https://example/audio?format=json'
curl -sS -b jar.txt 'https://example/audio/badges?format=json'
curl -sS -b jar.txt 'https://example/audio/transcripts?date=2026-07-11&context_before=2&limit=5&format=json'
```

## Notes

- Soft workweeks / clients / audio_dicts / audio_pipeline / bots.
- Reviews and balances live on record pages.
