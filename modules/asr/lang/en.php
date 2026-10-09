<?php
declare(strict_types=1);

return [
    'asr.module.name' => 'ASR / speech recognition',
    'asr.module.description' => 'Voice queue and shared Whisper worker token (no cabin analytics or shifts).',
    'asr.module.summary' => 'Enable for voice transcription (bots / external Whisper). Core only. Soft bots. Does not pull audio/workweeks.',
    'asr.perm.view' => 'ASR (status)',

    'asr.bot.voice.help' => "🎙️ Voice transcription\n\nSend a voice or audio message in this chat — it will be queued for Whisper and the text will come back here.\n\nWorker: GET/POST /asr/voice-transcribe/worker (X-Audio-Token).",
    'asr.bot.voice.accepted' => 'Voice accepted. Transcribing… Job: {id}',
    'asr.bot.voice.enqueue_fail' => 'Could not queue the voice message. Try again.',
    'asr.bot.voice.result' => '📝 Transcript (Job: {id})',
    'asr.bot.voice.err' => '❌ Recognition error (Job: {id}): {error}',
];
