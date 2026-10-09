<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Аудио-пайплайн',
    'audio_pipeline.perm.view' => 'Аудио-пайплайн (статус)',
    'audio_pipeline.module.description' => 'Воркеры ingest / cabin ASR / stem / collect для аудиоаналитики кабинета.',
    'audio_pipeline.module.summary' => 'Включайте для фоновой обработки записей кабинета. Требует asr + audio + audio_dicts. Мягко procedures. Голосовые боты — в модуле asr.',
];
