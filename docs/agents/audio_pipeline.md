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

## Human UI

- No staff CRUD menu — background jobs: ingest, ASR claim/result, stem, collect.
- Configure tokens with audio settings; host cron via platform `/cron/tick`.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (7 ops).

| Op | Method | Path |
|----|--------|------|
| `audio_pipeline.asr.claim` | GET | `/audio/asr/claim` |
| `audio_pipeline.asr.result` | POST | `/audio/asr/result` |
| `audio_pipeline.asr.chunk_claim` | GET | `/audio/asr/chunk-claim` |
| `audio_pipeline.asr.chunk_result` | POST | `/audio/asr/chunk-result` |
| `audio_pipeline.jobs.ingest` | POST | `/audio/jobs/ingest` |
| `audio_pipeline.jobs.stem` | POST | `/audio/jobs/stem` |
| `audio_pipeline.jobs.collect` | POST | `/audio/jobs/collect` |

```bash
curl -sS -b jar.txt 'https://example/audio/asr/claim?format=json'
curl -sS -b jar.txt 'https://example/audio/asr/chunk-claim?format=json'
```

## Notes

- Requires `asr` + `audio` + `audio_dicts`.
- Worker endpoints use `X-Audio-Token` (generate on `/audio/settings` → cfg/modules/asr.ini).

## Whisper cabin worker (oper → FreeSaaS)

**Deploy the in-repo client:** [whisper-worker.md](whisper-worker.md) (`workers/whisper/`).

Handoff for updating an external Mac/Whisper pull worker and post-ASR parse.

| Oper (old) | FreeSaaS (new) |
|------------|----------------|
| `GET …/gens/getFileName.php` | `GET /audio/asr/claim` |
| save transcript (`saveFileDescription`) | `POST /audio/asr/result` |
| chunk claim / result (if used) | `GET /audio/asr/chunk-claim`, `POST /audio/asr/chunk-result` |
| `recordParser.php` (inbox → records) | `POST /audio/jobs/ingest` |
| **`recordParser3.php` (keys / morph / score)** | **`POST /audio/jobs/stem`** |
| `recordCollector` (script collections) | included by stem (`collect=1` default); alone: `POST /audio/jobs/collect` |

### Auth

- Header `X-Audio-Token: <token>` (or `?token=` / POST `token`).
- Token from `/audio/settings` (auto-created if missing; copy once) → `cfg/modules/asr.ini` `[asr] worker_token`.

### Claim / result

- Claim response: **plain text** filename or `None` (same as oper). Optional `?format=json` → `{"ok":true,"claim":"…"}`.
- Result POST fields: `file_name`, `result`; optional `PLAYTIME`, `VOLUME`.
- ASR claim/result are **not** part of `/cron/tick` — the Whisper worker polls FreeSaaS itself.

### After ASR (instead of `recordParser3.php`)

```bash
# Preferred (whisper idle / evening close): day-scoped drain + collect + staff_reports publish
curl -sS -X POST -H "X-Audio-Token: …" \
  'https://<host>/audio/jobs/stem?day=2026-10-16'

# Lookback batch only (no day publish) — not equivalent to calendar Re-®
curl -sS -X POST -H "X-Audio-Token: …" 'https://<host>/audio/jobs/stem'
```

| | `stem?day=YYYY-MM-DD` | Calendar **Re-®** |
|--|----------------------|-------------------|
| `resetDay` | no | yes |
| Stem unparsed for day | yes (multi-batch) | yes |
| Day collect + SCORE | yes | yes |
| Publish staff tickets / DAY_BONUS (`reparse.after`) | yes (`publish=1`) | yes |

- Not required after every file — periodic idle poll is enough.
- Filters: `day=`, `admin=` / `idAdmin=`, `record=` / `idRecord=`, `limit=`, `reset=1`, `collect=0`, `publish=0`.
- Day-report UI reads collections live; **messages/tickets** appear only after `reparse.after` (Re-® or `stem?day=`).

### Smoke

```bash
curl -sS -H "X-Audio-Token: …" 'https://<host>/audio/asr/claim'
curl -sS -H "X-Audio-Token: …" 'https://<host>/audio/jobs/stem'
```

Messenger voice queue is separate: `/asr/voice-transcribe/worker` (same token).

Full Mac/GPU install (venv, config, whisper.cpp, smoke): [whisper-worker.md](whisper-worker.md).
