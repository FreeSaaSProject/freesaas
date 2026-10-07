<?php
declare(strict_types=1);

/** Audio coverage of analysis windows (procedure slots / booking windows). */
final class CoverageCalculator
{
    /**
     * @param list<array{client_id?:int,win_start:int,win_end:int,procedure_id?:int}> $windows
     * @param array<string,mixed> $config
     * @return array{
     *   procedures_total:int,procedures_covered:int,procedures_partial:int,procedures_uncovered:int,
     *   overall_percent:float,avg_percent:float,min_percent:float
     * }
     */
    public static function compute(int $employeeId, array $windows, int $dayStart, int $dayEnd, array $config): array
    {
        $minPct = (float)($config['coverage_min_percent'] ?? 70);
        $result = [
            'procedures_total' => 0,
            'procedures_covered' => 0,
            'procedures_partial' => 0,
            'procedures_uncovered' => 0,
            'overall_percent' => 0.0,
            'avg_percent' => 0.0,
            'min_percent' => $minPct,
        ];
        if ($employeeId < 1 || $windows === [] || !Database::tableExists('base_records')) {
            return $result;
        }

        $segments = self::audioSegments($employeeId, $dayStart - 7200, $dayEnd + 7200);
        $sumWindow = 0;
        $sumCovered = 0;
        $pctSum = 0.0;
        $n = 0;

        foreach ($windows as $win) {
            $ws = (int)$win['win_start'];
            $we = (int)$win['win_end'];
            if ($we <= $ws) {
                continue;
            }
            $winDur = $we - $ws;
            $covered = self::coveredSeconds($segments, $ws, $we);
            $percent = $winDur > 0 ? ($covered / $winDur * 100) : 0.0;
            $result['procedures_total']++;
            $n++;
            $sumWindow += $winDur;
            $sumCovered += $covered;
            $pctSum += $percent;
            if ($percent <= 0) {
                $result['procedures_uncovered']++;
            } elseif ($percent >= $minPct) {
                $result['procedures_covered']++;
            } else {
                $result['procedures_partial']++;
            }
        }

        $result['overall_percent'] = $sumWindow > 0 ? round($sumCovered / $sumWindow * 100, 1) : 0.0;
        $result['avg_percent'] = $n > 0 ? round($pctSum / $n, 1) : 0.0;
        return $result;
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    private static function audioSegments(int $employeeId, int $from, int $to): array
    {
        $sql = 'SELECT DT_RECORDED AS dt, PLAYTIME AS play
                FROM base_records
                WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
                  AND (DISABLED IS NULL OR DISABLED < 1)
                  AND PLAYTIME > 0';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$employeeId, $from, $to]);
        $segs = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $s = (int)$row['dt'];
            $e = $s + (int)$row['play'];
            if ($e > $s) {
                $segs[] = [$s, $e];
            }
        }
        usort($segs, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        return $segs;
    }

    /**
     * @param list<array{0:int,1:int}> $segments
     */
    private static function coveredSeconds(array $segments, int $winStart, int $winEnd): int
    {
        $covered = 0;
        $curS = 0;
        $curE = 0;
        $open = false;
        foreach ($segments as $seg) {
            $s = max($winStart, $seg[0]);
            $e = min($winEnd, $seg[1]);
            if ($e <= $s) {
                continue;
            }
            if (!$open) {
                $curS = $s;
                $curE = $e;
                $open = true;
                continue;
            }
            if ($s <= $curE) {
                $curE = max($curE, $e);
            } else {
                $covered += ($curE - $curS);
                $curS = $s;
                $curE = $e;
            }
        }
        if ($open) {
            $covered += ($curE - $curS);
        }
        return max(0, $covered);
    }
}
