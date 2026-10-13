# Audio LLM studies

Product module `audio_llm`: Enable for AI analysis of recordings. Requires llm + audio + audio_dicts. Soft staff_reports/comments/procedures/contracts. Turn on after llm keys and audio dicts are ready. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable audio_llm --accept-risk --format=json
```

Or `[modules] audio_llm=1` in `cfg/config.ini`.

Permissions: `audio_llm.view`, `audio_llm.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `llm` >=0.1.0, `audio` >=0.1.0, `audio_dicts` >=0.1.0
- Soft: `staff_reports` >=0.1.0, `comments` >=0.1.0, `procedures` >=0.1.0, `contracts` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_records_llm_studies` | Owned by `audio_llm` |
| `base_records_llm_study_results` | Owned by `audio_llm` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

## Human UI

- `/audio/llm-studies` — regular / cyclic / spawned LLM studies over transcripts.
- Process queue on study page or `POST /audio/llm-studies/work`.

| Path | Permission |
|------|------------|
| `/audio/llm-studies` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (4 ops).

| Op | Method | Path |
|----|--------|------|
| `audio_llm.studies.list` | GET | `/audio/llm-studies` |
| `audio_llm.studies.get` | GET | `/audio/llm-studies/{id}` |
| `audio_llm.studies.create` | POST | `/audio/llm-studies/new` |
| `audio_llm.studies.work` | POST | `/audio/llm-studies/work` |

```bash
curl -sS -b jar.txt 'https://example/audio/llm-studies?format=json'
```

## Notes

- Requires `llm` + `audio` + `audio_dicts`.
- Soft staff_reports / comments / procedures / contracts for spawn channels.
