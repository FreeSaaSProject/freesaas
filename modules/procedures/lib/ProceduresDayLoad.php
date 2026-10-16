<?php
declare(strict_types=1);

/**
 * Relative day load % for masseurs (salary vs team average) — oper calendar parity.
 * Excludes equipment (IS_HIDDEN) and admin-fix lines from the average and from %.
 */
final class ProceduresDayLoad
{
    /** @var array<string,array{stats:array<string,mixed>,pay:array<int,array<string,float>>,loads:array<int,array{pct:int,is_min:bool,salary:float}>}> */
    private static $bundleCache = [];

    /**
     * Stats + pay + load % for one day (request-memoized).
     *
     * @return array{
     *   stats:array{operators:array<int,array<string,mixed>>,revenue:float,restored:float,proc_count:int,promo_count:int},
     *   pay:array<int,array{salary:float,salary_fix:float,salary_terminal:float,salary_sales:float,salary_audio:float,salary_scripts:float,total:float}>,
     *   loads:array<int,array{pct:int,is_min:bool,salary:float}>
     * }
     */
    public static function bundleForDay(int $tsStart, int $tsEnd, ?int $staffFilter = null): array
    {
        $emptyStats = [
            'operators' => [],
            'revenue' => 0.0,
            'restored' => 0.0,
            'proc_count' => 0,
            'promo_count' => 0,
        ];
        $empty = ['stats' => $emptyStats, 'pay' => [], 'loads' => []];
        if ($tsStart <= 0 || $tsEnd <= $tsStart) {
            return $empty;
        }
        $staffKey = ($staffFilter !== null && $staffFilter > 0) ? (int)$staffFilter : 0;
        $cacheKey = $tsStart . '|' . $tsEnd . '|' . $staffKey;
        if (isset(self::$bundleCache[$cacheKey])) {
            return self::$bundleCache[$cacheKey];
        }
        if (!class_exists(ProceduresRepository::class, false)
            || !Database::tableExists('base_procedures')
        ) {
            return self::$bundleCache[$cacheKey] = $empty;
        }

        $stats = ProceduresRepository::dayColumnStats($tsStart, $tsEnd, $staffKey > 0 ? $staffKey : null);
        $operators = is_array($stats['operators'] ?? null) ? $stats['operators'] : [];
        $pay = [];
        $loads = [];
        if ($operators !== []
            && class_exists(ProcentsRepository::class, false)
            && ModuleManager::isEnabled('procents')
        ) {
            $pay = ProcentsRepository::dayStaffPay($tsStart, $tsEnd, $operators);
            $loads = self::loadsFromPay($operators, $pay);
        }

        return self::$bundleCache[$cacheKey] = [
            'stats' => $stats,
            'pay' => $pay,
            'loads' => $loads,
        ];
    }

    /**
     * @return array<int,array{pct:int,is_min:bool,salary:float}>
     */
    public static function forDay(int $tsStart, int $tsEnd, ?int $staffFilter = null): array
    {
        return self::bundleForDay($tsStart, $tsEnd, $staffFilter)['loads'];
    }

    /**
     * @param array<int,array<string,mixed>> $operators
     * @param array<int,array{salary?:float,salary_fix?:float}> $pay
     * @return array<int,array{pct:int,is_min:bool,salary:float}>
     */
    private static function loadsFromPay(array $operators, array $pay): array
    {
        $avgBase = 0.0;
        $avgN = 0;
        $minSalary = null;
        $minOid = 0;
        foreach ($pay as $oid => $p) {
            $oid = (int)$oid;
            if (!empty($operators[$oid]['is_hidden'])) {
                continue;
            }
            if ((float)($p['salary_fix'] ?? 0) > 0) {
                continue;
            }
            $salary = (float)($p['salary'] ?? 0);
            if ($salary <= 0) {
                continue;
            }
            $avgBase += $salary;
            $avgN++;
            if ($minSalary === null || $salary < $minSalary) {
                $minSalary = $salary;
                $minOid = $oid;
            }
        }
        if ($avgN < 1) {
            return [];
        }
        $avg = $avgBase / $avgN;

        $out = [];
        foreach ($pay as $oid => $p) {
            $oid = (int)$oid;
            if (!empty($operators[$oid]['is_hidden'])) {
                continue;
            }
            if ((float)($p['salary_fix'] ?? 0) > 0) {
                continue;
            }
            $salary = (float)($p['salary'] ?? 0);
            if ($salary <= 0) {
                continue;
            }
            $out[$oid] = [
                'pct' => (int)round(($salary / $avg) * 100),
                'is_min' => $oid === $minOid,
                'salary' => $salary,
            ];
        }
        return $out;
    }

    /**
     * Append load % to calendar shift chip labels (skip assist/admin/equipment groups).
     *
     * @param list<array<string,mixed>> $shiftLabels
     * @param array<int,array{pct:int,is_min:bool,salary:float}> $loads
     * @return list<array<string,mixed>>
     */
    public static function decorateShiftLabels(array $shiftLabels, array $loads): array
    {
        if ($loads === []) {
            return $shiftLabels;
        }
        foreach ($shiftLabels as &$sh) {
            $oid = (int)($sh['id'] ?? 0);
            $group = (int)($sh['group'] ?? 0);
            // 2=admin, 3=assist, 4=hidden/equipment — no load %.
            if ($oid < 1 || $group === 2 || $group === 3 || $group === 4) {
                continue;
            }
            if (!isset($loads[$oid])) {
                continue;
            }
            $pct = (int)$loads[$oid]['pct'];
            $sh['label'] = (string)($sh['label'] ?? '') . ' ' . $pct . '%';
            $sh['load_pct'] = $pct;
            $sh['load_min'] = !empty($loads[$oid]['is_min']);
            if (!empty($loads[$oid]['is_min'])) {
                $sh['full'] = (string)($sh['full'] ?? '') . ' · ' . $pct . '%';
            }
        }
        unset($sh);
        return $shiftLabels;
    }
}
