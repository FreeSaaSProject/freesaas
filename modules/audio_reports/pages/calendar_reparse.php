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
require_once base_path('modules/audio_reports/lib/ReparseCost.php');

$date = trim((string)($_GET['date'] ?? $_POST['date'] ?? ''));
$adminFilter = max(0, (int)($_GET['admin'] ?? $_POST['admin'] ?? 0));
$json = !empty($_GET['json']) || !empty($_POST['json']);
$step = trim((string)($_GET['step'] ?? $_POST['step'] ?? 'auto'));
$ret = NavContext::sanitize((string)($_GET['ret'] ?? $_POST['ret'] ?? '/audio/calendar'));
if ($ret === '') {
    $ret = '/audio/calendar';
}

$cost = new ReparseCost($_GET);
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
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e(t('audio_reports.calendar.reparse_title', ['date' => $date])) . '</title>';
    echo '<style>'
        . 'html,body{margin:0;padding:0;background:#f8fafc;color:#0f172a;font:14px/1.45 system-ui,sans-serif}'
        . 'main{max-width:780px;margin:24px auto;padding:0 16px}'
        . 'h1{font-size:1.25rem;margin:0 0 12px}'
        . '.live{background:#0f172a;color:#bbf7d0;padding:12px;border-radius:8px;white-space:pre-wrap;'
        . 'min-height:8rem;overflow:auto;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}'
        . '.cost{background:#1e293b;color:#93c5fd;padding:10px 12px;border-radius:8px;margin-top:12px;'
        . 'font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre-wrap}'
        . '.err{color:#b91c1c;font-weight:600} a{color:#2563eb}'
        . '.hint{color:#64748b;margin:8px 0 16px;font-size:13px}'
        . '</style></head><body><main>';
    echo '<h1>' . e(t('audio_reports.calendar.reparse_title', ['date' => $date])) . '</h1>';
    echo '<p class="hint">' . e(t('audio_reports.calendar.reparse_live_hint')) . '</p>';
    echo '<div class="live" id="log">';
    @flush();
};

$liveMark = static function (string $text) use ($json, $cost): void {
    if ($json) {
        return;
    }
    $mem = $cost->memMb();
    $peak = $cost->peakMb();
    $sec = $cost->requestSec();
    echo '[' . date('H:i:s') . sprintf(' · +%.1fs · %.1f/%.1f Mb] ', $sec, $mem, $peak)
        . e($text) . "\n";
    @flush();
};

$liveCost = static function (string $prefix = 'cost') use ($json, $cost, $liveMark): void {
    $liveMark($cost->line($prefix));
};

$selfUrl = static function (string $nextStep) use ($date, $ret, $cost, $adminFilter): string {
    $q = array_merge([
        'date' => $date,
        'ret' => $ret,
        'step' => $nextStep,
    ], $cost->queryParams());
    if ($adminFilter > 0) {
        $q['admin'] = $adminFilter;
    }

    return '/audio/calendar/reparse?' . http_build_query($q);
};

$renderCostBox = static function (?int $pendingLeft = null) use ($cost, $date): void {
    if ($pendingLeft === null) {
        $pendingLeft = StemRunner::countPendingForDay($date);
    }
    $lines = $cost->summaryLines($pendingLeft);
    echo '<div class="cost" id="cost">';
    foreach ($lines as $line) {
        echo e($line) . "\n";
    }
    echo '</div>';
};

if (!$json) {
    $liveBoot();
    $liveCost('boot');
}

$lock = JobLock::tryAcquire('stem');
if ($lock === null) {
    if ($json) {
        JobRunner::blocked();
        return;
    }
    echo '</div><p class="err">' . e(t('audio_reports.calendar.reparse_err_busy')) . '</p>';
    echo '<p><a href="' . e($ret) . '">' . e(t('audio_reports.calendar.reparse_back')) . '</a></p></main></body></html>';
    return;
}

try {
    if ($step === 'auto' || $step === 'reset') {
        $cost->mark('reset');
        $liveMark(t('audio_reports.calendar.reparse_step_reset'));
        if ($adminFilter > 0) {
            $liveMark('admin_filter=' . $adminFilter);
        }
        $reset = StemRunner::resetDay($range['start'], $range['end'], $adminFilter);
        $liveMark(t('audio_reports.calendar.reparse_reset_stats', [
            'records' => (string)(int)($reset['records'] ?? 0),
            'instances' => (string)(int)($reset['instances'] ?? 0),
            'collections' => (string)(int)($reset['collections'] ?? 0),
        ]));
        $cost->endMark();
        $liveCost('after_reset');
        if ($json && $step === 'reset') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'message' => 'reset_done',
                'reset' => $reset,
                'admin' => $adminFilter,
                'cost' => $cost->toArray(),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        $step = 'stem';
    }

    if ($step === 'stem') {
        $pendingBefore = StemRunner::countPendingForDay($date);
        $cost->setPendingStart($pendingBefore);
        $cost->mark('stem');
        $liveMark(t('audio_reports.calendar.reparse_step_stem', [
            'pending' => (string)$pendingBefore,
        ]));
        $liveMark(t('audio_reports.calendar.reparse_stem_wait'));

        // One (or few) day-sized stem passes — oper processes the whole day in ~2–4s.
        // Small batches re-encode ~4k keys each time and blow wall time to ~13s/day.
        $requestBudgetSec = 180;
        $batchLimit = 700;
        $deadline = microtime(true) + $requestBudgetSec;
        $result = ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'nothing_to_parse', 'extra' => []];
        $procTotal = 0;
        $skipTotal = 0;
        $batchNum = 0;
        $pendingAfter = $pendingBefore;
        $stuck = false;

        while ($pendingAfter > 0 && microtime(true) < $deadline) {
            $batchNum++;
            $pendingBatchStart = $pendingAfter;
            try {
                $stemOpts = [
                    'day' => $date,
                    'unparsed_only' => true,
                    'collect' => false,
                    'limit' => $batchLimit,
                    'time_budget_sec' => max(15, (int)ceil($deadline - microtime(true))),
                ];
                if ($adminFilter > 0) {
                    $stemOpts['admin'] = $adminFilter;
                }
                $result = StemRunner::run($stemOpts);
            } catch (Throwable $e) {
                $liveMark('ERROR: ' . $e->getMessage());
                $result = [
                    'ok' => false,
                    'processed' => 0,
                    'skipped' => 0,
                    'message' => 'stem_exception:' . $e->getMessage(),
                    'extra' => [],
                ];
                $stuck = true;
                break;
            }

            $proc = (int)($result['processed'] ?? 0);
            $skip = (int)($result['skipped'] ?? 0);
            $procTotal += $proc;
            $skipTotal += $skip;
            $cost->addStemBatch($proc, $skip);
            $cost->mark('stem'); // continue wall clock across batches
            $pendingAfter = StemRunner::countPendingForDay($date);

            $liveMark(t('audio_reports.calendar.reparse_stem_stats', [
                'processed' => (string)$proc,
                'skipped' => (string)$skip,
                'batch' => (string)(int)($result['extra']['batch_records'] ?? 0),
                'pending' => (string)$pendingAfter,
                'message' => 'batch#' . $batchNum . ' ' . (string)($result['message'] ?? ''),
            ]));
            if ($batchNum === 1 && !empty($result['extra']['stem_cache'])) {
                $liveMark('stem_cache: ' . (string)$result['extra']['stem_cache']);
            }
            if ($batchNum === 1 && !empty($result['extra']['enabled_records'])) {
                $liveMark('enabled_records=' . (int)$result['extra']['enabled_records']);
            }
            if (!empty($result['extra']['collect']['disabled_records'])) {
                $liveMark('disabled_records=' . (int)$result['extra']['collect']['disabled_records']);
            }
            if ($batchNum === 1) {
                if (!empty($result['extra']['morph'])) {
                    $liveMark('morph: ' . (string)$result['extra']['morph']);
                } elseif (($result['message'] ?? '') === 'stem_done') {
                    $liveMark(t('audio_reports.calendar.reparse_morph_ok'));
                }
            }

            $touched = $proc + $skip;
            if ($pendingAfter > 0 && $pendingAfter === $pendingBatchStart && $touched < 1) {
                $stuck = true;
                break;
            }
            if ($touched < 1) {
                break;
            }
        }

        $liveMark(sprintf(
            'queue Δ=%+d (%d → %d) · batches=%d · wall in cost line below',
            $pendingBefore - $pendingAfter,
            $pendingBefore,
            $pendingAfter,
            $batchNum
        ));
        $cost->endMark();
        $liveCost('after_stem');

        // JSON bulk client: only pause when stem still has work (or stuck).
        if ($json && ($pendingAfter > 0 || $stuck)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => !empty($result['ok']) && !$stuck,
                'message' => (string)($result['message'] ?? ''),
                'processed' => $procTotal,
                'pending' => $pendingAfter,
                'date' => $date,
                'extra' => array_merge($result['extra'] ?? [], ['batches' => $batchNum]),
                'next' => $stuck ? 'stuck' : 'stem',
                'cost' => $cost->toArray(),
                'cost_query' => $cost->queryParams(),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($stuck && $pendingAfter > 0) {
            echo '</div>';
            echo '<p class="err">' . e(t('audio_reports.calendar.reparse_stem_stuck', [
                'pending' => (string)$pendingAfter,
                'message' => (string)($result['message'] ?? ''),
            ])) . '</p>';
            $renderCostBox();
            echo '<p><a href="' . e($selfUrl('stem')) . '">' . e(t('audio_reports.calendar.reparse_continue')) . '</a></p>';
            echo '<p><a href="' . e($ret) . '">' . e(t('audio_reports.calendar.reparse_back')) . '</a></p>';
            echo '</main></body></html>';
            return;
        }

        if ($pendingAfter > 0) {
            $next = $selfUrl('stem');
            echo '</div>';
            echo '<p>' . e(t('audio_reports.calendar.reparse_continue_hint', ['n' => (string)$pendingAfter])) . '</p>';
            $renderCostBox();
            echo '<p><a href="' . e($next) . '">' . e(t('audio_reports.calendar.reparse_continue')) . '</a></p>';
            echo '<meta http-equiv="refresh" content="1;url=' . e($next) . '">';
            echo '</main></body></html>';
            return;
        }
        $step = 'collect';
    }

    if ($step === 'collect') {
        $admins = $adminFilter > 0
            ? [$adminFilter]
            : StemRunner::adminIdsForDay($date);
        $cost->mark('collect');
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
        $disabledN = StemRunner::disableOutsideCoverageAfterCollect(
            (int)$range['start'],
            (int)$range['end'],
            $adminFilter
        );        if ($disabledN > 0) {
            $liveMark('disabled_records=' . $disabledN);
        }
        $cost->endMark();
        $liveCost('after_collect');

        $cost->mark('hooks');
        require_once base_path('modules/audio_reports/lib/CalendarHooks.php');
        CalendarHooks::emitReparseAfter([
            'date' => $date,
            'day_start' => (int)$range['start'],
            'day_end' => (int)$range['end'],
            'admin_ids' => $admins,
            'log' => $liveMark,
        ]);
        $cost->addCollectDone();
        $liveCost('done');

        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'message' => 'reparse_done',
                'date' => $date,
                'collect' => $coll,
                'processed' => (int)($coll['processed'] ?? 0),
                'collections' => (int)(($coll['extra']['collections'] ?? 0)),
                'time' => round($cost->requestSec(), 3),
                'cost' => $cost->toArray(),
                'cost_query' => $cost->queryParams(),
                'next' => 'done',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo '</div>';
        echo '<p><strong>' . e(t('audio_reports.calendar.reparse_finished')) . '</strong></p>';
        $renderCostBox();
        $sep = strpos($ret, '?') === false ? '?' : '&';
        $done = $ret . $sep . 'reparse_ok=1&reparse_date=' . rawurlencode($date)
            . '&reparse_n=0&reparse_records=0';
        echo '<p><a href="' . e($done) . '">' . e(t('audio_reports.calendar.reparse_back')) . '</a></p>';
        echo '<meta http-equiv="refresh" content="2;url=' . e($done) . '">';
        echo '</main></body></html>';
        return;
    }

    if (!$json) {
        echo '</div><p class="err">Unknown step</p></main></body></html>';
    }
} catch (Throwable $e) {
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
            'cost' => $cost->toArray(),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $liveMark('FATAL: ' . $e->getMessage());
        $liveCost('fatal');
        echo '</div><p class="err">' . e($e->getMessage()) . '</p>';
        $renderCostBox();
        echo '<p><a href="' . e($ret) . '">' . e(t('audio_reports.calendar.reparse_back')) . '</a></p>';
        echo '</main></body></html>';
    }
} finally {
    $lock->release();
}
