#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * A/B ASR models from asr_dia journal (same record_ids, large-v3 vs podlodka-turbo).
 *
 *   php bin/asr-dia-ab.php
 *   php bin/asr-dia-ab.php --ids=291361,291362
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');

$ids = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--ids=([\d,]+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
}
if ($ids === []) {
    $fixture = base_path('docs/agents/asr_dia/fixture_2026-11-16.json');
    if (is_file($fixture)) {
        $data = json_decode((string)file_get_contents($fixture), true);
        if (is_array($data['record_ids'] ?? null)) {
            $ids = array_map('intval', $data['record_ids']);
        }
    }
}
$ids = array_values(array_unique(array_filter($ids)));
if ($ids === [] || !AsrDiaRunsRepository::available()) {
    echo json_encode(['ok' => false, 'error' => 'no_ids_or_table'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$st = Database::pdo()->prepare(
    "SELECT ID_RECORD, ASR_MODEL, STATUS, CUES, MATCHED_CUES, MATCHED_PCT,
            CHAR_LENGTH(COALESCE(TRANSCRIPTION_NEW, '')) AS chars, DT_DONE
     FROM base_asr_dia_runs
     WHERE ID_RECORD IN ($ph) AND STATUS = 'done'
     ORDER BY ID_RECORD ASC, ASR_MODEL ASC, ID DESC"
);
$st->execute($ids);

/** @var array<int,array<string,array<string,mixed>>> $by */
$by = [];
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $rid = (int)$row['ID_RECORD'];
    $model = trim((string)$row['ASR_MODEL']);
    if ($model === '') {
        $model = 'unknown';
    }
    if (isset($by[$rid][$model])) {
        continue; // keep latest per model
    }
    $by[$rid][$model] = [
        'cues' => (int)$row['CUES'],
        'matched_cues' => (int)$row['MATCHED_CUES'],
        'matched_pct' => (float)$row['MATCHED_PCT'],
        'chars' => (int)$row['chars'],
        'dt_done' => (int)$row['DT_DONE'],
    ];
}

$pairs = [];
$aWins = 0;
$bWins = 0;
$ties = 0;
foreach ($ids as $rid) {
    $a = $by[$rid]['large-v3'] ?? null;
    $b = $by[$rid]['podlodka-turbo'] ?? null;
    $row = [
        'record_id' => $rid,
        'large-v3' => $a,
        'podlodka-turbo' => $b,
        'winner' => 'incomplete',
    ];
    if (is_array($a) && is_array($b)) {
        $ac = (int)$a['chars'];
        $bc = (int)$b['chars'];
        if ($bc > $ac * 1.05) {
            $row['winner'] = 'podlodka-turbo';
            $bWins++;
        } elseif ($ac > $bc * 1.05) {
            $row['winner'] = 'large-v3';
            $aWins++;
        } else {
            $row['winner'] = 'tie';
            $ties++;
        }
    }
    $pairs[] = $row;
}

$report = [
    'ok' => true,
    'metric' => 'chars_proxy_plus_matched (listen still required)',
    'summary' => [
        'large-v3_wins' => $aWins,
        'podlodka-turbo_wins' => $bWins,
        'ties' => $ties,
        'incomplete' => count($ids) - $aWins - $bWins - $ties,
    ],
    'pairs' => $pairs,
];
$path = base_path('docs/agents/asr_dia/ab_report.json');
file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
