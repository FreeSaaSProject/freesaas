<?php
declare(strict_types=1);

final class WellnessCalendarRepository
{
    /**
     * Month overview for the current user: daily series + calendar cells.
     *
     * @return array{
     *   year:int,month:int,target_ccals:int,
     *   labels:list<string>,
     *   eaten:list<int>,burned:list<int>,target:list<int>,weight:list<?float>,
     *   days:array<string,array<string,mixed>>
     * }
     */
    public static function monthSummary(int $year, int $month): array
    {
        $userId = WellnessOwner::userId();
        [$from, $to] = WellnessOwner::monthRange($year, $month);
        $daysInMonth = (int)date('t', $from);

        $labels = [];
        $eaten = [];
        $burned = [];
        $targetLine = [];
        $weightSeries = [];
        $days = [];

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ymd = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $labels[] = (string)$d;
            $eaten[] = 0;
            $burned[] = 0;
            $targetLine[] = 0;
            $weightSeries[] = null;
            $days[$ymd] = [
                'date' => $ymd,
                'ccals' => 0,
                'carbs' => 0.0,
                'proteins' => 0.0,
                'fats' => 0.0,
                'burned' => 0,
                'weight' => null,
                'workouts' => 0,
                'health' => 0,
                'doctor_rx' => 0,
            ];
        }

        $target = FitTargetsRepository::activeAt($to);
        $targetCcals = $target ? (int)$target['ccals'] : 0;
        for ($i = 0; $i < $daysInMonth; $i++) {
            $targetLine[$i] = $targetCcals;
        }

        if (Database::tableExists('base_fit_foodlogs')) {
            $st = Database::pdo()->prepare(
                'SELECT FROM_UNIXTIME(DT, \'%Y-%m-%d\') AS d,
                        COALESCE(SUM(CCALS),0) AS ccals,
                        COALESCE(SUM(CARBS),0) AS carbs,
                        COALESCE(SUM(PROTEINS),0) AS proteins,
                        COALESCE(SUM(FATS),0) AS fats
                 FROM base_fit_foodlogs
                 WHERE ID_USER = ? AND DT BETWEEN ? AND ?
                 GROUP BY d'
            );
            $st->execute([$userId, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ymd = (string)$r['d'];
                if (!isset($days[$ymd])) {
                    continue;
                }
                $idx = (int)substr($ymd, -2) - 1;
                $cc = (int)round((float)$r['ccals']);
                $days[$ymd]['ccals'] = $cc;
                $days[$ymd]['carbs'] = round((float)$r['carbs'], 2);
                $days[$ymd]['proteins'] = round((float)$r['proteins'], 2);
                $days[$ymd]['fats'] = round((float)$r['fats'], 2);
                if ($idx >= 0 && $idx < $daysInMonth) {
                    $eaten[$idx] = $cc;
                }
            }
        }

        if (Database::tableExists('base_fitlogs')) {
            $st = Database::pdo()->prepare(
                'SELECT FROM_UNIXTIME(DT_START, \'%Y-%m-%d\') AS d,
                        COALESCE(SUM(CCALS),0) AS ccals,
                        COUNT(*) AS cnt
                 FROM base_fitlogs
                 WHERE ID_USER = ? AND DT_START BETWEEN ? AND ?
                 GROUP BY d'
            );
            $st->execute([$userId, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ymd = (string)$r['d'];
                if (!isset($days[$ymd])) {
                    continue;
                }
                $idx = (int)substr($ymd, -2) - 1;
                $cc = (int)round((float)$r['ccals']);
                $days[$ymd]['burned'] = $cc;
                $days[$ymd]['workouts'] = (int)$r['cnt'];
                if ($idx >= 0 && $idx < $daysInMonth) {
                    $burned[$idx] = $cc;
                }
            }
        }

        if (Database::tableExists('base_fit_weightlogs')) {
            $st = Database::pdo()->prepare(
                'SELECT FROM_UNIXTIME(DT, \'%Y-%m-%d\') AS d, WEIGHT AS weight
                 FROM base_fit_weightlogs
                 WHERE ID_USER = ? AND DT BETWEEN ? AND ?
                 ORDER BY DT ASC, ID ASC'
            );
            $st->execute([$userId, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ymd = (string)$r['d'];
                if (!isset($days[$ymd])) {
                    continue;
                }
                $idx = (int)substr($ymd, -2) - 1;
                $w = (float)$r['weight'];
                $days[$ymd]['weight'] = $w;
                if ($idx >= 0 && $idx < $daysInMonth) {
                    $weightSeries[$idx] = $w;
                }
            }
        }

        if (Database::tableExists('base_healths_logs')) {
            $st = Database::pdo()->prepare(
                'SELECT FROM_UNIXTIME(DT, \'%Y-%m-%d\') AS d, COUNT(*) AS cnt
                 FROM base_healths_logs
                 WHERE ID_USER = ? AND DT BETWEEN ? AND ?
                 GROUP BY d'
            );
            $st->execute([$userId, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ymd = (string)$r['d'];
                if (isset($days[$ymd])) {
                    $days[$ymd]['health'] = (int)$r['cnt'];
                }
            }
        }

        if (Database::tableExists('base_healths_recipes')) {
            $st = Database::pdo()->prepare(
                'SELECT FROM_UNIXTIME(DT, \'%Y-%m-%d\') AS d, COUNT(*) AS cnt
                 FROM base_healths_recipes
                 WHERE ID_USER = ? AND DT BETWEEN ? AND ?
                 GROUP BY d'
            );
            $st->execute([$userId, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ymd = (string)$r['d'];
                if (isset($days[$ymd])) {
                    $days[$ymd]['doctor_rx'] = (int)$r['cnt'];
                }
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'target_ccals' => $targetCcals,
            'labels' => $labels,
            'eaten' => $eaten,
            'burned' => $burned,
            'target' => $targetLine,
            'weight' => $weightSeries,
            'days' => $days,
        ];
    }
}
