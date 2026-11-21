#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Push a few asr_dia jobs to Mac Studio (LAN gateway), wait, print perf.
 *
 *   php bin/asr-dia-sample-run.php
 *   php bin/asr-dia-sample-run.php --ids=291273,291407,291361 --model=large-v3
 *   php bin/asr-dia-sample-run.php --ids=291273 --model=podlodka-turbo
 *   php bin/asr-dia-sample-run.php --gateway=https://192.168.1.215:18443
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');
require_once base_path('modules/audio_diarize/lib/AsrDiaPushDispatcher.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

$ids = [];
$model = 'large-v3';
$gateway = 'https://192.168.1.215:18443';
$applySpeakers = true;
$applyTranscription = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--ids=([\d,]+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } elseif (preg_match('/^--model=(.+)$/', $arg, $m)) {
        $model = trim($m[1]);
    } elseif (preg_match('/^--gateway=(.+)$/', $arg, $m)) {
        $gateway = rtrim(trim($m[1]), '/');
    } elseif ($arg === '--no-apply-speakers') {
        $applySpeakers = false;
    } elseif ($arg === '--apply-transcription') {
        $applyTranscription = true;
    }
}
if ($ids === []) {
    $ids = [291273, 291407, 291361];
}
$ids = array_values(array_unique($ids));

if (!AsrDiaRunsRepository::available()) {
    fwrite(STDERR, "base_asr_dia_runs missing\n");
    exit(1);
}

$host = EdgeHosts::find(1);
if ($host === null) {
    fwrite(STDERR, "edge host #1 missing\n");
    exit(1);
}
$origBase = (string)$host['BASE_URL'];
if ($gateway !== '') {
    Database::pdo()->prepare('UPDATE base_edge_hosts SET BASE_URL = ? WHERE ID = 1')->execute([$gateway]);
    $host = EdgeHosts::find(1);
}
EdgeHosts::setPlugin(1, 'asr_dia', true, 10);

$t0 = microtime(true);
$runIds = AsrDiaRunsRepository::enqueueRecords($ids, $model, $applySpeakers, $applyTranscription);
$sync = AsrDiaPushDispatcher::syncAll();
$sentAt = microtime(true);

$deadline = time() + 1800;
$last = '';
while (time() < $deadline) {
    AsrDiaPushDispatcher::syncAll();
    $counts = AsrDiaRunsRepository::countsForRunIds($runIds);
    $done = (int)$counts['done'] + (int)$counts['failed'];
    $msg = sprintf(
        'pending=%d queued=%d done=%d failed=%d',
        $counts['pending'],
        $counts['queued'],
        $counts['done'],
        $counts['failed']
    );
    if ($msg !== $last) {
        fwrite(STDERR, $msg . "\n");
        $last = $msg;
    }
    if ($done >= count($runIds)) {
        break;
    }
    usleep(1500000);
}

$elapsed = microtime(true) - $t0;
$latest = AsrDiaRunsRepository::latestForRecords($ids);

// Restore public gateway URL for production-facing config.
if ($gateway !== '' && $origBase !== '') {
    Database::pdo()->prepare('UPDATE base_edge_hosts SET BASE_URL = ? WHERE ID = 1')->execute([$origBase]);
}

$play = [];
$ph = implode(',', array_fill(0, count($ids), '?'));
$st = Database::pdo()->prepare("SELECT ID, PLAYTIME, ID_ADMIN FROM base_records WHERE ID IN ($ph)");
$st->execute($ids);
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $play[(int)$row['ID']] = [
        'playtime' => (int)$row['PLAYTIME'],
        'admin' => (int)$row['ID_ADMIN'],
    ];
}

$rows = [];
$pipeSum = 0.0;
$wallSum = 0.0;
$audioSum = 0;
foreach ($latest as $r) {
    $rid = (int)$r['record_id'];
    $pt = (int)($play[$rid]['playtime'] ?? 0);
    $audioSum += $pt;
    $pipe = $r['pipeline_sec'] ?? null;
    $wall = $r['wall_sec'] ?? $r['job_wall_sec'] ?? null;
    if (is_float($pipe) || is_int($pipe)) {
        $pipeSum += (float)$pipe;
    }
    if (is_float($wall) || is_int($wall)) {
        $wallSum += (float)$wall;
    }
    $rtf = ($pt > 0 && $wall !== null) ? round((float)$wall / $pt, 3) : null;
    $rows[] = [
        'record_id' => $rid,
        'admin' => (int)($play[$rid]['admin'] ?? 0),
        'playtime_sec' => $pt,
        'status' => (string)$r['status'],
        'asr_model' => (string)$r['asr_model'],
        'cues' => (int)$r['cues'],
        'matched_cues' => (int)$r['matched_cues'],
        'matched_pct' => (float)$r['matched_pct'],
        'download_sec' => $r['download_sec'],
        'pipeline_sec' => $pipe,
        'wall_sec' => $wall,
        'rtf' => $rtf,
        'error' => (string)$r['error'],
    ];
}

$report = [
    'ok' => true,
    'model' => $model,
    'gateway' => $gateway,
    'ids' => $ids,
    'run_ids' => $runIds,
    'sync_first' => $sync,
    'batch_wall_sec' => round($elapsed, 1),
    'push_latency_sec' => round($sentAt - $t0, 2),
    'audio_sec_sum' => $audioSum,
    'pipeline_sec_sum' => round($pipeSum, 1),
    'wall_sec_sum' => round($wallSum, 1),
    'rtf_mean' => $audioSum > 0 && $wallSum > 0 ? round($wallSum / $audioSum, 3) : null,
    'rows' => $rows,
    'note' => 'wall_sec = download+pipeline on edge; rtf = wall/playtime. Old cue-diarize is post-ASR only — compare pipeline_sec to diarize worker times, not full whisper.',
];
$path = base_path('docs/agents/asr_dia/sample_run_' . preg_replace('/[^a-z0-9_-]+/i', '_', $model) . '.json');
file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
