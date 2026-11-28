#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * audio.transcripts.batch completeness + audio.activity.summary cross-check.
 *
 * Batch: every transcript is rebuilt from the cursor slices (offset + line_from) and compared line by line with
 *  (1) the audio.get path (RecordsRepository::forAgent), (2) the stored source: delivered timecodes are an in-order
 *  subsequence of the source lines and delivered + dropped garbage == all source lines. Also: day sweeps with context
 *  (every selected record exactly once and complete, context whole or listed in context_omitted and then readable),
 *  budget respected, selection_complete / transcripts_complete are independent and consistent.
 * Activity: per employee and hour counts, record ids, playtime, speech, dictionary hits and speakers are compared with
 *  independent SQL.
 * Exit: 0 ok, 1 fail; SKIP when audio is off or there are no transcripts.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

if (!ModuleManager::isEnabled('audio') || !class_exists('TranscriptsBatch', false)
    || !class_exists('ActivitySummary', false) || !Database::tableExists('base_records')
) {
    echo "SKIP audio disabled\n";
    exit(0);
}
$pdo = Database::pdo();
if ($pdo->query("SELECT 1 FROM base_records WHERE TRANSCRIPTION LIKE '%[%' LIMIT 1")->fetchColumn() === false) {
    echo "SKIP no transcripts\n";
    exit(0);
}

/** @return list<string> */
function splitLines(string $t): array
{
    return $t === '' ? [] : explode("\n", $t);
}

function tcOf(string $line): string
{
    return preg_match('/^\[[^\]]*\]/', $line, $m) === 1 ? $m[0] : '';
}

/** @return list<string> lines of the audio.get path */
function expectedLines(int $id): array
{
    $fa = RecordsRepository::forAgent(RecordsRepository::find($id), true);
    return splitLines((string)($fa['transcription'] ?? ''));
}

function firstDiff(array $a, array $b): string
{
    $n = max(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
            return 'first diff at line ' . ($i + 1) . ' (got ' . count($a) . ', expected ' . count($b) . ')';
        }
    }
    return 'equal';
}

/**
 * Source accounting for one record: raw timecodes, and the garbage-filter result of the corrected text.
 *
 * @return array{raw_tc:list<string>,kept:int,trash:int,chunks:int}
 */
function sourceInfo(int $id): array
{
    $raw = (string)Database::pdo()->query('SELECT TRANSCRIPTION FROM base_records WHERE ID=' . $id)->fetchColumn();
    $parts = explode('[', $raw);
    array_shift($parts);
    $tc = [];
    $chunks = 0;
    foreach ($parts as $chunk) {
        if (strpos($chunk, ']') === false) {
            continue;
        }
        $chunks++;
        $tc[] = '[' . explode(']', $chunk, 2)[0] . ']';
    }
    $f = TranscriptGarbage::filterTranscription(
        TranscriptCorrector::forRecord($id, $raw),
        RecordsRepository::trashPhrases()
    );
    return ['raw_tc' => $tc, 'kept' => (int)$f['lines'], 'trash' => (int)$f['trash'], 'chunks' => $chunks];
}

/** True when $needle is an in-order subsequence of $hay. */
function isSubsequence(array $needle, array $hay): bool
{
    $i = 0;
    $n = count($needle);
    foreach ($hay as $h) {
        if ($i < $n && $needle[$i] === $h) {
            $i++;
        }
    }
    return $i === $n;
}

/**
 * Read one record through the cursor only (ids mode). Returns lines plus protocol problems.
 *
 * @return array{lines:list<string>,calls:int,issues:list<string>,ctx:array<int,array<string,mixed>>,omitted:list<int>}
 */
function readRecord(int $id, int $maxBytes, int $ctxN): array
{
    $lines = [];
    $issues = [];
    $calls = 0;
    $offset = 0;
    $lineFrom = 1;
    $prevTo = 0;
    $ctx = [];
    $omitted = [];
    do {
        $res = TranscriptsBatch::run([
            'ids' => (string)$id, 'context_before' => (string)$ctxN, 'max_bytes' => (string)$maxBytes,
            'offset' => (string)$offset, 'line_from' => (string)$lineFrom,
        ]);
        $calls++;
        if (empty($res['ok'])) {
            $issues[] = 'run failed: ' . (string)($res['error'] ?? '');
            break;
        }
        $sel = null;
        foreach ($res['items'] as $it) {
            if (!empty($it['context'])) {
                $ctx[(int)$it['id']] ??= $it;
            } elseif ((int)$it['id'] === $id) {
                $sel = $it;
            }
        }
        if ($sel === null) {
            $issues[] = "selected item missing on call $calls";
            break;
        }
        $seg = splitLines((string)$sel['transcription']);
        if ((int)$sel['line_from'] !== $prevTo + 1 && $calls > 1) {
            $issues[] = "slice not contiguous on call $calls (line_from {$sel['line_from']} after $prevTo)";
        }
        if ($seg !== [] && count($seg) !== (int)$sel['line_to'] - (int)$sel['line_from'] + 1) {
            $issues[] = "line_from/line_to do not match the slice on call $calls";
        }
        $prevTo = (int)$sel['line_to'];
        foreach ($seg as $l) {
            $lines[] = $l;
        }
        $complete = !empty($sel['complete']);
        if ($complete !== (bool)$res['transcripts_complete']) {
            $issues[] = "transcripts_complete disagrees with item.complete on call $calls";
        }
        if ($res['selection_complete'] !== ($res['next_offset'] === null)) {
            $issues[] = "selection_complete disagrees with next_offset on call $calls";
        }
        if (!$complete) {
            if ($res['next_offset'] !== $offset || (int)($res['next_line_from'] ?? 0) !== $prevTo + 1) {
                $issues[] = "cursor does not stay on the partial record on call $calls";
            }
            if ($res['selection_complete']) {
                $issues[] = "selection_complete=true while the transcript is partial on call $calls";
            }
        } elseif ($res['next_offset'] !== null) {
            $issues[] = "single record complete but next_offset is not null on call $calls";
        }
        if ((int)$res['bytes'] > (int)$res['max_bytes'] && count($seg) > 1) {
            $issues[] = "page over budget on call $calls ({$res['bytes']} > {$res['max_bytes']})";
        }
        foreach ($res['context_omitted'] as $oid) {
            $omitted[(int)$oid] = (int)$oid;
        }
        $offset = $res['next_offset'];
        $lineFrom = (int)($res['next_line_from'] ?? 1);
    } while ($offset !== null && $calls < 3000);
    if ($offset !== null) {
        $issues[] = 'cursor did not finish';
    }
    return ['lines' => $lines, 'calls' => $calls, 'issues' => array_values($issues), 'ctx' => $ctx, 'omitted' => array_values($omitted)];
}

/**
 * Check a record read through the cursor against audio.get and the stored source.
 */
$checkRecord = static function (int $id, int $maxBytes, int $ctxN) use ($assert): void {
    $exp = expectedLines($id);
    $src = sourceInfo($id);
    $r = readRecord($id, $maxBytes, $ctxN);
    $label = "record $id max_bytes=$maxBytes ctx=$ctxN";
    $assert($r['issues'] === [], "$label: cursor protocol ok in {$r['calls']} call(s)" . ($r['issues'] ? ' - ' . implode('; ', array_slice($r['issues'], 0, 3)) : ''));
    $assert($r['lines'] === $exp, "$label: all " . count($exp) . ' lines equal audio.get (' . firstDiff($r['lines'], $exp) . ')');
    $got = array_map('tcOf', $r['lines']);
    $assert(isSubsequence($got, $src['raw_tc']), "$label: delivered timecodes are an in-order subsequence of the source");
    $assert(
        count($r['lines']) === $src['kept'] && $src['kept'] + $src['trash'] === $src['chunks'],
        "$label: delivered {$src['kept']} + dropped garbage {$src['trash']} == {$src['chunks']} source lines"
    );
    foreach ($r['ctx'] as $cid => $cit) {
        if (!empty($cit['complete'])) {
            $assert(splitLines((string)$cit['transcription']) === expectedLines($cid), "$label: context record $cid equals audio.get");
        }
    }
    foreach (array_slice($r['omitted'], 0, 3) as $oid) {
        $o = readRecord($oid, 4000, 0);
        $assert($o['issues'] === [] && $o['lines'] === expectedLines($oid), "$label: omitted context $oid is readable in full via ids");
    }
};

// ---- 1) single records, long first, several budgets ----
$ids = [];
foreach ($pdo->query("SELECT ID FROM base_records WHERE TRANSCRIPTION LIKE '%[%' ORDER BY LENGTH(TRANSCRIPTION) DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
    $ids[(int)$v] = true;
}
foreach ($pdo->query("SELECT ID FROM base_records WHERE TRANSCRIPTION LIKE '%[%' ORDER BY ID DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
    $ids[(int)$v] = true;
}
$longest = (int)array_key_first($ids);
foreach (array_keys($ids) as $id) {
    $checkRecord($id, 4000, 0);
    $checkRecord($id, 12000, 1);
}
$checkRecord($longest, 30000, 1);

// ---- 2) day sweeps: every selected record exactly once, complete, in order ----
$dts = $pdo->query('SELECT DT_RECORDED FROM base_records WHERE DT_RECORDED > 0 ORDER BY ID DESC LIMIT 4000')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$perDay = [];
foreach ($dts as $dt) {
    $d = date('Y-m-d', (int)$dt);
    $perDay[$d] = ($perDay[$d] ?? 0) + 1;
}
arsort($perDay);
$date = (string)array_key_first($perDay);
[$Y, $M, $D] = array_map('intval', explode('-', $date));

/**
 * @param array<string,mixed> $args extra arguments (limit, max_bytes, context_before, time window)
 * @param list<int> $expectedIds selected record ids in order
 */
$sweep = static function (string $label, array $args, array $expectedIds) use ($assert, $date): void {
    $segments = [];
    $order = [];
    $issues = [];
    $ctxSeen = [];
    $omitted = [];
    $offset = 0;
    $lineFrom = 1;
    $pages = 0;
    do {
        $res = TranscriptsBatch::run($args + ['date' => $date, 'offset' => (string)$offset, 'line_from' => (string)$lineFrom]);
        $pages++;
        if (empty($res['ok'])) {
            $issues[] = 'run failed: ' . (string)($res['error'] ?? '');
            break;
        }
        $allComplete = true;
        $partialOneLine = false;
        foreach ($res['items'] as $it) {
            $iid = (int)$it['id'];
            if (empty($it['complete'])) {
                $allComplete = false;
                // A cursor must always move: one line may exceed a tiny budget.
                $partialOneLine = (int)$it['line_to'] - (int)$it['line_from'] + 1 <= 1;
            }
            if (!empty($it['context'])) {
                $ctxSeen[$iid] ??= $it;
                continue;
            }
            if (!isset($segments[$iid])) {
                $segments[$iid] = [];
                $order[] = $iid;
            }
            $seg = splitLines((string)$it['transcription']);
            $have = count($segments[$iid]);
            if ($seg !== [] && (int)$it['line_from'] !== $have + 1) {
                $issues[] = "record $iid slice not contiguous (line_from {$it['line_from']}, have $have)";
            }
            foreach ($seg as $l) {
                $segments[$iid][] = $l;
            }
        }
        if ($allComplete !== (bool)$res['transcripts_complete']) {
            $issues[] = "page $pages: transcripts_complete disagrees with items";
        }
        if ($res['selection_complete'] !== ($res['next_offset'] === null)) {
            $issues[] = "page $pages: selection_complete disagrees with next_offset";
        }
        if (!$allComplete && $res['next_offset'] === null) {
            $issues[] = "page $pages: partial transcript but next_offset is null";
        }
        if ((int)$res['bytes'] > (int)$res['max_bytes'] && !$partialOneLine) {
            $issues[] = "page $pages over budget ({$res['bytes']} > {$res['max_bytes']})";
        }
        foreach ($res['context_omitted'] as $oid) {
            $omitted[(int)$oid] = (int)$oid;
        }
        $offset = $res['next_offset'];
        $lineFrom = (int)($res['next_line_from'] ?? 1);
    } while ($offset !== null && $pages < 5000);

    $assert($issues === [], "$label: protocol ok over $pages page(s)" . ($issues ? ' - ' . implode('; ', array_slice($issues, 0, 3)) : ''));
    $assert($order === $expectedIds, "$label: selected records exactly once and in order (" . count($order) . ' of ' . count($expectedIds) . ')');
    $bad = 0;
    $firstBad = '';
    foreach ($expectedIds as $rid) {
        if (($segments[$rid] ?? null) !== expectedLines($rid)) {
            $bad++;
            $firstBad = $firstBad === '' ? (string)$rid : $firstBad;
        }
    }
    $assert($bad === 0, "$label: all " . count($expectedIds) . ' transcripts are complete and equal audio.get' . ($bad ? " ($bad differ, e.g. $firstBad)" : ''));
    $ctxBad = 0;
    foreach ($ctxSeen as $cid => $cit) {
        if (!empty($cit['complete']) && splitLines((string)$cit['transcription']) !== expectedLines($cid)) {
            $ctxBad++;
        }
        if (empty($cit['complete'])) {
            $ctxBad++;
        }
    }
    $assert($ctxBad === 0, "$label: " . count($ctxSeen) . ' context records delivered whole');
    $okOmitted = true;
    foreach (array_slice(array_values($omitted), 0, 3) as $oid) {
        $o = readRecord($oid, 4000, 0);
        $okOmitted = $okOmitted && $o['issues'] === [] && $o['lines'] === expectedLines($oid);
    }
    $assert($okOmitted, "$label: " . count($omitted) . ' omitted context records readable via ids');
};

$dayRows = RecordsRepository::list(['year' => $Y, 'month' => $M, 'day' => $D, 'limit' => 0, 'order' => 'DT_RECORDED ASC, ID ASC'])['items'];
$dayIds = array_map(static fn (array $r): int => (int)$r['id'], $dayRows);
// A whole busy day is slow to verify line by line; bigger days are covered by the windows below.
if (count($dayIds) <= 250) {
    $sweep("day $date (" . count($dayIds) . ' records) limit=10 ctx=2', ['limit' => '10', 'context_before' => '2'], $dayIds);
} else {
    echo 'NOTE day ' . $date . ' has ' . count($dayIds) . " records: full-day sweep skipped (windows below)\n";
}

// Sparse selection (search): predecessors are not selected, so context records appear on most pages.
$word = 'Здравствуйте';
$hitRows = RecordsRepository::list([
    'year' => $Y, 'month' => $M, 'day' => $D, 'search' => $word, 'disabled' => 0,
    'limit' => 0, 'order' => 'DT_RECORDED ASC, ID ASC',
])['items'];
$hitIds = array_map(static fn (array $r): int => (int)$r['id'], $hitRows);
if (count($hitIds) >= 5) {
    $hitIds = array_slice($hitIds, 0, 60);
    // Select exactly these ids' day subset via search + time window ending at the last hit.
    $lastDt = 0;
    foreach ($hitRows as $r) {
        if ((int)$r['id'] === $hitIds[count($hitIds) - 1]) {
            $lastDt = (int)$r['dt_recorded'];
        }
    }
    $tt = date('H:i', $lastDt);
    $expHit = [];
    foreach ($hitRows as $r) {
        if ((int)$r['dt_recorded'] <= $lastDt - ((int)date('s', $lastDt)) + 59) {
            $expHit[] = (int)$r['id'];
        }
    }
    $sweep('search "' . $word . '" (' . count($expHit) . ' records) ctx=2', [
        'search' => $word, 'time_to' => $tt, 'disabled' => '0', 'limit' => '10', 'context_before' => '2',
    ], $expHit);
}

// A one-hour window with a tiny budget: forces partial records and multi-record pages.
if ($dayRows !== []) {
    $mid = (int)$dayRows[intdiv(count($dayRows), 2)]['dt_recorded'];
    $h1 = (int)date('G', $mid);
    $tf = sprintf('%02d:00', $h1);
    $tt = sprintf('%02d:59', $h1);
    $from = mktime(0, 0, 0, $M, $D, $Y) + $h1 * 3600;
    $to = $from + 3599;
    $winIds = [];
    foreach ($dayRows as $r) {
        $t = (int)$r['dt_recorded'];
        if ($t >= $from && $t <= $to) {
            $winIds[] = (int)$r['id'];
        }
    }
    $sweep("window $tf-$tt (" . count($winIds) . ' records) max_bytes=4000 ctx=1', [
        'time_from' => $tf, 'time_to' => $tt, 'max_bytes' => '4000', 'context_before' => '1', 'limit' => '10',
    ], $winIds);
}

// ---- 3) activity summary vs independent SQL ----
/** @return list<array<string,mixed>> */
$activityAll = static function (array $args): array {
    $emps = [];
    $offset = 0;
    $guard = 0;
    $first = null;
    $last = [];
    $legend = [];
    $laterTotals = false;
    do {
        $res = ActivitySummary::run($args + ['offset' => (string)$offset]);
        $last = $res;
        if (empty($res['ok'])) {
            return ['error' => $res, 'employees' => []];
        }
        $first ??= $res;
        if ($guard > 0 && isset($res['totals'])) {
            $laterTotals = true;
        }
        foreach ((array)$res['dicts'] as $k => $v) {
            $legend[(int)$k] = (string)$v;
        }
        foreach ($res['employees'] as $e) {
            $emps[] = $e;
        }
        $offset = $res['next_offset'];
        $guard++;
    } while ($offset !== null && $guard < 200);
    return ['employees' => $emps, 'first' => $first, 'last' => $last, 'legend' => $legend, 'later_totals' => $laterTotals, 'pages' => $guard];
};

$from = mktime(0, 0, 0, $M, $D, $Y);
$to = $from + 86399;
$st = $pdo->prepare(
    "SELECT ID, ID_ADMIN, DT_RECORDED, PLAYTIME, DISABLED,
            (CASE WHEN TRANSCRIPTION IS NOT NULL AND TRIM(TRANSCRIPTION) <> '' THEN 1 ELSE 0 END) AS has_tr
     FROM base_records WHERE DT_RECORDED >= ? AND DT_RECORDED <= ?"
);
$st->execute([$from, $to]);
$recs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$trash = RecordsRepository::trashPhrases();
$speechOf = static function (int $id, int $playtime) use ($pdo, $trash): float {
    $raw = (string)$pdo->query('SELECT TRANSCRIPTION FROM base_records WHERE ID=' . $id)->fetchColumn();
    $f = TranscriptGarbage::filterTranscription($raw, $trash);
    $sum = 0.0;
    foreach (splitLines($f['text']) as $line) {
        if (preg_match('/^\[(\d+):(\d+(?:\.\d+)?)->(\d+):(\d+(?:\.\d+)?)\]/', $line, $m) === 1) {
            $d = ((int)$m[3] * 60 + (float)$m[4]) - ((int)$m[1] * 60 + (float)$m[2]);
            if ($d > 0) {
                $sum += $d;
            }
        }
    }
    return $playtime > 0 ? min($sum, (float)$playtime) : $sum;
};
/** @var array<int,array{n:int,tr:int,dis:int,pt:int,ids:list<int>,speech:float,hours:array<int,array{n:int,ids:list<int>,pt:int,speech:float}>}> $exp */
$exp = [];
foreach ($recs as $r) {
    $a = (int)$r['ID_ADMIN'];
    $h = (int)date('G', (int)$r['DT_RECORDED']);
    $sp = (int)$r['has_tr'] === 1 ? $speechOf((int)$r['ID'], (int)$r['PLAYTIME']) : 0.0;
    $exp[$a] ??= ['n' => 0, 'tr' => 0, 'dis' => 0, 'pt' => 0, 'ids' => [], 'speech' => 0.0, 'hours' => []];
    $exp[$a]['hours'][$h] ??= ['n' => 0, 'ids' => [], 'pt' => 0, 'speech' => 0.0];
    $exp[$a]['n']++;
    $exp[$a]['tr'] += (int)$r['has_tr'];
    $exp[$a]['dis'] += (int)$r['DISABLED'] === 1 ? 1 : 0;
    $exp[$a]['pt'] += (int)$r['PLAYTIME'];
    $exp[$a]['speech'] += $sp;
    $exp[$a]['hours'][$h]['n']++;
    $exp[$a]['hours'][$h]['ids'][] = (int)$r['ID'];
    $exp[$a]['hours'][$h]['pt'] += (int)$r['PLAYTIME'];
    $exp[$a]['hours'][$h]['speech'] += $sp;
}
$act = $activityAll(['date' => $date, 'with_ids' => '1', 'max_bytes' => '30000']);
$assert(!isset($act['error']), "activity $date: runs");
$got = [];
foreach ($act['employees'] as $e) {
    $got[(int)$e['id_admin']] = $e;
}
$assert(count($got) === count($act['employees']), 'activity: no employee delivered twice across pages');
$assert(count($got) === count($exp) && array_diff(array_keys($exp), array_keys($got)) === [], 'activity: all ' . count($exp) . ' employees delivered');
$assert(!empty($act['last']['selection_complete']), 'activity: paging ends with selection_complete');
$assert((int)($act['first']['totals']['records'] ?? -1) === count($recs), 'activity: first page totals.records == ' . count($recs));
$assert(empty($act['later_totals']), 'activity: totals are only on the first page (' . $act['pages'] . ' page(s))');

$mismatch = [];
foreach ($exp as $a => $x) {
    $e = $got[$a] ?? null;
    if ($e === null) {
        $mismatch[] = "employee $a missing";
        continue;
    }
    foreach ([
        'records' => $x['n'], 'transcribed' => $x['tr'], 'disabled' => $x['dis'], 'playtime_sec' => $x['pt'],
        'speech_sec' => (int)round($x['speech']),
    ] as $k => $v) {
        if ((int)$e[$k] !== $v) {
            $mismatch[] = "employee $a $k {$e[$k]} != $v";
        }
    }
    $hoursGot = [];
    foreach ($e['hours'] as $hc) {
        $hoursGot[(int)$hc['hour']] = $hc;
    }
    if (count($hoursGot) !== count($x['hours'])) {
        $mismatch[] = "employee $a hour set differs";
    }
    foreach ($x['hours'] as $h => $hx) {
        $hc = $hoursGot[$h] ?? null;
        if ($hc === null) {
            $mismatch[] = "employee $a hour $h missing";
            continue;
        }
        $idsGot = $hc['record_ids'];
        $idsExp = $hx['ids'];
        sort($idsGot);
        sort($idsExp);
        if ($idsGot !== $idsExp) {
            $mismatch[] = "employee $a hour $h record_ids differ";
        }
        if ((int)$hc['records'] !== $hx['n'] || (int)$hc['playtime_sec'] !== $hx['pt'] || (int)$hc['speech_sec'] !== (int)round($hx['speech'])) {
            $mismatch[] = "employee $a hour $h counts differ";
        }
    }
}
$assert($mismatch === [], 'activity: records / ids / playtime / speech per employee and hour match independent SQL' . ($mismatch ? ' - ' . implode('; ', array_slice($mismatch, 0, 4)) : ''));

// Dictionary hits per employee, per hour and per dictionary.
if (Database::tableExists('base_records_instances') && Database::tableExists('base_records_keys')) {
    $q = $pdo->prepare(
        'SELECT r.ID_ADMIN AS a, r.DT_RECORDED AS dt, k.ID_DICTONARY AS d
         FROM base_records_instances i
         JOIN base_records_keys k ON k.ID = i.ID_KEY
         JOIN base_records r ON r.ID = i.ID_RECORD
         WHERE r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?'
    );
    $q->execute([$from, $to]);
    $dh = [];
    $dhHour = [];
    $dTotal = [];
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $a = (int)$row['a'];
        $h = (int)date('G', (int)$row['dt']);
        $d = (int)$row['d'];
        $dh[$a][$d] = ($dh[$a][$d] ?? 0) + 1;
        $dhHour[$a][$h][$d] = ($dhHour[$a][$h][$d] ?? 0) + 1;
        $dTotal[$d] = ($dTotal[$d] ?? 0) + 1;
    }
    $dm = [];
    foreach ($got as $a => $e) {
        $gotD = [];
        foreach ((array)($e['dicts'] ?? []) as $k => $v) {
            $gotD[(int)$k] = (int)$v;
        }
        $expD = $dh[$a] ?? [];
        ksort($gotD);
        ksort($expD);
        if ($gotD !== $expD || (int)$e['dict_hits'] !== array_sum($expD)) {
            $dm[] = "employee $a dicts differ";
        }
        foreach ($e['hours'] as $hc) {
            $gh = [];
            foreach ((array)($hc['dicts'] ?? []) as $k => $v) {
                $gh[(int)$k] = (int)$v;
            }
            $eh = $dhHour[$a][(int)$hc['hour']] ?? [];
            ksort($gh);
            ksort($eh);
            if ($gh !== $eh) {
                $dm[] = "employee $a hour {$hc['hour']} dicts differ";
            }
        }
    }
    $gotTotal = [];
    foreach ((array)($act['first']['totals']['dicts'] ?? []) as $k => $v) {
        $gotTotal[(int)$k] = (int)$v;
    }
    ksort($gotTotal);
    ksort($dTotal);
    $assert($dm === [] && $gotTotal === $dTotal, 'activity: dictionary hits per employee / hour / dictionary match independent SQL' . ($dm ? ' - ' . implode('; ', array_slice($dm, 0, 3)) : ''));
    $assert(count(array_diff(array_keys($dTotal), array_keys($act['legend']))) === 0, 'activity: dictionary legend covers every dictionary id in totals');
}

// Speakers (diarization): pick the busiest diarized day.
if (class_exists('SpeakerService', false) && SpeakerService::tablesReady()) {
    $sd = $pdo->query('SELECT r.DT_RECORDED FROM base_records_speakers s JOIN base_records r ON r.ID = s.ID_RECORD ORDER BY s.ID DESC LIMIT 3000')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $spDay = [];
    foreach ($sd as $dt) {
        $d = date('Y-m-d', (int)$dt);
        $spDay[$d] = ($spDay[$d] ?? 0) + 1;
    }
    arsort($spDay);
    if ($spDay !== []) {
        $sDate = (string)array_key_first($spDay);
        [$sy, $sm, $sdd] = array_map('intval', explode('-', $sDate));
        $sf = mktime(0, 0, 0, $sm, $sdd, $sy);
        $sq = $pdo->prepare(
            'SELECT s.ID_RECORD AS rid, r.ID_ADMIN AS a, s.MATCHED_ADMIN_ID AS m, s.IS_DOMINANT AS dom, s.TALK_SEC AS talk
             FROM base_records_speakers s JOIN base_records r ON r.ID = s.ID_RECORD
             WHERE r.DT_RECORDED >= ? AND r.DT_RECORDED <= ? ORDER BY s.ID ASC'
        );
        $sq->execute([$sf, $sf + 86399]);
        $perRec = [];
        while ($row = $sq->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['rid'];
            $perRec[$rid] ??= ['a' => (int)$row['a'], 'rows' => 0, 'talk' => 0.0, 'dom' => -1];
            $perRec[$rid]['rows']++;
            $perRec[$rid]['talk'] += (float)$row['talk'];
            if ((int)$row['dom'] === 1 && $perRec[$rid]['dom'] < 0) {
                $perRec[$rid]['dom'] = (int)$row['m'];
            }
        }
        $sx = [];
        foreach ($perRec as $r) {
            $a = $r['a'];
            $sx[$a] ??= ['diar' => 0, 'rows' => 0, 'talk' => 0.0, 'self' => 0, 'other' => 0, 'unm' => 0];
            $sx[$a]['diar']++;
            $sx[$a]['rows'] += $r['rows'];
            $sx[$a]['talk'] += $r['talk'];
            if ($r['dom'] > 0 && $a > 0 && $r['dom'] === $a) {
                $sx[$a]['self']++;
            } elseif ($r['dom'] > 0) {
                $sx[$a]['other']++;
            } else {
                $sx[$a]['unm']++;
            }
        }
        $sAct = $activityAll(['date' => $sDate, 'detail' => 'totals', 'max_bytes' => '30000']);
        $sgot = [];
        foreach ($sAct['employees'] as $e) {
            $sgot[(int)$e['id_admin']] = $e;
        }
        $sm2 = [];
        foreach ($sx as $a => $x) {
            $s = $sgot[$a]['speakers'] ?? null;
            if ($s === null) {
                $sm2[] = "employee $a speakers missing";
                continue;
            }
            if ((int)$s['diarized_records'] !== $x['diar'] || (int)$s['speaker_rows'] !== $x['rows']
                || (int)$s['talk_sec'] !== (int)round($x['talk'])
                || (int)$s['dominant_self'] !== $x['self'] || (int)$s['dominant_other'] !== $x['other']
                || (int)$s['dominant_unmatched'] !== $x['unm']
            ) {
                $sm2[] = "employee $a speakers differ";
            }
        }
        $assert($sm2 === [], "activity $sDate: speakers per employee match independent SQL (" . count($sx) . ' employees)' . ($sm2 ? ' - ' . implode('; ', array_slice($sm2, 0, 3)) : ''));
    }
}

// Paging: a tiny budget must still deliver every employee exactly once.
$tiny = $activityAll(['date' => $date, 'detail' => 'hours', 'max_bytes' => '4000']);
$ids2 = array_map(static fn (array $e): int => (int)$e['id_admin'], $tiny['employees']);
$assert(count($ids2) === count(array_unique($ids2)) && count($ids2) === count($exp), 'activity: tiny budget pages all ' . count($exp) . ' employees without gaps or repeats');

echo $failed === 0 ? "ALL OK\n" : "FAILED: $failed\n";
exit($failed === 0 ? 0 : 1);
