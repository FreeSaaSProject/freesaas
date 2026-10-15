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

Legacy (not owned): `base_records_types` may exist from oper / older installs. Soft-read only; migrator `types_to_scripts` converts scoring types into `RATING_ENABLED` scripts. Product rating uses scripts, not types.

## Human UI

- Dictionaries under Audio analytics: dicts, keys, tags, scripts, trash phrases.
- Scripts tree: `/audio/scripts/{id}` + parts / download pack / list upload import. Same-DB copy remains via bulk and `/audio/scripts/{id}/copy`.

| Path | Permission |
|------|------------|
| `/audio/dicts` | `—` |
| `/audio/keys` | `—` |
| `/audio/scripts` | `—` |
| `/audio/trashes` | `—` |
| `/audio/tags` | `—` |

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops`.

| Op | Method | Path |
|----|--------|------|
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
- Key `ID_TYPE` column remains in schema for legacy compatibility but is not edited in product UI.
