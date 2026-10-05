<?php
declare(strict_types=1);

require_once base_path('modules/audio_pipeline/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/StemRunner.php');

AsrWorkerAuth::assertToken();
$lock = JobLock::tryAcquire('stem');
if ($lock === null) {
    JobRunner::blocked();
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

    $opts = [
        'admin' => $admin,
        'record' => $record,
        'collect' => $collect,
    ];
    if ($day !== '') {
        $opts['day'] = $day;
    }
    if ($limit > 0) {
        $opts['limit'] = $limit;
    }

    $result = StemRunner::run($opts);
    $http = ($result['message'] === 'stem_libs_unavailable') ? 503 : 200;
    JobRunner::jsonResponse($result, $http);
} finally {
    $lock->release();
}
