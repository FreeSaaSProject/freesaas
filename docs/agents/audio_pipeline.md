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
- Worker endpoints use `X-Audio-Token`.
