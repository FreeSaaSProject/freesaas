<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/CoachRunsRepository.php';
require_once __DIR__ . '/lib/CoachCatalog.php';
require_once __DIR__ . '/lib/CoachFacts.php';
require_once __DIR__ . '/lib/CoachWindows.php';
require_once __DIR__ . '/lib/CoachPhraseCheck.php';
require_once __DIR__ . '/lib/CoachLlm.php';
require_once __DIR__ . '/lib/CoachApply.php';
require_once __DIR__ . '/lib/CoachRunner.php';

Assets::register('audio_script_coach', [
    'css' => ['audio_script_coach.css'],
    'js' => ['audio_script_coach.js'],
]);

$pages = [
    '/audio/script-coach' => ['permission' => 'audio_script_coach.view', 'methods' => ['GET'], 'file' => 'list.php'],
    '/audio/script-coach/day' => ['permission' => 'audio_script_coach.view', 'methods' => ['GET', 'POST'], 'file' => 'day.php'],
    '/audio/script-coach/run/{id}' => ['permission' => 'audio_script_coach.view', 'methods' => ['GET', 'POST'], 'file' => 'run.php'],
    '/audio/script-coach/work' => ['permission' => 'audio_script_coach.edit', 'methods' => ['POST'], 'file' => 'work.php'],
    '/audio/script-coach/proposal/{id}' => ['permission' => 'audio_script_coach.edit', 'methods' => ['POST'], 'file' => 'proposal.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'audio_script_coach',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_script_coach/pages/' . $file);
        },
    ]);
}

Health::register('audio_script_coach.tables', 'audio_script_coach', static function (): array {
    if (CoachRunsRepository::tablesReady()) {
        return ['status' => 'ok', 'message' => 'script coach tables present'];
    }

    return [
        'status' => 'fail',
        'message' => 'base_script_coach_* missing',
        'meta' => ['runbook' => 'php bin/freesaas.php migrate run --module=audio_script_coach --format=json'],
    ];
}, 'Script coach schema');

EventBus::on('audio_reports.day_report.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('audio_script_coach.view') || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $adminId = (int)($payload['admin_id'] ?? 0);
    $date = trim((string)($payload['date'] ?? ''));
    if ($adminId < 1 || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $payload;
    }
    $href = '/audio/script-coach/day?' . http_build_query(['date' => $date, 'admin' => $adminId]);
    $payload['html'] = (string)($payload['html'] ?? '')
        . '<p class="asc-day-link"><a class="btn-secondary" href="' . e($href) . '">'
        . te('audio_script_coach.day_report.link') . '</a></p>';

    return $payload;
});
