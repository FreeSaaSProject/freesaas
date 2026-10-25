# Audio dictionaries

Product module `audio_dicts`: Enable for ASR dictionaries and scripts. Requires audio. Required by pipeline, reports, audio_llm, staff_reports. Configure before turning on workers. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable audio_dicts --accept-risk --format=json
```

Or `[modules] audio_dicts=1` in `cfg/config.ini`.

Permissions: `audio_dicts.view`, `audio_dicts.edit`.

## Dependencies

- Requires: `core` >=0.3.0, `audio` >=0.1.0

## Tables

| Table | Role |
|-------|------|
| `base_records_dictonarys` | Owned by `audio_dicts` |
| `base_records_keys` | Owned by `audio_dicts` |
| `base_records_keys_tags` | Owned by `audio_dicts` |
| `base_records_keys_tags_links` | Owned by `audio_dicts` |
| `base_records_scripts` | Owned by `audio_dicts` |
| `base_records_trashes` | Owned by `audio_dicts` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

Product rating uses scripts (`RATING_ENABLED`). Keys belong to dictionaries (`ID_DICTONARY`); there is no key-type table/column in product path.

Record chip summary lives on `base_records.KEY_TYPES` (legacy name = dict ids) — see [`audio.md`](audio.md). Agents: do not treat that column as “types”.

## Human UI

- Dictionaries under Audio analytics: dicts, keys, tags, scripts, trash phrases.
- Scripts tree: `/audio/scripts/{id}` + parts / download pack / list upload import. Same-DB copy remains via bulk and `/audio/scripts/{id}/copy`.
- Day bonus roots may define `DAY_GATES` (JSON list of `{metric,op,threshold,label}`) for volume thresholds: builtins `audio_playtime`, `script_starts`, `script_finishes`, `proc_ratio`. Unavailable metrics skip (not red).
- Unique bracelet scans are a script **part**: `COND_TYPE=term_scan_unique` + `GATE_*` as **count** thresholds. Unavailable when term_scans off (part skipped, not red). Collect ignores this COND_TYPE. Provider: EventBus `audio_reports.day_quality.part_metric`.

| Path | Permission |
|------|------------|
| `/audio/dicts` | `—` |
| `/audio/keys` | `—` |
| `/audio/scripts` | `—` |
| `/audio/trashes` | `—` |
| `/audio/tags` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

**Remote script/dict R/W (agent_token):** [`audio-scripts-debug.md`](audio-scripts-debug.md) (`/agent/audio_dicts/*`).

| Op | Method | Path |
|----|--------|------|
| `audio_dicts.agent.scripts_list` | GET | `/agent/audio_dicts/scripts` |
| `audio_dicts.agent.script_get` | GET | `/agent/audio_dicts/script` |
| `audio_dicts.agent.script_save` | POST | `/agent/audio_dicts/script` |
| `audio_dicts.agent.script_part_save` | POST | `/agent/audio_dicts/script/part` |
| `audio_dicts.agent.script_part_delete` | POST | `/agent/audio_dicts/script/part/delete` |
| `audio_dicts.agent.dicts_list` | GET | `/agent/audio_dicts/dicts` |
| `audio_dicts.agent.dict_get` | GET | `/agent/audio_dicts/dict` |
| `audio_dicts.agent.keys_list` | GET | `/agent/audio_dicts/keys` |
| `audio_dicts.dicts.list` | GET | `/audio/dicts` |
| `audio_dicts.dicts.get` | GET | `/audio/dicts/{id}/edit` |
| `audio_dicts.dicts.create` | POST | `/audio/dicts/new` |
| `audio_dicts.dicts.update` | POST | `/audio/dicts/{id}/edit` |
| `audio_dicts.keys.list` | GET | `/audio/keys` |
| `audio_dicts.keys.get` | GET | `/audio/keys/{id}/edit` |
| `audio_dicts.keys.create` | POST | `/audio/keys/new` |
| `audio_dicts.keys.update` | POST | `/audio/keys/{id}/edit` |
| `audio_dicts.tags.list` | GET | `/audio/tags` |
| `audio_dicts.tags.get` | GET | `/audio/tags/{id}/edit` |
| `audio_dicts.tags.create` | POST | `/audio/tags/new` |
| `audio_dicts.tags.update` | POST | `/audio/tags/{id}/edit` |
| `audio_dicts.trashes.list` | GET | `/audio/trashes` |
| `audio_dicts.trashes.get` | GET | `/audio/trashes/{id}/edit` |
| `audio_dicts.trashes.create` | POST | `/audio/trashes/new` |
| `audio_dicts.trashes.update` | POST | `/audio/trashes/{id}/edit` |
| `audio_dicts.scripts.list` | GET | `/audio/scripts` |
| `audio_dicts.scripts.get` | GET | `/audio/scripts/{id}` |
| `audio_dicts.scripts.create` | POST | `/audio/scripts/new` |
| `audio_dicts.scripts.download` | GET | `/audio/scripts/{id}/download` (`?keys=1` embeds dict keys) |
| `audio_dicts.scripts.import` | POST | `/audio/scripts/import` (missing dicts → empty) |
| `audio_dicts.scripts.copy` | GET | `/audio/scripts/{id}/copy` |
| `audio_dicts.scripts.parts` | POST | `/audio/scripts/{id}/parts` |

```bash
curl -sS -b jar.txt 'https://example/audio/dicts?format=json'
curl -sS -b jar.txt 'https://example/audio/scripts?format=json'
```

## Notes

- Required by recognition pipeline and staff reports.
- Requires `audio`.
