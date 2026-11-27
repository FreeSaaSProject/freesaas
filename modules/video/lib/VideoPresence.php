<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Presence logic ported from the VideoAnal / oper chain (pure functions, no I/O):
 *  - online decision (likespa api.php activityFrame): 5-min window, isolated presence between
 *    two absences is noise, warning when enough absences in a row for long enough;
 *  - retro filter (oper getAdminPresenceStatus): [1]0[1] and [11]00[11] fill gaps, [00]1[00] removes noise.
 *
 * Samples are dense (seconds apart); both algorithms work on fixed time buckets
 * (default 15 s, the old VideoAnal cadence) so thresholds keep their original meaning.
 *
 * Record shape (oldest → newest): ['dt' => int, 'present' => bool, 'guests' => int, 'path' => string, 'id' => int].
 */
final class VideoPresence
{
    public const BUCKET_SEC = 15;

    /**
     * Collapse dense samples into fixed buckets; a bucket is present when at least half of its samples are.
     *
     * @param list<array<string,mixed>> $recs oldest → newest
     * @return list<array{dt:int,present:bool,guests:float,n:int,first:int,last:int}> oldest → newest
     */
    public static function bucketize(array $recs, int $bucketSec = self::BUCKET_SEC): array
    {
        $bucketSec = max(1, $bucketSec);
        $out = [];
        $cur = null;
        $flush = static function (?array $c) use (&$out): void {
            if ($c === null) {
                return;
            }
            $out[] = [
                'dt' => $c['dt'],
                'present' => $c['p'] * 2 >= $c['n'],
                'guests' => $c['n'] > 0 ? $c['g'] / $c['n'] : 0.0,
                'n' => $c['n'],
                'first' => $c['first'],
                'last' => $c['last'],
            ];
        };
        foreach ($recs as $r) {
            $dt = (int)($r['dt'] ?? 0);
            $k = intdiv($dt, $bucketSec);
            if ($cur === null || $cur['k'] !== $k) {
                $flush($cur);
                $cur = ['k' => $k, 'dt' => $k * $bucketSec, 'p' => 0, 'n' => 0, 'g' => 0.0, 'first' => $dt, 'last' => $dt];
            }
            $cur['n']++;
            if (!empty($r['present'])) {
                $cur['p']++;
            }
            $cur['g'] += (float)($r['guests'] ?? 0);
            $cur['last'] = $dt;
        }
        $flush($cur);

        return $out;
    }

    /**
     * Isolated presence between two absences (indices into a newest-first list).
     *
     * @param list<bool> $presentNewestFirst
     * @return array<int,true>
     */
    public static function noiseIndices(array $presentNewestFirst): array
    {
        $noise = [];
        $n = count($presentNewestFirst);
        for ($i = 1; $i < $n - 1; $i++) {
            if ($presentNewestFirst[$i] && !$presentNewestFirst[$i - 1] && !$presentNewestFirst[$i + 1]) {
                $noise[$i] = true;
            }
        }

        return $noise;
    }

    /**
     * Online decision for the newest record of $recs (port of activityFrame).
     *
     * cfg: window (min, 5), run (min absent buckets in a row, 6), minutes (min absence span, 4),
     *      bucket (sec, 15), lookback (min, 30).
     *
     * @param list<array<string,mixed>> $recs oldest → newest, last = current sample
     * @param array<string,mixed> $cfg
     * @return array{absent:bool,warning:bool,consecutive:int,since:int,earliest:int,absence_sec:int,avg_guests:float,noise:int,last_present_dt:int}
     */
    public static function decide(array $recs, array $cfg = []): array
    {
        $none = [
            'absent' => false, 'warning' => false, 'consecutive' => 0, 'since' => 0, 'earliest' => 0,
            'absence_sec' => 0, 'avg_guests' => 0.0, 'noise' => 0, 'last_present_dt' => 0,
        ];
        if ($recs === []) {
            return $none;
        }
        $bucketSec = max(1, (int)($cfg['bucket'] ?? self::BUCKET_SEC));
        $windowSec = max(1, (int)($cfg['window'] ?? 5)) * 60;
        $lookbackSec = max($windowSec, (int)($cfg['lookback'] ?? 30) * 60);
        $run = max(1, (int)($cfg['run'] ?? 6));
        $minSince = max(0, (int)($cfg['minutes'] ?? 4)) * 60;

        $now = (int)$recs[count($recs) - 1]['dt'];
        $cut = $now - $lookbackSec;
        $inLook = [];
        foreach ($recs as $r) {
            if ((int)$r['dt'] >= $cut && (int)$r['dt'] <= $now) {
                $inLook[] = $r;
            }
        }
        $buckets = self::bucketize($inLook, $bucketSec);
        if ($buckets === []) {
            return $none;
        }
        $nb = count($buckets);
        // newest-first view
        $presentNf = [];
        $dtNf = [];
        $guestsNf = [];
        for ($i = $nb - 1; $i >= 0; $i--) {
            $presentNf[] = $buckets[$i]['present'];
            $dtNf[] = $buckets[$i]['dt'];
            $guestsNf[] = $buckets[$i]['guests'];
        }
        if ($presentNf[0]) {
            return $none;
        }

        $noiseAll = self::noiseIndices($presentNf);

        // Decision window: buckets within $windowSec of now (first index beyond window ends the scan).
        $consecutive = 0;
        $earliest = 0;
        $noiseInWindow = 0;
        foreach ($presentNf as $i => $present) {
            if ($dtNf[$i] < $now - $windowSec) {
                break;
            }
            if (!$present) {
                $consecutive++;
                $earliest = $dtNf[$i];
            } elseif (isset($noiseAll[$i])) {
                $noiseInWindow++;
            } else {
                break;
            }
        }
        $since = $earliest > 0 ? $now - $earliest : 0;

        // Absence span since the last REAL presence (noise ignored), up to the lookback.
        $lastPresentDt = 0;
        $lastPresentIdx = -1;
        foreach ($presentNf as $i => $present) {
            if ($present && !isset($noiseAll[$i])) {
                $lastPresentDt = $dtNf[$i];
                $lastPresentIdx = $i;
                break;
            }
        }
        $absenceSec = $lastPresentDt > 0 ? $now - $lastPresentDt : $now - $dtNf[$nb - 1];
        $sumGuests = 0.0;
        $cnt = 0;
        $limit = $lastPresentIdx >= 0 ? $lastPresentIdx : $nb;
        for ($i = 0; $i < $limit; $i++) {
            $sumGuests += $guestsNf[$i];
            $cnt++;
        }

        return [
            'absent' => true,
            'warning' => $consecutive >= $run && $since >= $minSince,
            'consecutive' => $consecutive,
            'since' => $since,
            'earliest' => $earliest,
            'absence_sec' => max(0, $absenceSec),
            'avg_guests' => $cnt > 0 ? round($sumGuests / $cnt, 2) : 0.0,
            'noise' => $noiseInWindow,
            'last_present_dt' => $lastPresentDt,
        ];
    }

    /**
     * Retro filter (oper getAdminPresenceStatus): returns filtered presence per input bit.
     *
     * @param list<bool> $bits oldest → newest
     * @return list<array{present:bool,filtered:bool,rule:string}>
     */
    public static function retroFilter(array $bits): array
    {
        $n = count($bits);
        $at = static fn (int $i): bool => $i >= 0 && $i < $n ? (bool)$bits[$i] : false;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $cur = $at($i);
            $p1 = $at($i - 1);
            $p2 = $at($i - 2);
            $p3 = $at($i - 3);
            $n1 = $at($i + 1);
            $n2 = $at($i + 2);
            $n3 = $at($i + 3);
            $res = $cur;
            $rule = 'none';
            if (!$cur && $p1 && $n1) {
                $res = true;
                $rule = 'gap';
            } elseif ($cur && !$p2 && !$p1 && !$n1 && !$n2) {
                $res = false;
                $rule = 'noise';
            } elseif (!$cur && $p2 && $p1 && !$n1 && $n2 && $n3) {
                $res = true;
                $rule = 'double_gap';
            } elseif (!$cur && $p3 && $p2 && !$p1 && $n1 && $n2) {
                $res = true;
                $rule = 'double_gap';
            }
            $out[] = ['present' => $res, 'filtered' => $res !== $cur, 'rule' => $rule];
        }

        return $out;
    }

    /**
     * Absence report: records → buckets → retro filter → minutes / 30-min intervals.
     *
     * @param list<array<string,mixed>> $recs oldest → newest
     * @return array{intervals:list<array<string,mixed>>,totals:array<string,mixed>}
     */
    public static function report(array $recs, int $bucketSec = self::BUCKET_SEC, int $intervalSec = 1800): array
    {
        $buckets = self::bucketize($recs, $bucketSec);
        $bits = array_map(static fn (array $b): bool => $b['present'], $buckets);
        $filtered = self::retroFilter($bits);
        $intervals = [];
        $filteredCount = 0;
        $rawAbsentMin = [];
        foreach ($buckets as $i => $b) {
            $f = $filtered[$i];
            if ($f['filtered']) {
                $filteredCount++;
            }
            $iv = intdiv($b['dt'], max(60, $intervalSec)) * max(60, $intervalSec);
            $min = intdiv($b['dt'], 60);
            if (!isset($intervals[$iv])) {
                $intervals[$iv] = ['from' => $iv, 'present' => [], 'absent' => [], 'guests' => 0.0, 'n' => 0, 'filtered' => 0];
            }
            $intervals[$iv]['guests'] += $b['guests'];
            $intervals[$iv]['n']++;
            if ($f['filtered']) {
                $intervals[$iv]['filtered']++;
            }
            if ($f['present']) {
                $intervals[$iv]['present'][$min] = true;
            } else {
                $intervals[$iv]['absent'][$min] = true;
            }
            if (!$b['present']) {
                $rawAbsentMin[$min] = true;
            }
        }
        $rows = [];
        $tp = 0;
        $ta = 0;
        foreach ($intervals as $iv) {
            // A minute with any present bucket counts as present (oper uniqueMinutesWithAdmin).
            $absentOnly = array_diff_key($iv['absent'], $iv['present']);
            $p = count($iv['present']);
            $a = count($absentOnly);
            $tp += $p;
            $ta += $a;
            $rows[] = [
                'from' => $iv['from'],
                'to' => $iv['from'] + max(60, $intervalSec),
                'present_min' => $p,
                'absent_min' => $a,
                'avg_guests' => $iv['n'] > 0 ? round($iv['guests'] / $iv['n'], 2) : 0.0,
                'filtered' => $iv['filtered'],
            ];
        }

        return [
            'intervals' => $rows,
            'totals' => [
                'present_min' => $tp,
                'absent_min' => $ta,
                'raw_absent_min' => count($rawAbsentMin),
                'filtered_buckets' => $filteredCount,
                'buckets' => count($buckets),
            ],
        ];
    }

    /**
     * Evidence frames for a warning: only absence frames, about one per minute of absence
     * (min $minFrames when available, max $maxFrames), evenly spread.
     *
     * @param list<array<string,mixed>> $candidates records with non-empty 'path' inside the filtered absence, oldest → newest
     * @return list<string> paths
     */
    public static function pickEvidence(array $candidates, float $minutes, float $fpm = 1.0, int $minFrames = 5, int $maxFrames = 10): array
    {
        $cand = [];
        foreach ($candidates as $c) {
            $p = trim((string)($c['path'] ?? ''));
            if ($p !== '') {
                $cand[] = ['dt' => (int)($c['dt'] ?? 0), 'path' => $p];
            }
        }
        if ($cand === []) {
            return [];
        }
        $maxFrames = max(1, $maxFrames);
        $target = (int)ceil(max(0.0, $minutes) * max(0.0, $fpm));
        $target = min($maxFrames, max(min($minFrames, $maxFrames), $target, 1));
        $target = min($target, count($cand));
        $count = count($cand);
        if ($target >= $count) {
            return array_column($cand, 'path');
        }
        $picked = [];
        for ($k = 0; $k < $target; $k++) {
            $idx = $target === 1 ? $count - 1 : (int)round($k * ($count - 1) / ($target - 1));
            $picked[$idx] = $cand[$idx]['path'];
        }
        ksort($picked);

        return array_values($picked);
    }
}
