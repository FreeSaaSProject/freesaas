# Medcard from audio (bot, no cabin analytics)

Product module `medcard_audio` (catalog maturity: `alpha`): staff fills a visit medical sheet from a **messenger voice message**. Needs `docs` + `calendar` + `asr` + `llm`; soft `bots`. Does **not** require cabin `audio` / `audio_pipeline` / `audio_llm`.

See also: [`docs.md`](docs.md) (engine), [`asr.md`](asr.md) (voice queue), [`bots.md`](bots.md) (BotPlugins).

## Enable

```bash
php bin/freesaas.php module enable medcard_audio --accept-risk --format=json
cp modules/medcard_audio/config.example.ini cfg/modules/medcard_audio.ini
# Needs a ready LLM profile (cfg/modules/llm.ini) and ASR worker token (cfg/modules/asr.ini).
```

UI: `/medcard-audio/settings` (superadmin). Agent: `medcard_audio.settings` / `medcard_audio.settings.save`.

| ini key | Meaning |
|---------|---------|
| `bot_enabled` | `1` — show cabinet button «Fill medcard from audio» |
| `fill_mode` | `direct` (default) — write with `_prov.source=audio_bot` while unconfirmed; `propose` — pending `base_doc_proposals` |
| `llm_profile` | optional `LlmConfig` profile id; empty = default ready profile |
| `max_tokens` | completion budget (default 8000) |
| `confidence_default` | used when the model omits confidence |

## Flow

```
Staff bot cabinet → pick today’s own calendar event → send voice
  → VoiceTranscribeBotPlugin (consumer_module=medcard_audio, event_id)
  → Whisper voice queue (/asr/voice-transcribe/*)
  → bots.voice.transcription_result
  → MedcardAudioBotPlugin → MedcardAudioFill::fromTranscript
  → DocsSpawner::ensureForEvent → prefer specialty visit sheet over medcard_visit
  → LlmClient with schema JSON from the sheet → {"fields":{…},"confidence"}
  → DocsFillTemplates::applyGaps (doctor template, then clinic) for empty fields
  → fill_mode direct|propose → notify with /documents/{id}/edit link
```

LLM does **not** invent block structure: it receives extractable field ids/types/labels from `SCHEMA_JSON` and must omit fields with no evidence in the transcript. Skips `doctor_id` (set from event operator) and `talk_segments`.

## Cabin path vs bot path

| | Cabin (`audio_llm`) | Bot (`medcard_audio`) |
|--|---------------------|------------------------|
| Input | cabin `base_records` + pipeline ASR | messenger voice → `asr` voice queue |
| LLM prompt | staff study PROMPT; docs only parses a `"docs"` JSON block in RESULT_TEXT | hardcoded schema-driven extract in `MedcardAudioFill` |
| Write | proposals (`audio_llm`) | `audio_bot` direct or propose |
| Modules | audio + audio_llm (+ usually audio_pipeline) | docs + calendar + asr + llm (+ bots) |
