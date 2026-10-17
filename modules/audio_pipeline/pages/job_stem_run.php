<?php
declare(strict_types=1);

/**
 * Sync stem/collect/publish (blocking). Whisper idle should enqueue:
 *   GET|POST /audio/jobs/stem?token=&day=YYYY-MM-DD  → 202 queued
 * Sync / special modes (reset=1, score=1, full day drain):
 *   GET|POST /audio/jobs/stem/run?token=
 */

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/StemRunner.php');
require_once base_path('modules/audio_pipeline/lib/CollectRunner.php');

AsrWorkerAuth::assertToken();
$lock = JobLock::tryAcquire('stem');
if ($lock === null) {
    JobRunner::blocked('stem');
    return;
}

$req = array_merge($_GET ?? [], $_POST ?? []);
$day = isset($req['day']) ? trim((string)$req['day']) : '';
$admin = isset($req['admin']) ? (int)$req['admin'] : 0;
if ($admin < 1 && isset($req['idAdmin'])) {
    $admin = (int)$req['idAdmin'];
}
$record = isset($req['record']) ? (int)$req['record'] : 0;
if ($record < 1 && isset($req['idRecord'])) {
    $record = (int)$req['idRecord'];
}
$reset = !empty($req['reset']) && (string)$req['reset'] !== '0';
$scoreOnly = !empty($req['score']) && (string)$req['score'] !== '0';
$collect = !isset($req['collect']) || (string)$req['collect'] === '' || (string)$req['collect'] === '1';
$publish = !isset($req['publish']) || (string)$req['publish'] === '' || (string)$req['publish'] === '1';
$limit = isset($req['limit']) ? (int)$req['limit'] : 0;

try {
    if ($scoreOnly) {
        $opts = [
            'admin' => $admin,
            'record' => $record,
            'time_budget_sec' => 180,
        ];
        if ($day !== '') {
            $opts['day'] = $day;
        }
        if ($limit > 0) {
            $opts['limit'] = $limit;
        }
        JobRunner::jsonResponse(StemRunner::scoreOnly($opts));
        return;
    }

    if ($reset) {
        if ($day !== '') {
            $range = StemRunner::parseDayOption($day);
            if ($range === null) {
                JobRunner::jsonResponse(['ok' => false, 'message' => 'invalid_day'], 400);
                return;
            }
            $stats = StemRunner::resetDay($range['start'], $range['end'], $admin, $record);
            JobRunner::jsonResponse([
                'ok' => true,
                'processed' => 0,
                'skipped' => 0,
                'message' => 'reset_done',
                'extra' => ['reset' => $stats],
            ]);
            return;
        }
        if ($record > 0) {
            $stats = StemRunner::resetDay(0, 0, $admin, $record);
            JobRunner::jsonResponse([
                'ok' => true,
                'processed' => 0,
                'skipped' => 0,
                'message' => 'reset_done',
                'extra' => ['reset' => $stats],
            ]);
            return;
        }
        JobRunner::jsonResponse(['ok' => false, 'message' => 'reset_needs_day_or_record'], 400);
        return;
    }

    // Day mode (whisper idle / cron): closer to calendar Re-® without reset —
    // drain unparsed for the day → day-scoped collect/SCORE → staff_reports publish.
    if ($day !== '') {
        $range = StemRunner::parseDayOption($day);
        if ($range === null) {
            JobRunner::jsonResponse(['ok' => false, 'message' => 'invalid_day'], 400);
            return;
        }

        JobRunner::applyTimeLimit(120);
        $deadline = JobRunner::deadline(120);
        $batchLimit = $limit > 0 ? $limit : JobRunner::batchSize();
        $procTotal = 0;
        $skipTotal = 0;
        $batches = 0;
        $pending = StemRunner::countPendingForDay($day);
        $lastStem = ['message' => 'nothing_to_parse'];

        while ($pending > 0 && microtime(true) < $deadline) {
            $batches++;
            $remain = max(15, (int)ceil($deadline - microtime(true)));
            $lastStem = StemRunner::run([
                'day' => $day,
                'admin' => $admin,
                'record' => $record,
                'unparsed_only' => true,
                'collect' => false,
                'limit' => $batchLimit,
                'time_budget_sec' => $remain,
            ]);
            $procTotal += (int)($lastStem['processed'] ?? 0);
            $skipTotal += (int)($lastStem['skipped'] ?? 0);
            $pendingAfter = StemRunner::countPendingForDay($day);
            if ($pendingAfter >= $pending && (int)($lastStem['processed'] ?? 0) < 1) {
                break; // stuck
            }
            $pending = $pendingAfter;
            if (($lastStem['message'] ?? '') === 'stem_libs_unavailable') {
                JobRunner::jsonResponse($lastStem, 503);
                return;
            }
        }

        $extra = [
            'day' => $day,
            'batches' => $batches,
            'pending' => $pending,
            'stem' => $lastStem['extra'] ?? [],
        ];

        if ($collect && microtime(true) < $deadline) {
            $admins = StemRunner::adminIdsForDay($day);
            if ($admin > 0) {
                $admins = [$admin];
            }
            $remain = max(20, (int)ceil($deadline - microtime(true)));
            $coll = CollectRunner::run(false, $admins, [
                'start' => $range['start'],
                'end' => $range['end'],
                'time_budget_sec' => min(90, $remain),
            ]);
            $extra['collect'] = [
                'ok' => !empty($coll['ok']),
                'message' => (string)($coll['message'] ?? ''),
                'processed' => (int)($coll['processed'] ?? 0),
                'collections' => (int)(($coll['extra']['collections'] ?? 0)),
                'admins' => count($admins),
            ];
            $disabledN = StemRunner::disableOutsideCoverageAfterCollect(
                (int)$range['start'],
                (int)$range['end'],
                $admin > 0 ? $admin : 0
            );
            if ($disabledN > 0) {
                $extra['disabled_records'] = $disabledN;
            }

            // Same hook as calendar Re-® → staff_reports tickets / day bonuses.
            if ($publish && is_file(base_path('modules/audio_reports/lib/CalendarHooks.php'))) {
                require_once base_path('modules/audio_reports/lib/CalendarHooks.php');
                if (class_exists('CalendarHooks', false)) {
                    $hook = CalendarHooks::emitReparseAfter([
                        'date' => $day,
                        'day_start' => (int)$range['start'],
                        'day_end' => (int)$range['end'],
                        'admin_ids' => $admins,
                    ]);
                    if (isset($hook['staff_reports']) && is_array($hook['staff_reports'])) {
                        $extra['staff_reports'] = $hook['staff_reports']['stats']
                            ?? $hook['staff_reports'];
                    }
                }
            }
        } elseif ($collect) {
            $extra['collect_skipped'] = 'budget_exhausted';
        }

        $message = $pending > 0 ? 'stem_day_partial' : 'stem_day_done';
        JobRunner::jsonResponse([
            'ok' => true,
            'processed' => $procTotal,
            'skipped' => $skipTotal,
            'message' => $message,
            'extra' => $extra,
        ]);
        return;
    }

    $opts = [
        'admin' => $admin,
        'record' => $record,
        'collect' => $collect,
    ];
    if ($limit > 0) {
        $opts['limit'] = $limit;
    }

    $result = StemRunner::run($opts);
    $http = ($result['message'] === 'stem_libs_unavailable') ? 503 : 200;
    JobRunner::jsonResponse($result, $http);
} catch (Throwable $e) {
    error_log('[audio/jobs/stem/run] ' . $e->getMessage());
    $msg = $e->getMessage();
    $timedOut = (strpos($msg, '1317') !== false || stripos($msg, 'interrupted') !== false);
    JobRunner::jsonResponse([
        'ok' => false,
        'processed' => 0,
        'skipped' => 0,
        'message' => $timedOut ? 'query_interrupted' : 'stem_error',
        'extra' => ['error' => $msg],
    ], $timedOut ? 504 : 500);
} finally {
    $lock->release();
}
