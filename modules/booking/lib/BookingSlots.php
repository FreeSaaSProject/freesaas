<?php
declare(strict_types=1);

/**
 * Port of legacy checkTimeEvents (likespa.hm/inc/api.php) without globals.
 *
 * Product times are seconds (base_products.time_sec / time_net).
 * Procedure types: multiwork + parallels cap concurrent same-type work.
 */
final class BookingSlots
{
    public const INTERVAL_SEC = 300; // 5 minutes (finest engine grid)
    /** Default client-facing list step when channel does not override (legacy quarter-hour). */
    public const LIST_INTERVAL_SEC = 900;
    public const DEFAULT_DURATION_SEC = 3600;
    /** @deprecated Use defaultLeadSec() / caller-owned lead override */
    public const LEAD_SEC = 180;

    /** @return list<int> */
    public static function allowedIntervalMinutes(): array
    {
        return [5, 10, 15, 20, 30];
    }

    /**
     * Clamp channel slot step to allowlist (minutes). Invalid → $default.
     */
    public static function normalizeIntervalMinutes($raw, int $default = 15): int
    {
        $n = is_numeric($raw) ? (int)$raw : $default;
        if (!in_array($n, self::allowedIntervalMinutes(), true)) {
            return in_array($default, self::allowedIntervalMinutes(), true) ? $default : 15;
        }
        return $n;
    }

    /** Seconds for an allowlisted minute step (or default 15). */
    public static function intervalSecFromMinutes($raw, int $defaultMinutes = 15): int
    {
        return self::normalizeIntervalMinutes($raw, $defaultMinutes) * 60;
    }

    /**
     * Accept seconds or minutes; return allowlisted seconds.
     * null → $defaultSec (usually INTERVAL_SEC or LIST_INTERVAL_SEC).
     */
    public static function normalizeIntervalSec(?int $sec, int $defaultSec = self::INTERVAL_SEC): int
    {
        if ($sec === null) {
            $sec = $defaultSec;
        }
        $sec = (int)$sec;
        if (in_array($sec, [300, 600, 900, 1200, 1800], true)) {
            return $sec;
        }
        if (in_array($sec, self::allowedIntervalMinutes(), true)) {
            return $sec * 60;
        }
        if (in_array($defaultSec, [300, 600, 900, 1200, 1800], true)) {
            return $defaultSec;
        }
        return self::INTERVAL_SEC;
    }

    /**
     * Staff/booking default lead (seconds). Product channels pass their own override.
     */
    public static function defaultLeadSec(): int
    {
        $raw = Config::get('booking', 'lead_sec', 180);
        $n = is_numeric($raw) ? (int)$raw : 180;
        return max(0, min(7200, $n));
    }

    /**
     * Thin free timestamps for UI/API listing:
     * first free slot + on-grid starts + first after a gap.
     * Default grid = 15 min (legacy); pass channel intervalSec to override.
     *
     * @param list<int>|array<int,int> $slots
     * @return list<int>
     */
    public static function filterListSlots(array $slots, ?int $intervalSec = null): array
    {
        $slots = array_values(array_unique(array_filter(
            array_map('intval', $slots),
            static fn (int $t): bool => $t > 0
        )));
        sort($slots, SORT_NUMERIC);
        if ($slots === []) {
            return [];
        }
        $step = self::normalizeIntervalSec($intervalSec, self::LIST_INTERVAL_SEC);
        $intervalMin = max(1, intdiv($step, 60));
        $gapStep = min($step, self::INTERVAL_SEC);
        $out = [];
        $prev = null;
        foreach ($slots as $i => $ts) {
            $min = (int)date('i', $ts);
            $isOnGrid = ($min % $intervalMin) === 0;
            $isFirst = $i === 0;
            $isGapStart = $prev !== null && ($ts - $prev) > $gapStep;
            if ($isFirst || $isOnGrid || $isGapStart) {
                $out[] = $ts;
            }
            $prev = $ts;
        }
        return $out;
    }

    /**
     * Cap chill the same way as check() (legacy oper caps).
     *
     * @return array{total_time:int,total_net:int,time_chill:int}
     */
    public static function durationWithChill(int $totalTime, int $totalNetTime): array
    {
        $totalTime = max(0, $totalTime);
        $totalNetTime = max(0, $totalNetTime);
        if ($totalNetTime > $totalTime) {
            $totalNetTime = $totalTime;
        }
        $timeChill = $totalTime - $totalNetTime;
        $timeChillNew = $timeChill;
        if ($timeChill > 1200) {
            $timeChillNew = 1200;
        } elseif ($totalNetTime < 3600 && $timeChill > 600) {
            $timeChillNew = 600;
        }
        if ($timeChillNew !== $timeChill) {
            $timeChill = $timeChillNew;
            $totalTime = $timeChill + $totalNetTime;
        }
        return [
            'total_time' => $totalTime,
            'total_net' => $totalNetTime,
            'time_chill' => $timeChill,
        ];
    }

    /**
     * @param list<array{id:int,time_sec:int,time_net:int,type_id:int}> $products
     * @param array<int,array<string,array{DTSTART:int,DTEND:int,ID_GOOD?:int}>> $usedTimes
     * @return array{
     *   result:bool,
     *   timeSlots:array<int,int>,
     *   totalTime:int,
     *   totalNetTime:int,
     *   timeChill:int,
     *   timeText:float,
     *   timeChillText:float
     * }
     */
    public static function check(
        array $products,
        int $adminId,
        array $usedTimes,
        int $rangeStart,
        int $rangeEnd,
        int $workHourEnd = 22,
        int $workMinuteEnd = 0,
        ?int $intervalSec = null
    ): array {
        $empty = [
            'result' => false,
            'timeSlots' => [],
            'totalTime' => 0,
            'totalNetTime' => 0,
            'timeChill' => 0,
            'timeText' => 0.0,
            'timeChillText' => 0.0,
        ];
        if ($adminId < 1 || $products === [] || $rangeEnd <= $rangeStart) {
            return $empty;
        }

        $step = self::normalizeIntervalSec($intervalSec, self::INTERVAL_SEC);
        $stepMin = max(1, intdiv($step, 60));

        $totalTime = 0;
        $totalNetTime = 0;
        $procLimits = 0;
        $lastTypeId = 0;
        foreach ($products as $p) {
            $timeSec = max(0, (int)($p['time_sec'] ?? 0));
            $timeNet = max(0, (int)($p['time_net'] ?? 0));
            if ($timeSec < 1) {
                $timeSec = self::DEFAULT_DURATION_SEC;
            }
            if ($timeNet < 1) {
                $timeNet = $timeSec;
            }
            if ($timeNet > $timeSec) {
                $timeNet = $timeSec;
            }
            $totalTime += $timeSec;
            $totalNetTime += $timeNet;
            $typeId = max(0, (int)($p['type_id'] ?? 0));
            $lastTypeId = $typeId;
            $typeMeta = self::typeMeta($typeId);
            if (!$typeMeta['multiwork']) {
                $procLimits = 1;
            } elseif ($typeMeta['parallels'] > 0) {
                $procLimits = $typeMeta['parallels'];
            }
        }

        $capped = self::durationWithChill($totalTime, $totalNetTime);
        $totalTime = $capped['total_time'];
        $totalNetTime = $capped['total_net'];
        $timeChill = $capped['time_chill'];

        $dayMonth = (int)date('n', $rangeStart);
        $dayDay = (int)date('j', $rangeStart);
        $dayYear = (int)date('Y', $rangeStart);
        $searchEventEnd = (int)mktime($workHourEnd, $workMinuteEnd, 59, $dayMonth, $dayDay, $dayYear);
        if ($rangeEnd < $searchEventEnd) {
            $searchEventEnd = $rangeEnd;
        }

        $currentH = (int)date('H', $rangeStart);
        $currentM = (int)date('i', $rangeStart);
        $firstM = 0;
        $firstH = $currentH;
        $aligned = false;
        for ($num = $currentM; $num < 60; $num++) {
            if (($num % $stepMin) === 0) {
                $firstM = $num;
                $aligned = true;
                break;
            }
        }
        if (!$aligned) {
            $firstH = $currentH + 1;
            $firstM = 0;
        }

        $timeSlots = [];
        $secondTime = (int)mktime($firstH, $firstM, 0, $dayMonth, $dayDay, $dayYear);
        if ($secondTime < $rangeStart) {
            $secondTime += $step;
            while ($secondTime < $rangeStart) {
                $secondTime += $step;
            }
        }
        // Max probes scales with coarser grids (day ≈ 24h / step).
        $maxProbes = max(50, (int)ceil(86400 / $step) + 10);
        if (($secondTime + $totalTime) <= $searchEventEnd) {
            $timeSlots[$secondTime] = $secondTime;
            $searchCheckEnd = $secondTime + $totalTime;
            $n = 0;
            for ($searchCheckStart = $secondTime; $searchCheckEnd <= $searchEventEnd; $searchCheckStart += $step) {
                $timeSlots[$searchCheckStart] = $searchCheckStart;
                $searchCheckEnd = $searchCheckStart + $totalTime;
                $n++;
                if ($n > $maxProbes) {
                    break;
                }
            }
        }

        $slotDayStr = date('Y-m-d', $rangeStart);
        foreach ($timeSlots as $timeSlot => $_) {
            $searchCheckStart = $timeSlot;
            $searchCheckEnd = $searchCheckStart + $totalTime;

            if (isset($usedTimes[$adminId])) {
                foreach ($usedTimes[$adminId] as $EVENT_DATA) {
                    $evStart = (int)($EVENT_DATA['DTSTART'] ?? 0);
                    $evEnd = (int)($EVENT_DATA['DTEND'] ?? 0);
                    if ($evStart < 1 || $evEnd <= $evStart) {
                        continue;
                    }
                    $evStartDay = date('Y-m-d', $evStart);
                    $evEndDay = date('Y-m-d', $evEnd);
                    if ($evStartDay !== $slotDayStr && $evEndDay !== $slotDayStr) {
                        continue;
                    }
                    if (self::overlaps($evStart, $evEnd, $searchCheckStart, $searchCheckEnd)) {
                        unset($timeSlots[$timeSlot]);
                        continue 2;
                    }
                }
            }

            if ($procLimits > 0 && $usedTimes !== []) {
                $limitsFound = 0;
                foreach ($usedTimes as $umtAid => $umtData) {
                    if ((int)$umtAid === $adminId) {
                        continue;
                    }
                    foreach ($umtData as $EVENT_DATA) {
                        $evStart = (int)($EVENT_DATA['DTSTART'] ?? 0);
                        $evEnd = (int)($EVENT_DATA['DTEND'] ?? 0);
                        if ($evStart < 1 || $evEnd <= $evStart) {
                            continue;
                        }
                        if (!self::overlaps($evStart, $evEnd, $searchCheckStart, $searchCheckEnd, true)) {
                            continue;
                        }
                        $gid = (int)($EVENT_DATA['ID_GOOD'] ?? 0);
                        if ($gid < 1) {
                            continue;
                        }
                        $otherType = self::productTypeId($gid);
                        if ($otherType !== $lastTypeId) {
                            continue;
                        }
                        $limitsFound++;
                        break;
                    }
                }
                if ($limitsFound >= $procLimits) {
                    unset($timeSlots[$timeSlot]);
                }
            }
        }

        return [
            'result' => $timeSlots !== [],
            'timeSlots' => $timeSlots,
            'totalTime' => $totalTime,
            'totalNetTime' => $totalNetTime,
            'timeChill' => $timeChill,
            'timeText' => round($totalNetTime / 60, 1),
            'timeChillText' => round($timeChill / 60, 1),
        ];
    }

    private static function overlaps(int $evStart, int $evEnd, int $slotStart, int $slotEnd, bool $embrace = false): bool
    {
        return ($evStart < $slotStart && $evEnd > $slotStart)
            || ($evStart > $slotStart && $evEnd < $slotEnd)
            || ($evStart < $slotEnd && $evEnd > $slotEnd)
            || ($embrace && $evStart < $slotStart && $evEnd > $slotEnd)
            || ($evStart === $slotStart)
            || ($evEnd === $slotEnd);
    }

    /** @return array{multiwork:bool,parallels:int} */
    private static function typeMeta(int $typeId): array
    {
        if ($typeId < 1 || !class_exists(ProcedureTypesRepository::class, false)) {
            return ['multiwork' => true, 'parallels' => 0];
        }
        $row = ProcedureTypesRepository::find($typeId);
        if ($row === null) {
            return ['multiwork' => true, 'parallels' => 0];
        }
        return [
            'multiwork' => !empty($row['multiwork']),
            'parallels' => max(0, (int)($row['parallels'] ?? 0)),
        ];
    }

    private static function productTypeId(int $productId): int
    {
        if ($productId < 1 || !class_exists(ProductsRepository::class, false)) {
            return 0;
        }
        $p = ProductsRepository::find($productId);
        return $p ? max(0, (int)($p['type_id'] ?? 0)) : 0;
    }
}
