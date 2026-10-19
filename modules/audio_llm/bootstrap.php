<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CyclicHelpers.php';
require_once __DIR__ . '/lib/StudyPayload.php';
require_once __DIR__ . '/lib/StudiesRepository.php';
require_once __DIR__ . '/lib/StudyLlm.php';
require_once __DIR__ . '/lib/StudyRunner.php';
require_once __DIR__ . '/lib/CyclicDayRunner.php';
require_once __DIR__ . '/lib/ReviewModerationConfig.php';
require_once __DIR__ . '/lib/ReviewModerationBuilder.php';
require_once __DIR__ . '/lib/ReviewModerationLlm.php';

Assets::register('audio_llm', [
    'css' => ['review_moderation.css'],
    'js' => ['review_moderation.js'],
]);

$pages = [
    '/audio/llm-studies' => ['permission' => 'audio_llm.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/audio/llm-studies/new' => ['permission' => 'audio_llm.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/audio/llm-studies/work' => ['permission' => 'audio_llm.edit', 'methods' => ['POST'], 'file' => 'work.php'],
    '/audio/review-moderate' => ['permission' => 'audio.view', 'methods' => ['POST'], 'file' => 'review_moderate.php'],

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

EventBus::on('audio.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        $payload = ['html' => ''];
    }
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_moderation', [
        'systemPrompt' => ReviewModerationConfig::storedSystemPrompt(),
        'userPrompt' => ReviewModerationConfig::storedUserPrompt(),
    ], 'audio_llm');
    return $payload;
});

EventBus::on('audio.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['audio_llm_moderation_save'])) {
        return $payload;
    }
    ReviewModerationConfig::save(
        (string)($post['audio_llm_moderation_system'] ?? ''),
        (string)($post['audio_llm_moderation_user'] ?? '')
    );
    return $payload;
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
