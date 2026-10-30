<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/CoachRunsRepository.php';
require_once __DIR__ . '/lib/CoachCatalog.php';
require_once __DIR__ . '/lib/CoachFacts.php';
require_once __DIR__ . '/lib/CoachVerdicts.php';
require_once __DIR__ . '/lib/CoachWindows.php';
require_once __DIR__ . '/lib/CoachPhraseCheck.php';
require_once __DIR__ . '/lib/CoachLlm.php';
require_once __DIR__ . '/lib/CoachApply.php';
require_once __DIR__ . '/lib/CoachWhatIf.php';
require_once __DIR__ . '/lib/CoachRunner.php';
require_once __DIR__ . '/lib/CoachProgress.php';

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
    $redN = 0;
    $quality = is_array($payload['quality'] ?? null) ? $payload['quality'] : null;
    if (is_array($quality) && is_array($quality['parts'] ?? null)) {
        foreach ($quality['parts'] as $p) {
            if (($p['color'] ?? '') === 'red' && !empty($p['is_scoring'])
                && (string)($p['part_kind'] ?? '') !== 'day_metric'
            ) {
                $redN++;
            }
        }
    }
    $latest = CoachRunsRepository::tablesReady()
        ? CoachRunsRepository::findLatestForDay($adminId, $date)
        : null;
    $sumHtml = '';
    if ($redN > 0 || is_array($latest)) {
        $asr = 0;
        $semantic = 0;
        $conflict = 0;
        $notSaid = 0;
        if (is_array($latest) && (int)($latest['id'] ?? 0) > 0) {
            $ver = CoachRunsRepository::artifact((int)$latest['id'], 'verdicts');
            if (is_array($ver) && is_array($ver['summary'] ?? null)) {
                $asr = (int)($ver['summary']['asr_near'] ?? 0);
                $semantic = (int)($ver['summary']['semantic'] ?? 0);
                $conflict = (int)($ver['summary']['stem_conflict'] ?? 0);
                $notSaid = (int)($ver['summary']['not_said'] ?? 0);
            }
        }
        $sumHtml = '<p class="muted small asc-day-summary">'
            . te('audio_script_coach.day_report.summary', [
                'red' => (string)$redN,
                'asr' => (string)$asr,
                'semantic' => (string)$semantic,
                'conflict' => (string)$conflict,
                'not_said' => (string)$notSaid,
            ])
            . '</p>';
    }
    $runLink = '';
    if (is_array($latest) && (int)($latest['id'] ?? 0) > 0) {
        $runLink = ' <a class="btn-secondary" href="/audio/script-coach/run/'
            . (int)$latest['id'] . '">'
            . te('audio_script_coach.day.open_run', ['id' => (string)(int)$latest['id']])
            . '</a>';
    }
    $payload['html'] = (string)($payload['html'] ?? '')
        . '<div class="asc-day-link">' . $sumHtml
        . '<p><a class="btn-secondary" href="' . e($href) . '">'
        . te('audio_script_coach.day_report.link') . '</a>' . $runLink . '</p></div>';

    return $payload;
});
