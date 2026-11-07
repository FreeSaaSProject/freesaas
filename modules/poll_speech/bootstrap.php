<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PollSpeechConfig.php';
require_once __DIR__ . '/lib/PollSpeechScore.php';
require_once __DIR__ . '/lib/PollSpeechRepository.php';
require_once __DIR__ . '/lib/PollSpeechFiller.php';
require_once __DIR__ . '/lib/PollSpeechDictEnrich.php';
require_once __DIR__ . '/lib/PollSpeechCalendar.php';
require_once __DIR__ . '/lib/PollSpeechBiContrib.php';

Assets::register('poll_speech', [
    'css' => ['poll_speech.css'],
]);

$pages = [
    '/poll-speech' => [
        'permission' => 'poll_speech.view',
        'methods' => ['GET'],
        'file' => 'list.php',
    ],
    '/poll-speech/settings' => [
        'permission' => 'poll_speech.edit',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/poll-speech/fill-day' => [
        'permission' => 'poll_speech.edit',
        'methods' => ['POST'],
        'file' => 'fill_day.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'poll_speech',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/poll_speech/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

EventBus::on('calendar.timeline.enrich', static function ($payload) {
    return PollSpeechCalendar::onTimelineEnrich($payload);
});

EventBus::on('audio_reports.day.analyzed', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    PollSpeechFiller::fillDay($payload);
    return $payload;
});

EventBus::on('bi.datasets', static function ($payload) {
    return PollSpeechBiContrib::onDatasets($payload);
});

EventBus::on('polls.settings.sections', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    Assets::need('poll_speech');
    $pollOptions = class_exists('PollsRepository', false) ? PollsRepository::rootNames(true) : [];
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_section', [
        'cfg' => PollSpeechConfig::formValues(),
        'pollOptions' => $pollOptions,
        'dictKeys' => PollSpeechConfig::dictKeysForUi(),
    ], 'poll_speech');
    return $payload;
});

EventBus::on('polls.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['poll_speech_settings_save'])) {
        return $payload;
    }
    PollSpeechConfig::saveFromPost($post);
    return $payload;
});
