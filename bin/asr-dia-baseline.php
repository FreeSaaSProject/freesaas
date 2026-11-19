#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Phase-0 baseline: cue vs matched speaker markup for a day (default 2026-11-16).
 *
 * Usage:
 *   php bin/asr-dia-baseline.php
 *   php bin/asr-dia-baseline.php --day=2026-11-16 --per-admin=2
 *   php bin/asr-dia-baseline.php --ids=291361,291362
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$day = '2026-11-16';
$perAdmin = 2;
$idsFilter = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--day=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $day = $m[1];
    } elseif (preg_match('/^--per-admin=(\d+)$/', $arg, $m)) {
        $perAdmin = max(1, min(10, (int)$m[1]));
    } elseif (preg_match('/^--ids=([\d,]+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $idsFilter[] = $id;
            }
        }
    }
}

$from = (int)strtotime($day . ' 00:00:00');
$to = (int)strtotime($day . ' 23:59:59');
$pdo = Database::pdo();

$cueRe = '/\[\d{1,2}:\d{2}(?:\.\d+)?->\d{1,2}:\d{2}(?:\.\d+)?\]/u';

$audioAdmins = [];
if (ModuleManager::isEnabled('workweeks') && class_exists('WorkweeksRepository', false)) {
    $shiftIds = [];
    foreach (WorkweeksRepository::listForDay($from) as $s) {
        $oid = (int)($s['id_operator'] ?? 0);
        if ($oid > 0) {
            $shiftIds[$oid] = true;
        }
    }
    $staff = array_keys($shiftIds);
    if ($staff !== [] && Database::columnExists('base_users', 'AUDIO_ANALYTICS_ENABLED')) {
        $ph = implode(',', array_fill(0, count($staff), '?'));
        $st = $pdo->prepare(
            "SELECT ID, COALESCE(NAME, '') AS name, AUDIO_ANALYTICS_ENABLED AS a
             FROM base_users WHERE ID IN ($ph)"
        );
        $st->execute($staff);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['a'])) {
                $audioAdmins[(int)$row['ID']] = (string)$row['name'];
            }
        }
    }
}

$picked = [];
if ($idsFilter !== []) {
    $ph = implode(',', array_fill(0, count($idsFilter), '?'));
    $st = $pdo->prepare(
        "SELECT ID, ID_ADMIN, FILENAME, TRANSCRIPTION
         FROM base_records
         WHERE ID IN ($ph)
           AND (DISABLED IS NULL OR DISABLED < 1)
           AND FILENAME <> '' AND DT_TRANSCRIBED > 1"
    );
    $st->execute($idsFilter);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $picked[] = $row;
    }
} else {
    foreach ($audioAdmins as $aid => $_name) {
        $st = $pdo->prepare(
            'SELECT ID, ID_ADMIN, FILENAME, TRANSCRIPTION
             FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED < 1)
               AND FILENAME <> \'\' AND DT_TRANSCRIBED > 1
             ORDER BY DT_RECORDED ASC
             LIMIT ' . (int)$perAdmin
        );
        $st->execute([$aid, $from, $to]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $picked[] = $row;
        }
    }
}

$profiles = 0;
if (Database::tableExists('base_records_speaker_profiles')) {
    $profiles = (int)$pdo->query(
        'SELECT COUNT(*) FROM base_records_speaker_profiles WHERE EMBEDDING_DIM > 0'
    )->fetchColumn();
}

$outRows = [];
$pcts = [];
foreach ($picked as $row) {
    $rid = (int)$row['ID'];
    $admin = (int)$row['ID_ADMIN'];
    $tr = (string)($row['TRANSCRIPTION'] ?? '');
    $cues = preg_match_all($cueRe, $tr) ?: 0;
    $cuesRows = 0;
    $matchedCues = 0;
    $spk = 0;
    $matchedSpk = 0;
    if (Database::tableExists('base_records_cue_speakers')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM base_records_cue_speakers WHERE ID_RECORD = ?');
        $st->execute([$rid]);
        $cuesRows = (int)$st->fetchColumn();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM base_records_cue_speakers c
             INNER JOIN base_records_speakers s
               ON s.ID_RECORD = c.ID_RECORD AND s.LOCAL_ID = c.LOCAL_ID
             WHERE c.ID_RECORD = ? AND s.MATCHED_ADMIN_ID > 0'
        );
        $st->execute([$rid]);
        $matchedCues = (int)$st->fetchColumn();
    }
    if (Database::tableExists('base_records_speakers')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM base_records_speakers WHERE ID_RECORD = ?');
        $st->execute([$rid]);
        $spk = (int)$st->fetchColumn();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM base_records_speakers WHERE ID_RECORD = ? AND MATCHED_ADMIN_ID > 0'
        );
        $st->execute([$rid]);
        $matchedSpk = (int)$st->fetchColumn();
    }
    $pct = $cues > 0 ? round(100.0 * $matchedCues / $cues, 1) : 0.0;
    $pcts[] = $pct;
    $outRows[] = [
        'record_id' => $rid,
        'admin' => $admin,
        'name' => $audioAdmins[$admin] ?? ('#' . $admin),
        'cues' => $cues,
        'cues_rows' => $cuesRows,
        'matched_cues' => $matchedCues,
        'pct' => $pct,
        'speakers' => $spk,
        'matched_speakers' => $matchedSpk,
        'filename' => (string)($row['FILENAME'] ?? ''),
    ];
}

sort($pcts);
$n = count($pcts);
$median = 0.0;
if ($n > 0) {
    $median = $n % 2 === 1
        ? (float)$pcts[intdiv($n, 2)]
        : ((float)$pcts[$n / 2 - 1] + (float)$pcts[$n / 2]) / 2.0;
}
$mean = $n > 0 ? round(array_sum($pcts) / $n, 1) : 0.0;
$needAsrDia = $median < 30.0;

$report = [
    'day' => $day,
    'ready_profiles' => $profiles,
    'shift_audio_staff' => count($audioAdmins),
    'records' => $outRows,
    'median_matched_pct' => $median,
    'mean_matched_pct' => $mean,
    'gate_threshold_pct' => 30,
    'need_asr_dia' => $needAsrDia,
    'record_ids' => array_column($outRows, 'record_id'),
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit(0);
