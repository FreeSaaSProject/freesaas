<?php
declare(strict_types=1);

return [
    'asr.module.name' => 'ASR / распознавание речи',
    'asr.module.description' => 'Очередь голосовых и общий токен воркера Whisper (без аудиоаналитики и смен).',
    'asr.module.summary' => 'Включайте для расшифровки голоса (боты / внешний Whisper). Только core. Мягко bots. Не тянет audio/workweeks.',
    'asr.perm.view' => 'ASR (статус)',

    'asr.bot.voice.help' => "🎙️ Расшифровка голосовых\n\nПришлите голосовое или аудио в этот чат — оно встанет в очередь Whisper, текст вернётся сюда.\n\nВоркер: GET/POST /asr/voice-transcribe/worker (X-Audio-Token).",
    'asr.bot.voice.accepted' => 'Голосовое принято. Расшифровываю… Job: {id}',
    'asr.bot.voice.enqueue_fail' => 'Не удалось поставить голосовое в очередь. Попробуйте ещё раз.',
    'asr.bot.voice.result' => '📝 Расшифровка (Job: {id})',
    'asr.bot.voice.err' => '❌ Ошибка распознавания (Job: {id}): {error}',
];
