<?php
declare(strict_types=1);

/**
 * Enqueue stem/day parse (fast). Whisper idle:
 *   GET|POST /audio/jobs/stem?token=…&day=YYYY-MM-DD
 * Heavy work: Cron audio_pipeline.stem (every 5 min) + nightly 02:00,
 * or sync processor /audio/jobs/stem/run?token=… (reset/score/full day).
 */

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/AudioJobQueue.php');

AsrWorkerAuth::assertToken();
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
$collect = !isset($req['collect']) || (string)$req['collect'] === '' || (string)$req['collect'] === '1';
$publish = !isset($req['publish']) || (string)$req['publish'] === '' || (string)$req['publish'] === '1';
$limit = isset($req['limit']) ? (int)$req['limit'] : 0;

// Destructive / special modes stay sync on /run — do not enqueue silently.
if ((!empty($req['reset']) && (string)$req['reset'] !== '0')
    || (!empty($req['score']) && (string)$req['score'] !== '0')
) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'use_run_endpoint',
        'hint' => '/audio/jobs/stem/run?token=… for reset=1 or score=1',
    ], JSON_UNESCAPED_UNICODE);
    return;
}

$out = AudioJobQueue::requestStem([
    'day' => $day,
    'admin' => $admin,
    'record' => $record,
    'collect' => $collect,
    'publish' => $publish,
    'limit' => $limit,
]);
JobRunner::queuedResponse($out);
