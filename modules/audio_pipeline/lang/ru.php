<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Аудио-пайплайн',
    'audio_pipeline.perm.view' => 'Аудио-пайплайн (статус)',
    'audio_pipeline.module.description' => 'Воркеры ingest/ASR handshake/stem/collect для обработки аудио.',
    'audio_pipeline.module.summary' => 'Включайте для фоновых воркеров аудио. Требует audio + audio_dicts. Мягко procedures. Нужны токены воркеров в ini/health. Операционный модуль, не только UI.',

    'audio_pipeline.bot.voice.help' => "🎙️ Расшифровка голосовых\n\nПришлите голосовое или аудио в этот чат — оно встанет в очередь Whisper, текст вернётся сюда.\n\nВоркер: GET/POST /audio/voice-transcribe/worker (X-Audio-Token).",
    'audio_pipeline.bot.voice.accepted' => 'Голосовое принято. Расшифровываю… Job: {id}',
    'audio_pipeline.bot.voice.enqueue_fail' => 'Не удалось поставить голосовое в очередь. Попробуйте ещё раз.',
    'audio_pipeline.bot.voice.result' => '📝 Расшифровка (Job: {id})',
    'audio_pipeline.bot.voice.err' => '❌ Ошибка распознавания (Job: {id}): {error}',
];
