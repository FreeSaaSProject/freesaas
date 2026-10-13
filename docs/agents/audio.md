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
| `base_audio_settings` | Owned by `audio` |

Exclusive ownership in `module.json` → `tables` (uninstall DROP).

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

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (14 ops).

| Op | Method | Path |
|----|--------|------|
| `audio.list` | GET | `/audio` |
| `audio.get` | GET | `/audio/{id}` |
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

```bash
curl -sS -b jar.txt 'https://example/audio?format=json'
curl -sS -b jar.txt 'https://example/audio/badges?format=json'
```

## Notes

- Soft workweeks / clients / audio_dicts / audio_pipeline / bots.
- Reviews and balances live on record pages.
