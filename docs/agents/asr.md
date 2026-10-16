# ASR / speech recognition

Product module `asr`: Enable for voice transcription (bots / external Whisper). Core only. Soft bots. Does not pull audio/workweeks. Catalog maturity: `beta`.

## Enable

```bash
php bin/freesaas.php module enable asr --accept-risk --format=json
```

Or `[modules] asr=1` in `cfg/config.ini`.

Permissions: `asr.view`.

## Dependencies

- Requires: `core` >=0.3.0
- Soft: `bots` >=0.1.0

## Human UI

- No own rail menu — Whisper voice-queue worker endpoints for bots / external workers.
- Soft `bots`: voice-transcribe BotPlugin when bots enabled.
- Worker auth: `X-Audio-Token` (shared with audio pipeline), not staff session.
- Token UI: `/audio/settings` — auto-generate if missing, regenerate with confirm; plaintext shown once. Persists to `cfg/modules/asr.ini` `[asr] worker_token`.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (1 ops).

| Op | Method | Path |
|----|--------|------|
| `asr.voice.worker` | GET | `/asr/voice-transcribe/worker` |

```bash
curl -sS -b jar.txt 'https://example/asr/voice-transcribe/worker?format=json'
```

## Notes

- Paths `/audio/voice-transcribe/*` are aliases of `/asr/voice-transcribe/*`.
- Enable before `audio_pipeline` if you need cabin ASR workers.
