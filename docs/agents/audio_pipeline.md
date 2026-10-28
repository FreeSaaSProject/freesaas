# Audio pipeline

Product module `audio_pipeline`: Enable for cabin recording background jobs. Requires asr + audio + audio_dicts. Soft procedures. Messenger voice lives in module asr. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable audio_pipeline --accept-risk --format=json
```

Or `[modules] audio_pipeline=1` in `cfg/config.ini`.

Permissions: `audio_pipeline.view`.

## Dependencies

- Requires: `core` >=0.3.0, `asr` >=0.1.0, `audio` >=0.1.0, `audio_dicts` >=0.1.0
- Soft: `procedures` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_records_instances` | Owned by `audio_pipeline` |
| `base_records_collections` | Owned by `audio_pipeline` |
| `base_records_collections_datas` | Owned by `audio_pipeline` |
| `base_records_stem_word_cache` | Owned by `audio_pipeline` |
| `base_records_stem_key_cache` | Owned by `audio_pipeline` |
| `base_records_coverage_profiles` | Owned by `audio_pipeline` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

Stem writes `base_records.KEY_TYPES` (legacy name) as a dict-hit chip blob — see [`audio.md`](audio.md) § `KEY_TYPES`. Do not derive chips from `base_records_instances` on list/read.

## Human UI

- No staff CRUD menu — background jobs: ingest, ASR claim/result, stem, collect.
- Configure tokens with audio settings; host cron via platform `/cron/tick`.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

**Remote script debug (agent_token):** day-diag, records, collections, instances, day-reparse — [`audio-scripts-debug.md`](audio-scripts-debug.md).

| Op | Method | Path |
|----|--------|------|
| `audio_pipeline.day_diag` | GET | `/agent/audio_pipeline/day-diag` |
| `audio_pipeline.day_records` | GET | `/agent/audio_pipeline/day-records` |
| `audio_pipeline.record_get` | GET | `/agent/audio_pipeline/record` |
| `audio_pipeline.collections_list` | GET | `/agent/audio_pipeline/collections` |
| `audio_pipeline.collection_get` | GET | `/agent/audio_pipeline/collection` |
| `audio_pipeline.instances_list` | GET | `/agent/audio_pipeline/instances` |
| `audio_pipeline.day_reparse` | POST | `/agent/audio_pipeline/day-reparse` |
| `audio_pipeline.asr.claim` | GET | `/audio/asr/claim` |
| `audio_pipeline.asr.result` | POST | `/audio/asr/result` |
| `audio_pipeline.asr.chunk_claim` | GET | `/audio/asr/chunk-claim` |
| `audio_pipeline.asr.chunk_result` | POST | `/audio/asr/chunk-result` |
| `audio_pipeline.jobs.ingest` | GET|POST | `/audio/jobs/ingest` (enqueue 202) |
| `audio_pipeline.jobs.ingest_run` | GET|POST | `/audio/jobs/ingest/run` (sync) |
| `audio_pipeline.jobs.stem` | GET|POST | `/audio/jobs/stem` (enqueue 202) |
| `audio_pipeline.jobs.stem_run` | GET|POST | `/audio/jobs/stem/run` (sync) |
| `audio_pipeline.jobs.collect` | GET|POST | `/audio/jobs/collect` |

```bash
curl -sS -b jar.txt 'https://example/audio/asr/claim?format=json'
curl -sS -b jar.txt 'https://example/audio/asr/chunk-claim?format=json'
```

## Notes

- Requires `asr` + `audio` + `audio_dicts`.
- Worker endpoints use `X-Audio-Token` or `?token=` (generate on `/audio/settings` → cfg/modules/asr.ini).

## Queue model (ingest / stem)

`/audio/jobs/ingest` and `/audio/jobs/stem` only **enqueue** a flag under `var/audio_jobs/` and return **HTTP 202** (`message=ingest_queued|stem_queued`). Heavy work runs on Cron:

| Cron id | Schedule | Behaviour |
|---------|----------|-----------|
| `audio_pipeline.ingest` | every 5 min | `AudioJobQueue::drainIngest` — one batch; requeues if inbox still has files |
| `audio_pipeline.ingest_nightly` | 02:00 | force drain even without flag if inbox dirty |
| `audio_pipeline.stem` | every 5 min | `drainStem` — one stem batch; when day pending=0 → collect + `reparse.after` publish; requeues while pending>0 |
| `audio_pipeline.stem_nightly` | 07:00 | morning sweep (uploads often finish only by morning): enqueue yesterday (always — same-day publish is refused while it is today) + every day within `StemRunner::LOOKBACK_DAYS` (10) with pending records, immediately; then drain head |

Host still needs `*/5` → `/cron/tick` — see [cron.md](cron.md).

**Sync processors** (blocking, same lock as before): `/audio/jobs/ingest/run`, `/audio/jobs/stem/run`. Use for `reset=1` / `score=1` / manual full drain. Staff UI `/audio/ingest` and calendar Re-® stay sync and do not use this queue.

## Uploader → auto-recognition

After files land in the audio inbox, call ingest (safe GET — no body):

```bash
curl -sS "https://<host>/audio/jobs/ingest?token=WORKER_TOKEN"
# → {"ok":true,"message":"ingest_queued",…} HTTP 202
```

Creates a queue flag; Cron creates `base_records` rows awaiting Whisper (`/audio/asr/claim`). Copy-paste GET URL with token: `/audio/settings`.

## Whisper cabin worker (cutover)

**Deploy the in-repo client:** [workers.md](workers.md) (plugin `whisper`).

Handoff for updating an external Mac/Whisper pull worker and post-ASR parse.

| Oper (old) | FreeSaaS (new) |
|------------|----------------|
| `GET …/gens/getFileName.php` | `GET /audio/asr/claim` |
| save transcript (`saveFileDescription`) | `POST /audio/asr/result` |
| chunk claim / result (if used) | `GET /audio/asr/chunk-claim`, `POST /audio/asr/chunk-result` |
| `recordParser.php` (inbox → records) | `GET|POST /audio/jobs/ingest` (enqueue) |
| **`recordParser3.php` (keys / morph / score)** | **`POST /audio/jobs/stem`** (enqueue; Cron drains) |
| `recordCollector` (script collections) | included when stem day drains (`collect=1`); alone: `POST /audio/jobs/collect` |

### Auth

- Header `X-Audio-Token: <token>` (or `?token=` / POST `token`).
- Token from `/audio/settings` (auto-created if missing; copy once) → `cfg/modules/asr.ini` `[asr] worker_token`.

### Claim / result

- Claim response: **plain text** filename or `None`. Optional `?format=json` → `{"ok":true,"claim":"…"}`.
- Result POST fields: `file_name`, `result`; optional `PLAYTIME`, `VOLUME`.
- Result save for a **past** day (`DT_RECORDED` before today, within `LOOKBACK_DAYS`) enqueues that day with `not_before = now + late_stem_delay_sec` (`cfg/modules/audio.ini` `[audio]`, default 3600); diag line shows `STEM_QUEUED=YYYY-MM-DD`. Further results for the same day merge into the same job and do **not** postpone it (earliest `not_before` wins). Why deferred: when a day drains, collect for all staff of the day + staff report publish (quality per employee, reward contract, gratitude board, optional cyclic LLM) run — too heavy to repeat every 5 min while uploads trickle in. Today's records are left to the Whisper idle ping; older than lookback: calendar Re-®.
- Queue jobs carry `not_before` (0 = next tick). `drainStem` takes the first **due** job in order; if only deferred jobs remain the cron journal shows `stem_deferred`.
- ASR claim/result are **not** part of `/cron/tick` — the Whisper worker polls FreeSaaS itself.

### After ASR (instead of `recordParser3.php`)

```bash
# Preferred (whisper idle): enqueue day drain — Cron finishes collect + staff_reports publish
curl -sS -X POST -H "X-Audio-Token: …" \
  'https://<host>/audio/jobs/stem?day=2026-10-16'
# → HTTP 202 stem_queued

# Sync / reset / score-only (blocking)
curl -sS -X POST -H "X-Audio-Token: …" \
  'https://<host>/audio/jobs/stem/run?day=2026-10-16&reset=1'
```

| | `stem?day=` (enqueue) → Cron | Calendar **Re-®** |
|--|------------------------------|-------------------|
| `resetDay` | no | yes |
| Stem unparsed for day | yes (multi-batch via requeue) | yes |
| Day collect + SCORE | yes (when pending=0) | yes |
| Publish staff tickets / DAY_BONUS (`reparse.after`) | yes (`publish=1`) | yes |

- Not required after every file — one idle ping per day is enough; Cron requeues until pending=0.
- Filters on enqueue: `day=`, `admin=` / `idAdmin=`, `record=` / `idRecord=`, `limit=`, `collect=0`, `publish=0`.
- Multi-day / multi-staff: `AudioJobQueue::requestStem` **appends or merges** scopes (`day|admin|record`) into `var/audio_jobs/queue_stem.json` → `jobs[]`. A second ping for another day must not replace the first; Cron drains head job, then the next. Same scope again only refreshes flags (`collect`/`publish` OR, `limit` widened).
- `reset=1` / `score=1` on enqueue URL → 400 `use_run_endpoint`.
- Day-report UI reads collections live; **messages/tickets** appear only after `reparse.after` (Re-® or Cron day drain).

### Smoke

```bash
curl -sS -H "X-Audio-Token: …" 'https://<host>/audio/asr/claim'
curl -sS -H "X-Audio-Token: …" 'https://<host>/audio/jobs/stem?day=2026-10-16'
# expect HTTP 202
```

Messenger voice queue is separate: `/asr/voice-transcribe/worker` (same token).

Full Mac/GPU install (venv, config, whisper.cpp, smoke): [workers.md](workers.md).
