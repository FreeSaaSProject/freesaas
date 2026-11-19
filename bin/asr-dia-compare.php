#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ASR quality side-by-side: prod TRANSCRIPTION vs latest asr_dia TRANSCRIPTION_NEW.
 * Also reports simple dict-key hit counts when audio_dicts keys are available.
 *
 *   php bin/asr-dia-compare.php
 *   php bin/asr-dia-compare.php --ids=291361,291362
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
    $fixture = base_path('docs/agents/asr_dia_fixture_2026-11-16.json');
    if (is_file($fixture)) {
        $data = json_decode((string)file_get_contents($fixture), true);
        if (is_array($data['record_ids'] ?? null)) {
            $ids = array_map('intval', $data['record_ids']);
        }
    }
}
$ids = array_values(array_unique(array_filter($ids)));

$keys = [];
if (Database::tableExists('base_records_keys')) {
    $st = Database::pdo()->query(
        'SELECT NAME FROM base_records_keys WHERE (DISABLED IS NULL OR DISABLED < 1) AND NAME <> \'\' LIMIT 5000'
    );
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $n = mb_strtolower(trim((string)$row['NAME']), 'UTF-8');
        if (mb_strlen($n, 'UTF-8') >= 3) {
            $keys[] = $n;
        }
    }
}

function strip_cues(string $t): string
{
    $t = preg_replace('/\[\d{1,2}:\d{2}(?:\.\d+)?->\d{1,2}:\d{2}(?:\.\d+)?\]/u', ' ', $t) ?? $t;
    return mb_strtolower(preg_replace('/\s+/u', ' ', $t) ?? $t, 'UTF-8');
}

function hit_count(string $hay, array $keys): int
{
    $n = 0;
    foreach ($keys as $k) {
        if ($k !== '' && mb_strpos($hay, $k, 0, 'UTF-8') !== false) {
            $n++;
        }
    }
    return $n;
}

$runs = AsrDiaRunsRepository::available()
    ? AsrDiaRunsRepository::latestForRecords($ids)
    : [];
$byRec = [];
foreach ($runs as $r) {
    $byRec[(int)$r['record_id']] = $r;
}

$ph = $ids === [] ? '0' : implode(',', array_fill(0, count($ids), '?'));
$st = Database::pdo()->prepare(
    "SELECT ID, ID_ADMIN, TRANSCRIPTION FROM base_records WHERE ID IN ($ph)"
);
$st->execute($ids ?: [0]);
$rows = [];
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $rid = (int)$row['ID'];
    $prod = (string)($row['TRANSCRIPTION'] ?? '');
    $run = $byRec[$rid] ?? null;
    $neu = is_array($run) ? (string)($run['transcription_new'] ?? '') : '';
    $prodPlain = strip_cues($prod);
    $newPlain = strip_cues($neu);
    $rows[] = [
        'record_id' => $rid,
        'admin' => (int)$row['ID_ADMIN'],
        'prod_chars' => mb_strlen($prodPlain, 'UTF-8'),
        'new_chars' => mb_strlen($newPlain, 'UTF-8'),
        'prod_dict_hits' => hit_count($prodPlain, $keys),
        'new_dict_hits' => $neu !== '' ? hit_count($newPlain, $keys) : null,
        'asr_model' => is_array($run) ? (string)$run['asr_model'] : '',
        'run_status' => is_array($run) ? (string)$run['status'] : 'none',
        'matched_pct' => is_array($run) ? (float)$run['matched_pct'] : null,
    ];
}

$prodHits = 0;
$newHits = 0;
$newRows = 0;
foreach ($rows as $r) {
    $prodHits += (int)$r['prod_dict_hits'];
    if ($r['new_dict_hits'] !== null) {
        $newHits += (int)$r['new_dict_hits'];
        $newRows++;
    }
}
$report = [
    'dict_keys_loaded' => count($keys),
    'summary' => [
        'records' => count($rows),
        'with_asr_dia' => $newRows,
        'prod_dict_hits_sum' => $prodHits,
        'new_dict_hits_sum' => $newHits,
        'note' => 'Side-by-side dict-key proxy; listen 5–10 fragments before model cutover',
    ],
    'rows' => $rows,
];
$outPath = base_path('docs/agents/asr_dia_quality_report.json');
file_put_contents($outPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
