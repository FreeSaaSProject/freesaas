<?php
declare(strict_types=1);

/** Overlay rewards on audio records calendar + summary.external series. */
final class CalendarRewardOverlay
{
    /** @var array{range:string,data:array}|null */
    private static $cache = null;

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * @return array<int, array<string, array{reward:bool,ticket:int,no_records:bool,comment_id?:int}>>
     */
    private static function monthData(int $year, int $month): array
    {
        $key = $year . '-' . $month;
        if (self::$cache === null || (self::$cache['range'] ?? '') !== $key) {
            self::$cache = [
                'range' => $key,
                'data' => MonthRewards::forMonth($year, $month),
            ];
        }
        return self::$cache['data'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onCell($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!ModuleManager::isEnabled('staff_reports') || !Auth::can('staff_reports.view')) {
            return $payload;
        }
        $date = (string)($payload['date'] ?? '');
        $adminId = (int)($payload['admin_id'] ?? 0);
        if ($date === '' || $adminId < 1 || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $payload;
        }
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        $monthData = self::monthData($year, $month);
        $meta = $monthData[$day][$adminId] ?? null;
        if (!is_array($meta)) {
            return $payload;
        }

        if (class_exists('Assets', false)) {
            Assets::need('staff_reports');
        }

        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $sections[] = [
            'id' => 'staff_reports.reward',
            'order' => 55,
            'html' => View::capture('_cell_reward', [
                'meta' => $meta,
                'date' => $date,
                'admin_id' => $adminId,
            ], 'staff_reports'),
        ];
        $payload['sections'] = $sections;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onSummaryEnrich($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!ModuleManager::isEnabled('staff_reports') || !Auth::can('staff_reports.view')) {
            return $payload;
        }
        $year = (int)($payload['year'] ?? 0);
        $month = (int)($payload['month'] ?? 0);
        if ($year < 1 || $month < 1) {
            return $payload;
        }
        // Reuse monthData cache warmed by onCell — avoid a second MonthRewards::forMonth.
        $raw = self::monthData($year, $month);
        $rewardByAdmin = [];
        foreach ($raw as $emps) {
            foreach ($emps as $adminId => $meta) {
                $adminId = (int)$adminId;
                if (!isset($rewardByAdmin[$adminId])) {
                    $rewardByAdmin[$adminId] = 0;
                }
                if (!empty($meta['reward'])) {
                    $rewardByAdmin[$adminId]++;
                }
            }
        }
        $columns = is_array($payload['columns'] ?? null) ? $payload['columns'] : [];
        $columns[] = [
            'id' => 'staff_reports.rewards',
            'label' => t('staff_reports.reward.col_short'),
            'title' => t('staff_reports.reward.col_title'),
            'order' => 200,
        ];
        $payload['columns'] = $columns;

        $byAdmin = is_array($payload['by_admin'] ?? null) ? $payload['by_admin'] : [];
        foreach ($rewardByAdmin as $adminId => $n) {
            $adminId = (int)$adminId;
            if (!isset($byAdmin[$adminId])) {
                $byAdmin[$adminId] = ['cells' => []];
            }
            if (!isset($byAdmin[$adminId]['cells']) || !is_array($byAdmin[$adminId]['cells'])) {
                $byAdmin[$adminId]['cells'] = [];
            }
            $byAdmin[$adminId]['cells']['staff_reports.rewards'] = $n > 0
                ? ('🏆' . $n)
                : '';
        }
        $payload['by_admin'] = $byAdmin;

        $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : ['cells' => []];
        if (!isset($totals['cells']) || !is_array($totals['cells'])) {
            $totals['cells'] = [];
        }
        $sumRewards = 0;
        foreach ($rewardByAdmin as $n) {
            $sumRewards += (int)$n;
        }
        $totals['cells']['staff_reports.rewards'] = $sumRewards > 0 ? ('🏆' . $sumRewards) : '';
        $payload['totals'] = $totals;

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onSummaryExternal($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!ModuleManager::isEnabled('staff_reports')) {
            return $payload;
        }
        $ctx = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $year = (int)($ctx['year'] ?? 0);
        $month = (int)($ctx['month'] ?? 0);
        if ($year < 1 || $month < 1) {
            return $payload;
        }
        $agg = MonthRewards::aggregates($year, $month);
        $series = is_array($payload['series'] ?? null) ? $payload['series'] : [];
        $byEmpReward = [];
        $byEmpMiss = [];
        $totalReward = 0;
        $totalMiss = 0;
        foreach ($agg['by_admin'] as $adminId => $stats) {
            $adminId = (int)$adminId;
            $r = (int)($stats['reward_days'] ?? 0);
            $m = (int)($stats['no_reward_days'] ?? 0);
            $byEmpReward[$adminId] = $r;
            $byEmpMiss[$adminId] = $m;
            $totalReward += $r;
            $totalMiss += $m;
        }
        $series[] = [
            'key' => 'staff_reward_days',
            'title' => t('staff_reports.reward.col'),
            'unit' => 'days',
            'by_employee' => $byEmpReward,
            'total' => $totalReward,
        ];
        $series[] = [
            'key' => 'staff_no_reward_days',
            'title' => t('staff_reports.reward.no'),
            'unit' => 'days',
            'by_employee' => $byEmpMiss,
            'total' => $totalMiss,
        ];
        $payload['series'] = $series;
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onReparseAfter($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!ModuleManager::isEnabled('staff_reports')) {
            return $payload;
        }
        $date = (string)($payload['date'] ?? '');
        if ($date === '') {
            return $payload;
        }
        $log = is_callable($payload['log'] ?? null) ? $payload['log'] : null;
        if ($log) {
            $log(t('staff_reports.generate.reparse_step'));
        }
        StaffReportRunner::setLogSink($log);
        StaffReportRunner::resetLog();
        $run = StaffReportRunner::runDay($date, 0);
        StaffReportRunner::setLogSink(null);
        $payload['staff_reports'] = $run;
        if ($log && isset($run['stats'])) {
            $log('staff_reports: ok=' . (int)($run['stats']['ok'] ?? 0)
                . ' skipped=' . (int)($run['stats']['skipped'] ?? 0)
                . ' error=' . (int)($run['stats']['error'] ?? 0));
        }
        return $payload;
    }
}
