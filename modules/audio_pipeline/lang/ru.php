<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Аудио-пайплайн',
    'audio_pipeline.perm.view' => 'Аудио-пайплайн (статус)',
    'audio_pipeline.module.description' => 'Воркеры ingest / cabin ASR / stem / collect для аудиоаналитики кабинета.',
    'audio_pipeline.module.summary' => 'Включайте для фоновой обработки записей кабинета. Требует asr + audio + audio_dicts. Мягко procedures. Голосовые боты — в модуле asr.',
    'audio_pipeline.cron.ingest' => 'Разбор inbox аудио (каждые 5 мин)',
    'audio_pipeline.cron.ingest_nightly' => 'Разбор inbox аудио (форс ночью в 02:00)',
    'audio_pipeline.cron.stem' => 'Stem/день (каждые 5 мин)',
    'audio_pipeline.cron.stem_nightly' => 'Stem/день: вчера + дни с хвостами (утром в 07:00)',
    'audio_pipeline.settings.coverage' => 'Покрытие записей (отключение простоя)',
    'audio_pipeline.settings.coverage_hint' => 'Глобальные значения. Ниже — опциональные оверрайды по роли / сотруднику. Без сопоставления процедур оставляйте match выключенным.',
    'audio_pipeline.settings.disable_outside' => 'Отключать записи вне покрытия после collect',
    'audio_pipeline.settings.disable_outside_hint' => 'Остаются включёнными: rated-коллекция, платная процедура (± буфер) или «треб. осмотра». Нужен флаг DISABLE_NON_PROCEDURE_TIME на роли.',
    'audio_pipeline.settings.match_procedures' => 'Сравнивать / сопоставлять платные процедуры с сессиями скриптов',
    'audio_pipeline.settings.match_procedures_hint' => 'Панель процедур в day-report и окна процедур при отключении простоя. По умолчанию выкл.; включайте глобально или по роли/сотруднику.',
    'audio_pipeline.settings.procedure_buffer' => 'Буфер процедуры (сек)',
    'audio_pipeline.settings.min_procedure' => 'Мин. длительность процедуры (сек)',
    'audio_pipeline.settings.coverage_by_position' => 'Оверрайд по роли',
    'audio_pipeline.settings.coverage_by_position_hint' => 'Если включено — заменяет глобальные match/disable/min/buffer для сотрудников этой роли.',
    'audio_pipeline.settings.coverage_by_admin' => 'Оверрайд по сотруднику',
    'audio_pipeline.settings.coverage_by_admin_hint' => 'Имеет приоритет над профилем роли.',
    'audio_pipeline.settings.position' => 'Роль',
    'audio_pipeline.settings.admin' => 'Сотрудник',
    'audio_pipeline.settings.profile_enabled' => 'Использовать этот оверрайд',
];
