<?php
declare(strict_types=1);

/**
 * Relative day load % for masseurs (salary vs team average) — oper calendar parity.
 * Excludes equipment (IS_HIDDEN) and admin-fix lines from the average and from %.
 */
final class ProceduresDayLoad
{
    /**
     * @return array<int,array{pct:int,is_min:bool,salary:float}>
     */
    public static function forDay(int $tsStart, int $tsEnd, ?int $staffFilter = null): array
    {
        if ($tsStart <= 0 || $tsEnd <= $tsStart) {
            return [];
        }
        if (!class_exists(ProceduresRepository::class, false)
            || !class_exists(ProcentsRepository::class, false)
            || !ModuleManager::isEnabled('procents')
            || !Database::tableExists('base_procedures')) {
            return [];
        }
        $stats = ProceduresRepository::dayColumnStats($tsStart, $tsEnd, $staffFilter);
        $operators = is_array($stats['operators'] ?? null) ? $stats['operators'] : [];
        if ($operators === []) {
            return [];
        }
        $pay = ProcentsRepository::dayStaffPay($tsStart, $tsEnd, $operators);

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
