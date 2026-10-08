<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Аудио-пайплайн',
    'audio_pipeline.perm.view' => 'Аудио-пайплайн (статус)',
    'audio_pipeline.module.description' => 'Воркеры ingest/ASR handshake/stem/collect для обработки аудио.',
    'audio_pipeline.module.summary' => 'Включайте для фоновых воркеров аудио. Требует audio + audio_dicts. Мягко procedures. Нужны токены воркеров в ini/health. Операционный модуль, не только UI.',
];
