<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AudioDayBounds.php';
require_once __DIR__ . '/lib/CalendarAggregates.php';
require_once __DIR__ . '/lib/CalendarHooks.php';
require_once __DIR__ . '/lib/SummariesRepository.php';
require_once __DIR__ . '/lib/SummaryRunner.php';

Assets::register('audio_reports', [
    'css' => ['audio_reports.css'],
]);

$pages = [
    '/audio/calendar' => ['permission' => 'audio_reports.view', 'methods' => ['GET', 'POST'], 'file' => 'calendar.php'],
    '/audio/summaries' => ['permission' => 'audio_reports.view', 'methods' => ['GET', 'POST'], 'file' => 'summaries.php'],
    '/audio/summaries/new' => ['permission' => 'audio_reports.edit', 'methods' => ['GET', 'POST'], 'file' => 'summaries.php'],
    '/audio/summaries/{id}' => ['permission' => 'audio_reports.view', 'methods' => ['GET', 'POST'], 'file' => 'summaries.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'audio_reports',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_reports/pages/' . $file);
        },
    ]);
}

EventBus::on('audio.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        $payload = ['html' => ''];
    }
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $bounds = AudioDayBounds::read();
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_day_bounds', [
        'bounds' => $bounds,
    ], 'audio_reports');
    return $payload;
});

EventBus::on('audio.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['audio_day_bounds_save'])) {
        return $payload;
    }
    AudioDayBounds::save([
        'hourStartOffRate' => (int)($post['hourStartOffRate'] ?? 9),
        'minStartOffRate' => (int)($post['minStartOffRate'] ?? 0),
        'hourEndOffRate' => (int)($post['hourEndOffRate'] ?? 22),
        'minEndOffRate' => (int)($post['minEndOffRate'] ?? 0),
        'hourStartOffRecord' => (int)($post['hourStartOffRecord'] ?? 9),
        'minStartOffRecord' => (int)($post['minStartOffRecord'] ?? 0),
        'hourEndOffRecord' => (int)($post['hourEndOffRecord'] ?? 22),
        'minEndOffRecord' => (int)($post['minEndOffRecord'] ?? 0),
    ]);
    return $payload;
});
