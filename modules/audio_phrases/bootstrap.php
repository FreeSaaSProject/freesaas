<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AudioPhrasesConfig.php';
require_once __DIR__ . '/lib/PhrasesRepository.php';
require_once __DIR__ . '/lib/PhraseExtractor.php';
require_once __DIR__ . '/lib/PhraseRunner.php';

Assets::register('audio_phrases', [
    'css' => ['audio_phrases.css'],
    'js' => ['audio_phrases.js'],
]);

$listId = 'audio_phrases';
BulkActions::register($listId, 'junk', [
    'label' => 'audio_phrases.bulk.junk',
    'permission' => 'audio_phrases.edit',
    'group' => 'audio_phrases.bulk.group.status',
    'run' => static function (array $ids): array {
        return PhrasesRepository::setStatusMany($ids, ['status' => 'junk'], Auth::userId());
    },
]);
BulkActions::register($listId, 'skip', [
    'label' => 'audio_phrases.bulk.skip',
    'permission' => 'audio_phrases.edit',
    'group' => 'audio_phrases.bulk.group.status',
    'run' => static function (array $ids): array {
        return PhrasesRepository::setStatusMany($ids, ['status' => 'skip'], Auth::userId());
    },
]);
BulkActions::register($listId, 'new', [
    'label' => 'audio_phrases.bulk.new',
    'permission' => 'audio_phrases.edit',
    'group' => 'audio_phrases.bulk.group.status',
    'run' => static function (array $ids): array {
        return PhrasesRepository::setStatusMany($ids, ['status' => 'new'], Auth::userId());
    },
]);
BulkActions::register($listId, 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'audio_phrases.delete',
    'confirm' => 'audio_phrases.bulk.confirm_delete',
    'run' => static function (array $ids): array {
        return ['done' => PhrasesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
EventBus::on('core.bulk.check_item', static function ($payload) use ($listId) {
    if (!is_array($payload) || (string)($payload['list_id'] ?? '') !== $listId) {
        return $payload;
    }
    if ((string)($payload['action'] ?? '') !== 'delete') {
        return $payload;
    }
    // Any status may be purged (cleanup of previous harvests); only missing rows block.
    $id = (int)($payload['id'] ?? 0);
    $row = PhrasesRepository::find($id);
    if ($row === null) {
        $payload['blocked'] = true;
        $payload['reason'] = t('audio_phrases.err.not_found', ['id' => $id]);
    }
    return $payload;
});

$pages = [
    '/audio/phrases' => ['permission' => 'audio_phrases.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/audio/phrases/day' => ['permission' => 'audio_phrases.view', 'methods' => ['GET', 'POST'], 'file' => 'day.php'],
    '/audio/phrases/{id}/status' => ['permission' => 'audio_phrases.edit', 'methods' => ['POST'], 'file' => 'status.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'audio_phrases',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_phrases/pages/' . $file);
        },
    ]);
}

Cron::register(AudioPhrasesConfig::CRON_JOB_ID, [
    'module' => 'audio_phrases',
    'label' => 'audio_phrases.cron.daily',
    'daily_at' => AudioPhrasesConfig::dailyAt(),
    'handler' => static function (): array {
        if (!AudioPhrasesConfig::cronEnabled()) {
            return ['ok' => true, 'processed' => 0, 'message' => 'cron_disabled'];
        }
        $r = PhraseRunner::runDailyCatchup();
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)($r['processed'] ?? 0),
            'message' => implode(',', $r['days'] ?? []),
        ];
    },
]);

EventBus::on('audio.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        $payload = ['html' => ''];
    }
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    if (!isset($payload['sections']) || !is_array($payload['sections'])) {
        $payload['sections'] = [];
    }
    $payload['sections'][] = [
        'id' => 'phrases',
        'title' => 'audio_phrases.settings.legend',
        'weight' => 30,
        'html' => View::capture('_settings_phrases', [
            'withReparse' => AudioPhrasesConfig::withReparse(),
            'cronEnabled' => AudioPhrasesConfig::cronEnabled(),
            'words' => AudioPhrasesConfig::words(),
            'minInstances' => AudioPhrasesConfig::minInstances(),
            'deltaSurround' => AudioPhrasesConfig::deltaSurround(),
            'dailyAt' => AudioPhrasesConfig::dailyAt(),
            'backfillDays' => AudioPhrasesConfig::backfillDays(),
        ], 'audio_phrases'),
    ];
    return $payload;
});

EventBus::on('audio.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['audio_phrases_settings_save'])) {
        return $payload;
    }
    Config::set('audio_phrases', 'with_reparse', !empty($post['audio_phrases_with_reparse']) ? '1' : '0');
    AudioPhrasesConfig::setCronEnabled(!empty($post['audio_phrases_cron_enabled']));
    Config::set('audio_phrases', 'words', (string)max(2, min(8, (int)($post['audio_phrases_words'] ?? 3))));
    Config::set('audio_phrases', 'min_instances', (string)max(1, (int)($post['audio_phrases_min_instances'] ?? 3)));
    Config::set('audio_phrases', 'delta_surround', (string)max(0, min(10, (int)($post['audio_phrases_delta_surround'] ?? 3))));
    $dailyAt = trim((string)($post['audio_phrases_daily_at'] ?? '03:40'));
    if (!preg_match('/^\d{1,2}:\d{2}$/', $dailyAt)) {
        $dailyAt = '03:40';
    }
    Config::set('audio_phrases', 'daily_at', $dailyAt);
    Config::set('audio_phrases', 'backfill_days', (string)max(0, min(14, (int)($post['audio_phrases_backfill_days'] ?? 3))));
    return $payload;
});

EventBus::on('audio_reports.calendar.reparse.after', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    // Default on (cfg with_reparse=1). Opt out with with_reparse=0; cron / day «Rebuild» still work.
    $force = !empty($payload['run_phrases']);
    if (!$force && !AudioPhrasesConfig::withReparse()) {
        return $payload;
    }
    $day = trim((string)($payload['day'] ?? $payload['date'] ?? ''));
    if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        return $payload;
    }
    $adminId = (int)($payload['admin'] ?? $payload['admin_id'] ?? 0);
    // Calendar may pass admin_ids[]; harvest each (or whole day when empty).
    $adminIds = [];
    if ($adminId > 0) {
        $adminIds[] = $adminId;
    }
    foreach ($payload['admin_ids'] ?? [] as $aid) {
        $aid = (int)$aid;
        if ($aid > 0) {
            $adminIds[$aid] = $aid;
        }
    }
    $adminIds = array_values($adminIds);
    try {
        if ($adminIds === []) {
            PhraseRunner::runDay($day, 0);
        } else {
            foreach ($adminIds as $aid) {
                PhraseRunner::runDay($day, $aid);
            }
        }
    } catch (Throwable $e) {
        // non-fatal
    }
    return $payload;
});

EventBus::on('audio_reports.day_report.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('audio_phrases.view')) {
        return $payload;
    }
    $adminId = (int)($payload['admin_id'] ?? 0);
    $date = trim((string)($payload['date'] ?? ''));
    if ($adminId < 1 || $date === '') {
        return $payload;
    }
    $href = '/audio/phrases/day?' . http_build_query(['date' => $date, 'admin' => $adminId]);
    $payload['html'] = (string)($payload['html'] ?? '')
        . '<p class="ar-day-phrases-link"><a href="' . e($href) . '">'
        . te('audio_phrases.day_report.link') . '</a></p>';
    return $payload;
});
