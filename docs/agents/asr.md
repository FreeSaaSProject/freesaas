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
- Cabin Mac worker (claim → whisper.cpp → result → stem): [workers.md](workers.md), code in `workers/plugins/whisper/`.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (1 ops).

| Op | Method | Path |
|----|--------|------|
| `asr.voice.worker` | GET | `/asr/voice-transcribe/worker` |
| `asr.voice_queue` | GET | `/agent/asr/voice-queue` (agent_token; `?job=<id>`) — counts, newest jobs, `last_dispatch` (why push did/did not send), `base_url_set` |
| `asr.voice_queue_requeue` | POST | `/agent/asr/voice-queue/requeue` (agent_token; `confirm=1`, `job=<id>` or `all=1`) |

```bash
curl -sS -b jar.txt 'https://example/asr/voice-transcribe/worker?format=json'
```

## Voice queue delivery

Queue: `var/voice_transcribe/{pending,processing,done,audio}` (`VoiceTranscribeQueue`). Two consumers, atomic claim (rename) keeps them apart:

- **Push** (preferred, edge host behind gateway): `audio_pipeline` `AsrPushDispatcher` sends voice jobs **before** cabin records on `/v1/asr/jobs` (`file_name = /voice/<job_id>.<ext>`, `source_url = /asr/voice-transcribe/file?job_id&job_token`). EventBus `asr.voice.enqueued` kicks one sync right after the webhook response (no wait for the 2-min cron) and then keeps polling the worker for that result up to `[asr] voice_follow_sec` (default 150) so the transcript arrives in seconds. Needs `app.base_url` for cron-driven retries (CLI cron has no Host header; `last_dispatch.skipped = no_base_url`). Results are pulled with the cabin results, stored, then acked.
- **Pull** (external clients): `GET /asr/voice-transcribe/worker?action=next`, `POST …?action=submit {job_id, job_token, text, error}`.

Processing jobs the edge host no longer holds go back to pending on the next sync; `voice_processing_ttl_sec` (default 3 h) is only a safety net so long visit recordings are not restarted.

### Result contract (`bots.voice.transcription_result`)

| Key | Value |
|-----|-------|
| `job` | Queue job incl. extra from `enqueueFromPath` (`consumer_module`, `event_id`, …) and `message_date` / `duration` from the messenger |
| `text` | Plain transcript (cue prefixes stripped) |
| `text_raw` | Whisper lines `[mm:ss->mm:ss] …` — same format as `base_records.TRANSCRIPTION` |
| `playtime` | Seconds (from worker) or null |
| `audio_path` | Absolute path while the file still exists |
| `error` | Worker error / failed job |
| `handled` | Set `true` to skip the default chat reply |
| `audio_taken` | Set `true` when the consumer moved `audio_path` away (e.g. import into audio analytics); otherwise the file is deleted after the event |

Consumer pattern: bot plugin with `BotChatState` → `VoiceTranscribeBotPlugin::enqueueFromPath(..., ['consumer_module' => '<id>', …])` → listener checks `job.consumer_module` (see wellness `FitFoodBotPlugin`). Medical use (visit recording → medcard): propose fields via `docs.instance.propose`, never reply clinical text into the chat.

Download failures are answered in the chat (`too_large` — messenger bot file limit ~20 MB, `download_failed`).

### Retention

Cron `asr.voice_retention` (daily 04:20): drops `done/*.json` and orphan `audio/*` older than `[asr] voice_retention_days` (default 7) and requeues stale processing jobs. Voice may contain medical data — keep the window short.

## Notes

- Paths `/audio/voice-transcribe/*` are aliases of `/asr/voice-transcribe/*`.
- Enable before `audio_pipeline` if you need cabin ASR workers.
