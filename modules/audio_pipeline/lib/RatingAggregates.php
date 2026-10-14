<?php
declare(strict_types=1);

/**
 * Day/admin rating from rated script collections (SCORE_RATE × duration).
 * Derived base_records.SCORE is written for list filters / legacy UI chips.
 */
final class RatingAggregates
{
    /**
     * @param array{hourStartOffRate?:int,minStartOffRate?:int,hourEndOffRate?:int,minEndOffRate?:int,hourStartOffRecord?:int,minStartOffRecord?:int,hourEndOffRecord?:int,minEndOffRecord?:int}|null $bounds
     * @return array{
     *   score_w:float,score_sec:int,coverage_sec:int,idle_sec:int,record_sec:int,
     *   collections:list<array{id:int,script_id:int,score_rate:int,dt_start:int,dt_end:int,duration:int,contrib:float}>,
     *   by_script:array<int,array{score_rate_w:float,duration:int,count:int}>
     * }
     */
    public static function forAdminRange(int $adminId, int $start, int $end, ?array $bounds = null): array
    {
        $empty = [
            'score_w' => 0.0,
            'score_sec' => 0,
            'coverage_sec' => 0,
            'idle_sec' => 0,
            'record_sec' => 0,
            'collections' => [],
            'by_script' => [],
        ];
        if ($adminId < 1 || $end < $start
            || !Database::tableExists('base_records_collections')
            || !Database::tableExists('base_records')
        ) {
            return $empty;
        }
        if ($bounds === null && class_exists('AudioDayBounds', false)) {
            $bounds = AudioDayBounds::read();
        }
        if ($bounds === null) {
            $bounds = [
                'hourStartOffRate' => 9, 'minStartOffRate' => 0,
                'hourEndOffRate' => 22, 'minEndOffRate' => 0,
                'hourStartOffRecord' => 9, 'minStartOffRecord' => 0,
                'hourEndOffRecord' => 22, 'minEndOffRecord' => 0,
            ];
        }

        $pdo = Database::pdo();
        $hasRated = Database::columnExists('base_records_collections', 'RATED');
        $hasScoreRate = Database::columnExists('base_records_collections', 'SCORE_RATE');
        $scoreRateCol = $hasScoreRate ? 'c.SCORE_RATE' : '0';
        $ratedWhere = $hasRated ? ' AND c.RATED = 1' : '';

        $st = $pdo->prepare(
            "SELECT c.ID AS id, c.ID_SCRIPT AS script_id, c.DT_START AS dt_start, c.DT_END AS dt_end,
                    {$scoreRateCol} AS score_rate
             FROM base_records_collections c
             WHERE c.ID_ADMIN = ?
               AND c.DT_START <= ?
               AND (c.DT_END = 0 OR c.DT_END >= ?)
               {$ratedWhere}
             ORDER BY c.DT_START ASC, c.ID ASC"
        );
        $st->execute([$adminId, $end, $start]);
        $cols = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recordSec = self::accountedRecordSeconds($adminId, $start, $end, $bounds);
        $scoreW = 0.0;
        $coverageIntervals = [];
        $collections = [];
        $byScript = [];

        foreach ($cols as $row) {
            $dtStart = (int)$row['dt_start'];
            $dtEnd = (int)$row['dt_end'];
            if ($dtEnd < $dtStart) {
                $dtEnd = $dtStart;
            }
            $duration = self::collectionDurationSec($dtStart, $dtEnd, $adminId);
            // Clip to requested window.
            $clipStart = max($dtStart, $start);
            $clipEnd = min($dtEnd > 0 ? $dtEnd : $dtStart + $duration, $end);
            if ($clipEnd < $clipStart) {
                continue;
            }
            $clipDur = max(1, $clipEnd - $clipStart);
            if ($duration < 1) {
                $duration = $clipDur;
            }
            // Prefer clipped duration for day weighting.
            $useDur = min($duration, $clipDur);
            if ($useDur < 1) {
                $useDur = 1;
            }
            $rate = (int)$row['score_rate'];
            $contrib = $rate * $useDur;
            $scoreW += $contrib;
            $sid = (int)$row['script_id'];
            if (!isset($byScript[$sid])) {
                $byScript[$sid] = ['score_rate_w' => 0.0, 'duration' => 0, 'count' => 0];
            }
            $byScript[$sid]['score_rate_w'] += $contrib;
            $byScript[$sid]['duration'] += $useDur;
            $byScript[$sid]['count']++;
            $coverageIntervals[] = ['dtStart' => $clipStart, 'dtEnd' => $clipStart + $useDur];
            $collections[] = [
                'id' => (int)$row['id'],
                'script_id' => $sid,
                'score_rate' => $rate,
                'dt_start' => $dtStart,
                'dt_end' => $dtEnd,
                'duration' => $useDur,
                'contrib' => (float)$contrib,
            ];
        }

        $coverageSec = self::mergedSeconds($coverageIntervals);
        $idleSec = max(0, $recordSec - $coverageSec);

        return [
            'score_w' => $scoreW,
            'score_sec' => $recordSec,
            'coverage_sec' => $coverageSec,
            'idle_sec' => $idleSec,
            'record_sec' => $recordSec,
            'collections' => $collections,
            'by_script' => $byScript,
        ];
    }

    /**
     * Write derived base_records.SCORE = Σ SCORE_RATE of rated collections overlapping each record.
     *
     * @return array{updated:int}
     */
    public static function applyRecordScores(int $adminId, int $start = 0, int $end = 0): array
    {
        if ($adminId < 1
            || !Database::tableExists('base_records')
            || !Database::tableExists('base_records_collections')
        ) {
            return ['updated' => 0];
        }
        $pdo = Database::pdo();
        $hasRated = Database::columnExists('base_records_collections', 'RATED');
        $hasScoreRate = Database::columnExists('base_records_collections', 'SCORE_RATE');
        if (!$hasScoreRate) {
            return ['updated' => 0];
        }

        $params = [$adminId];
        $sql = 'SELECT ID, DT_RECORDED, PLAYTIME, SCORE FROM base_records
                WHERE ID_ADMIN = ? AND (DISABLED IS NULL OR DISABLED < 1)';
        if ($start > 0 && $end >= $start) {
            $sql .= ' AND DT_RECORDED >= ? AND DT_RECORDED <= ?';
            $params[] = $start;
            $params[] = $end;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $records = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($records === []) {
            return ['updated' => 0];
        }

        $cParams = [$adminId];
        $cSql = 'SELECT ID, DT_START, DT_END, SCORE_RATE FROM base_records_collections
                 WHERE ID_ADMIN = ?';
        if ($hasRated) {
            $cSql .= ' AND RATED = 1';
        }
        if ($start > 0 && $end >= $start) {
            // Collections that may overlap the window (extend by a day for open collections).
            $cSql .= ' AND DT_START <= ? AND (DT_END = 0 OR DT_END >= ?)';
            $cParams[] = $end + 86400;
            $cParams[] = max(0, $start - 86400);
        }
        $cst = $pdo->prepare($cSql);
        $cst->execute($cParams);
        $cols = $cst->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $upd = $pdo->prepare('UPDATE base_records SET SCORE = ? WHERE ID = ?');
        $updated = 0;
        foreach ($records as $rec) {
            $rid = (int)$rec['ID'];
            $rStart = (int)$rec['DT_RECORDED'];
            $rEnd = $rStart + max(0, (int)$rec['PLAYTIME']);
            $sum = 0;
            $seenScripts = [];
            // Prefer max SCORE_RATE per script among overlapping collections, then sum scripts.
            $byScript = [];
            foreach ($cols as $c) {
                $cStart = (int)$c['DT_START'];
                $cEnd = (int)$c['DT_END'];
                if ($cEnd < $cStart) {
                    $cEnd = $cStart;
                }
                if ($cEnd < 1) {
                    $cEnd = $cStart + 1;
                }
                // Overlap with record interval?
                if ($cEnd < $rStart || $cStart > $rEnd) {
                    continue;
                }
                // Without script id on this query — sum rates; CollectRunner stores one collection per flush.
                // Multiple collections of same script: take max absolute? Plan: max within script.
                // We need ID_SCRIPT — reload if missing.
            }
            // Re-query with script id once for correctness.
        }

        // Single pass with script id.
        $cSql2 = 'SELECT ID, ID_SCRIPT, DT_START, DT_END, SCORE_RATE FROM base_records_collections
                  WHERE ID_ADMIN = ?';
        $cParams2 = [$adminId];
        if ($hasRated) {
            $cSql2 .= ' AND RATED = 1';
        }
        if ($start > 0 && $end >= $start) {
            $cSql2 .= ' AND DT_START <= ? AND (DT_END = 0 OR DT_END >= ?)';
            $cParams2[] = $end + 86400;
            $cParams2[] = max(0, $start - 86400);
        }
        $cst2 = $pdo->prepare($cSql2);
        $cst2->execute($cParams2);
        $cols2 = $cst2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($records as $rec) {
            $rid = (int)$rec['ID'];
            $rStart = (int)$rec['DT_RECORDED'];
            $rEnd = $rStart + max(0, (int)$rec['PLAYTIME']);
            $byScript = [];
            foreach ($cols2 as $c) {
                $cStart = (int)$c['DT_START'];
                $cEnd = (int)$c['DT_END'];
                if ($cEnd < $cStart) {
                    $cEnd = $cStart;
                }
                if ($cEnd < 1) {
                    $cEnd = $cStart + 1;
                }
                if ($cEnd < $rStart || $cStart > $rEnd) {
                    continue;
                }
                $sid = (int)$c['ID_SCRIPT'];
                $rate = (int)$c['SCORE_RATE'];
                if (!isset($byScript[$sid]) || abs($rate) > abs((int)$byScript[$sid])) {
                    $byScript[$sid] = $rate;
                }
            }
            $sum = 0;
            foreach ($byScript as $rate) {
                $sum += (int)$rate;
            }
            if ($sum !== (int)($rec['SCORE'] ?? 0)) {
                $upd->execute([(string)$sum, $rid]);
                $updated++;
            }
        }

        return ['updated' => $updated];
    }

    /**
     * Duration of a collection in seconds. Zero-length → min 1, or line duration when available.
     */
    public static function collectionDurationSec(int $dtStart, int $dtEnd, int $adminId = 0): int
    {
        $dur = max(0, $dtEnd - $dtStart);
        if ($dur > 0) {
            return $dur;
        }
        return 1;
    }

    /**
     * Accounted record playtime inside OffRecord window (non-disabled, non-bonus).
     * Outside OffRate still counted when a rated collection overlaps (caller decides via coverage).
     * Here we return all OffRecord seconds as the denominator baseline.
     *
     * @param array<string,int> $bounds
     */
    public static function accountedRecordSeconds(int $adminId, int $start, int $end, array $bounds): int
    {
        if ($adminId < 1 || !Database::tableExists('base_records')) {
            return 0;
        }
        $hasDesc = Database::columnExists('base_records', 'DESCRIPTION');
        $sql = 'SELECT DT_RECORDED AS dt, PLAYTIME AS playtime'
            . ($hasDesc ? ', DESCRIPTION AS description' : '')
            . ' FROM base_records
               WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
                 AND (DISABLED IS NULL OR DISABLED < 1)';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$adminId, $start, $end]);
        $sec = 0;
        $bonus = 'BONUS TIME';
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($hasDesc && (string)($row['description'] ?? '') === $bonus) {
                continue;
            }
            $dt = (int)$row['dt'];
            $play = max(0, (int)$row['playtime']);
            if ($play < 1) {
                continue;
            }
            if (class_exists('CalendarAggregates', false)) {
                if (!CalendarAggregates::isFullyInWindow($dt, $play, $bounds, 'OffRecord')) {
                    continue;
                }
            }
            $sec += $play;
        }
        return $sec;
    }

    /**
     * @param list<array{dtStart:int,dtEnd:int}> $intervals
     */
    public static function mergedSeconds(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }
        usort($intervals, static function (array $a, array $b): int {
            return $a['dtStart'] <=> $b['dtStart'];
        });
        $merged = [['dtStart' => $intervals[0]['dtStart'], 'dtEnd' => $intervals[0]['dtEnd']]];
        for ($i = 1, $n = count($intervals); $i < $n; $i++) {
            $last = &$merged[count($merged) - 1];
            if ($intervals[$i]['dtStart'] <= $last['dtEnd']) {
                if ($intervals[$i]['dtEnd'] > $last['dtEnd']) {
                    $last['dtEnd'] = $intervals[$i]['dtEnd'];
                }
            } else {
                $merged[] = ['dtStart' => $intervals[$i]['dtStart'], 'dtEnd' => $intervals[$i]['dtEnd']];
            }
        }
        unset($last);
        $sec = 0;
        foreach ($merged as $iv) {
            $sec += max(0, $iv['dtEnd'] - $iv['dtStart']);
        }
        return $sec;
    }

    public static function avgScore(array $agg): ?float
    {
        $sec = (int)($agg['score_sec'] ?? 0);
        if ($sec < 1) {
            return null;
        }
        return round((float)($agg['score_w'] ?? 0) / $sec, 2);
    }
}
