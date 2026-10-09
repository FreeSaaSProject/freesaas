<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Audio pipeline',
    'audio_pipeline.perm.view' => 'Audio pipeline (status)',
    'audio_pipeline.module.description' => 'Ingest/ASR handshake/stem/collect workers for audio processing.',
    'audio_pipeline.module.summary' => 'Enable to run background audio processing workers. Requires audio + audio_dicts. Soft procedures. Needs worker tokens in module ini/health checks. Ops module — not only UI.',

    'audio_pipeline.bot.voice.help' => "🎙️ Voice transcription\n\nSend a voice or audio message in this chat — it will be queued for Whisper and the text will come back here.\n\nWorker: GET/POST /audio/voice-transcribe/worker (X-Audio-Token).",
    'audio_pipeline.bot.voice.accepted' => 'Voice accepted. Transcribing… Job: {id}',
    'audio_pipeline.bot.voice.enqueue_fail' => 'Could not queue the voice message. Try again.',
    'audio_pipeline.bot.voice.result' => '📝 Transcript (Job: {id})',
    'audio_pipeline.bot.voice.err' => '❌ Recognition error (Job: {id}): {error}',
];
