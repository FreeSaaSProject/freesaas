<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CyclicHelpers.php';
require_once __DIR__ . '/lib/StudyPayload.php';
require_once __DIR__ . '/lib/StudiesRepository.php';
require_once __DIR__ . '/lib/StudyLlm.php';
require_once __DIR__ . '/lib/StudyRunner.php';
require_once __DIR__ . '/lib/CyclicDayRunner.php';

$pages = [
    '/audio/llm-studies' => ['permission' => 'audio_llm.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/audio/llm-studies/new' => ['permission' => 'audio_llm.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/audio/llm-studies/work' => ['permission' => 'audio_llm.edit', 'methods' => ['GET', 'POST'], 'file' => 'work.php'],
    '/audio/llm-studies/{id}' => ['permission' => 'audio_llm.view', 'methods' => ['GET', 'POST'], 'file' => 'view.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'audio_llm',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_llm/pages/' . $file);
        },
    ]);
}

EventBus::on('staff_reports.day.after_publish', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    return CyclicDayRunner::onAfterPublish($payload);
});

Health::register('audio_llm.tables', 'audio_llm', static function (): array {
    if (StudiesRepository::tablesReady()) {
        return ['status' => 'ok', 'message' => 'LLM study tables present'];
    }
    return [
        'status' => 'fail',
        'message' => 'base_records_llm_studies missing',
        'meta' => ['runbook' => 'php bin/freesaas.php migrate run --module=audio_llm --format=json'],
    ];
}, 'LLM studies schema');
