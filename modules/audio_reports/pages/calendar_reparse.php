<?php
declare(strict_types=1);

/**
 * Authenticated day reparse (oper calendar Re-®).
 * Progressive: reset → stem batches (auto-continue) → scoped collect.
 * Live HTML so the browser does not look hung.
 */

require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/StemRunner.php');
require_once base_path('modules/audio_pipeline/lib/CollectRunner.php');

$date = trim((string)($_GET['date'] ?? $_POST['date'] ?? ''));
$json = !empty($_GET['json']) || !empty($_POST['json']);
$step = trim((string)($_GET['step'] ?? $_POST['step'] ?? 'auto'));
$ret = NavContext::sanitize((string)($_GET['ret'] ?? $_POST['ret'] ?? '/audio/calendar'));
if ($ret === '') {
    $ret = '/audio/calendar';
}

$range = StemRunner::parseDayOption($date);
if ($range === null) {
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'invalid_day'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sep = strpos($ret, '?') === false ? '?' : '&';
    redirect($ret . $sep . 'reparse_err=invalid_day');
}

$liveBoot = static function () use ($date): void {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'Off');
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'
        . e(t('audio_reports.calendar.reparse_title', ['date' => $date]))
        . '</title>';
    echo '<style>body{font:14px/1.45 system-ui,sans-serif;margin:16px;max-width:720px}'
        . '.live{background:#111;color:#d4f8d4;padding:12px;border-radius:6px;white-space:pre-wrap}'
        . '.err{color:#b91c1c;font-weight:600} a{color:#2563eb}</style></head><body>';
    echo '<h1>' . e(t('audio_reports.calendar.reparse_title', ['date' => $date])) . '</h1>';
    echo '<div class="live" id="log">';
    @flush();
};

$liveMark = static function (string $text) use ($json): void {
    if ($json) {
        return;
    }
    $mem = round(memory_get_usage() / 1024 / 1024, 1);
    echo '[' . date('H:i:s') . ' · ' . $mem . " Mb] " . e($text) . "\n";
    @flush();
};

$selfUrl = static function (string $nextStep) use ($date, $ret): string {
    return '/audio/calendar/reparse?' . http_build_query([
        'date' => $date,
        'ret' => $ret,
        'step' => $nextStep,
    ]);
};

if (!$json) {
    $liveBoot();
}

$lock = JobLock::tryAcquire('stem');
if ($lock === null) {
    if ($json) {
        JobRunner::blocked();
        return;
    }
    echo '</div><p class="err">' . e(t('audio_reports.calendar.reparse_err_busy')) . '</p>';
    echo '<p><a href="' . e($ret) . '">' . e(t('audio_reports.calendar.reparse_back')) . '</a></p></body></html>';
    return;
}

try {
    // auto: reset once, then stem, then collect when pending=0
    if ($step === 'auto' || $step === 'reset') {
        $liveMark(t('audio_reports.calendar.reparse_step_reset'));
        $reset = StemRunner::resetDay($range['start'], $range['end']);
        $liveMark(t('audio_reports.calendar.reparse_reset_stats', [
            'records' => (string)(int)($reset['records'] ?? 0),
            'instances' => (string)(int)($reset['instances'] ?? 0),
            'collections' => (string)(int)($reset['collections'] ?? 0),
        ]));
        if ($json && $step === 'reset') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => 'reset_done', 'reset' => $reset], JSON_UNESCAPED_UNICODE);
            return;
        }
        $step = 'stem';
    }

    if ($step === 'stem') {
        $pendingBefore = StemRunner::countPendingForDay($date);
        $liveMark(t('audio_reports.calendar.reparse_step_stem', [
            'pending' => (string)$pendingBefore,
        ]));

        $result = StemRunner::run([
            'day' => $date,
            'unparsed_only' => true,
            'collect' => false,
            'limit' => 200,
            'time_budget_sec' => 90,
        ]);

        $pendingAfter = StemRunner::countPendingForDay($date);
        $liveMark(t('audio_reports.calendar.reparse_stem_stats', [
            'processed' => (string)(int)($result['processed'] ?? 0),
            'skipped' => (string)(int)($result['skipped'] ?? 0),
            'batch' => (string)(int)($result['extra']['batch_records'] ?? 0),
            'pending' => (string)$pendingAfter,
            'message' => (string)($result['message'] ?? ''),
        ]));
        if (!empty($result['extra']['morph'])) {
            $liveMark('morph: ' . (string)$result['extra']['morph']);
        } elseif (($result['message'] ?? '') === 'stem_done') {
            $liveMark(t('audio_reports.calendar.reparse_morph_ok'));
        }

        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => !empty($result['ok']),
                'message' => (string)($result['message'] ?? ''),
                'processed' => (int)($result['processed'] ?? 0),
                'pending' => $pendingAfter,
                'extra' => $result['extra'] ?? [],
                'next' => $pendingAfter > 0 ? 'stem' : 'collect',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($pendingAfter > 0) {
            $next = $selfUrl('stem');
            echo '</div>';
            echo '<p>' . e(t('audio_reports.calendar.reparse_continue_hint', ['n' => (string)$pendingAfter])) . '</p>';
            echo '<p><a href="' . e($next) . '">' . e(t('audio_reports.calendar.reparse_continue')) . '</a></p>';
            echo '<meta http-equiv="refresh" content="1;url=' . e($next) . '">';
            echo '</body></html>';
            return;
        }
        $step = 'score';
    }

    if ($step === 'score') {
        $liveMark(t('audio_reports.calendar.reparse_step_score'));
        $scored = StemRunner::scoreOnly([
            'day' => $date,
            'limit' => 5000,
            'time_budget_sec' => 120,
        ]);
        $liveMark(t('audio_reports.calendar.reparse_score_stats', [
            'processed' => (string)(int)($scored['processed'] ?? 0),
            'message' => (string)($scored['message'] ?? ''),
        ]));
        $step = 'collect';
    }

    if ($step === 'collect') {
        $admins = StemRunner::adminIdsForDay($date);
        $liveMark(t('audio_reports.calendar.reparse_step_collect', [
            'admins' => (string)count($admins),
        ]));
        $coll = CollectRunner::run(false, $admins, [
            'start' => $range['start'],
            'end' => $range['end'],
            'time_budget_sec' => 90,
        ]);
        $liveMark(t('audio_reports.calendar.reparse_collect_stats', [
            'processed' => (string)(int)($coll['processed'] ?? 0),
            'collections' => (string)(int)(($coll['extra']['collections'] ?? 0)),
            'message' => (string)($coll['message'] ?? ''),
        ]));

        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'message' => 'reparse_done',
                'collect' => $coll,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo '</div>';
        echo '<p><strong>' . e(t('audio_reports.calendar.reparse_finished')) . '</strong></p>';
        $sep = strpos($ret, '?') === false ? '?' : '&';
        $done = $ret . $sep . 'reparse_ok=1&reparse_date=' . rawurlencode($date)
            . '&reparse_n=0&reparse_records=0';
        echo '<p><a href="' . e($done) . '">' . e(t('audio_reports.calendar.reparse_back')) . '</a></p>';
        echo '<meta http-equiv="refresh" content="2;url=' . e($done) . '">';
        echo '</body></html>';
        return;
    }

    if (!$json) {
        echo '</div><p class="err">Unknown step</p></body></html>';
    }
} finally {
    $lock->release();
}
