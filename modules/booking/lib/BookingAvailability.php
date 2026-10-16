<?php
declare(strict_types=1);

/**
 * Day availability: staff on shift × BookingSlots against occupancy.
 */
final class BookingAvailability
{
    /**
     * @param list<int> $productIds
     * @param int|null $excludeOrderId omit order from occupancy (edit re-check)
     * @param bool $allowPastLead when true, do not push rangeStart past "now+lead" (order edit)
     * @param int|null $leadSec override lead seconds (null → booking.lead_sec / 180)
     * @param int|null $excludeProcedureId omit procedure from occupancy (procedure edit)
     * @return array{
     *   date:string,
     *   day_start:int,
     *   day_end:int,
     *   products:list<array{id:int,name:string,time_sec:int,time_net:int,type_id:int}>,
     *   total_time:int,
     *   operators:list<array{
     *     id:int,
     *     name:string,
     *     slots:list<int>,
     *     slots_hhmm:list<string>,
     *     available:bool
     *   }>
     * }
     */
    public static function forDay(
        string $dateYmd,
        array $productIds,
        ?int $onlyOperatorId = null,
        ?int $excludeOrderId = null,
        bool $allowPastLead = false,
        ?int $leadSec = null,
        ?int $excludeProcedureId = null
    ): array {
        $dayStart = self::parseDayStart($dateYmd);
        if ($dayStart === null) {
            throw new InvalidArgumentException(t('booking.err.date'));
        }
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            throw new InvalidArgumentException(t('booking.err.products'));
        }

        $products = self::loadProducts($productIds);
        if ($products === []) {
            throw new InvalidArgumentException(t('booking.err.products'));
        }

        $lead = $leadSec !== null ? max(0, min(7200, $leadSec)) : BookingSlots::defaultLeadSec();

        $dayEnd = (int)mktime(23, 59, 59, (int)date('n', $dayStart), (int)date('j', $dayStart), (int)date('Y', $dayStart));
        [$workHourStart, $workMinuteStart] = self::workDayStart();
        [$workHourEnd, $workMinuteEnd] = self::workDayEnd();
        $rangeStart = (int)mktime(
            $workHourStart,
            $workMinuteStart,
            0,
            (int)date('n', $dayStart),
            (int)date('j', $dayStart),
            (int)date('Y', $dayStart)
        );
        $todayStart = (int)mktime(0, 0, 0);
        // Past calendar days have no bookable slots (storefront / new booking).
        if (!$allowPastLead && $dayStart < $todayStart) {
            return [
                'date' => $dateYmd,
                'day_start' => $dayStart,
                'day_end' => $dayEnd,
                'products' => $products,
                'total_time' => 0,
                'empty_reason' => 'past',
                'operators' => [],
            ];
        }
        if (!$allowPastLead && $dayStart === $todayStart) {
            $rangeStart = max($rangeStart, time() + $lead);
        }

        $used = BookingOccupancy::build($dayStart, $dayEnd + 1, $excludeOrderId, $excludeProcedureId);
        $shifts = class_exists(WorkweeksRepository::class, false)
            ? WorkweeksRepository::listForDay($dayStart)
            : [];

        $shiftsOnDay = 0;
        $eligibleCount = 0;
        $operatorsOut = [];
        foreach ($shifts as $shift) {
            $opId = (int)($shift['id_operator'] ?? 0);
            if ($opId < 1) {
                continue;
            }
            $shiftsOnDay++;
            if ($onlyOperatorId !== null && $onlyOperatorId > 0 && $opId !== $onlyOperatorId) {
                continue;
            }
            // IS_HIDDEN staff stay bookable when on shift; assist_team still skipped.
            if (!empty($shift['assist_team'])) {
                continue;
            }
            if (!BookingOperatorGate::canPerform($opId, $products, $dayStart, false)) {
                continue;
            }
            $eligibleCount++;
            $check = BookingSlots::check(
                $products,
                $opId,
                $used,
                $rangeStart,
                $dayEnd,
                $workHourEnd,
                $workMinuteEnd
            );
            $slots = array_values($check['timeSlots']);
            sort($slots);
            $hhmm = [];
            foreach ($slots as $ts) {
                $hhmm[] = date('H:i', $ts);
            }
            $operatorsOut[] = [
                'id' => $opId,
                'name' => trim((string)($shift['operator_name'] ?? '')) !== ''
                    ? (string)$shift['operator_name']
                    : ('#' . $opId),
                'slots' => $slots,
                'slots_hhmm' => $hhmm,
                'available' => $check['result'],
                'total_time' => $check['totalTime'],
                'time_chill' => $check['timeChill'],
                'time_text' => $check['timeText'],
                'time_chill_text' => $check['timeChillText'],
            ];
        }

        $totalTime = 0;
        if ($operatorsOut !== []) {
            $totalTime = (int)($operatorsOut[0]['total_time'] ?? 0);
        } else {
            $probe = BookingSlots::check($products, 1, [], $rangeStart, $dayEnd, $workHourEnd, $workMinuteEnd);
            $totalTime = $probe['totalTime'];
        }

        $reason = '';
        if ($operatorsOut === []) {
            if ($shiftsOnDay < 1) {
                $reason = 'no_shifts';
            } elseif ($eligibleCount < 1) {
                $reason = 'none_can_do';
            } else {
                $reason = 'no_slots';
            }
        }

        return [
            'date' => date('Y-m-d', $dayStart),
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'products' => $products,
            'total_time' => $totalTime,
            'operators' => $operatorsOut,
            'shifts_on_day' => $shiftsOnDay,
            'eligible_on_day' => $eligibleCount,
            'empty_reason' => $reason,
        ];
    }

    public static function parseDayStart(string $dateYmd): ?int
    {
        $dateYmd = trim($dateYmd);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
            return null;
        }
        $parts = explode('-', $dateYmd);
        $ts = (int)mktime(0, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]);
        return $ts > 0 ? $ts : null;
    }

    /**
     * @param list<int> $productIds
     * @return list<array{id:int,name:string,time_sec:int,time_net:int,type_id:int}>
     */
    public static function loadProducts(array $productIds): array
    {
        $out = [];
        foreach ($productIds as $id) {
            if (!class_exists(ProductsRepository::class, false)) {
                break;
            }
            $p = ProductsRepository::find($id);
            if ($p === null) {
                continue;
            }
            $timeSec = max(0, (int)($p['time_sec'] ?? 0));
            $timeNet = max(0, (int)($p['time_net'] ?? 0));
            $out[] = [
                'id' => $id,
                'name' => (string)($p['name'] ?? ''),
                'time_sec' => $timeSec,
                'time_net' => $timeNet,
                'type_id' => max(0, (int)($p['type_id'] ?? 0)),
            ];
        }
        return $out;
    }

    /** @return array{0:int,1:int} hour, minute */
    public static function workDayStart(): array
    {
        $hour = 10;
        $minute = 0;
        if (class_exists(SystemRepository::class, false)) {
            $row = SystemRepository::get();
            if (is_array($row)) {
                if (isset($row['work_hour_start'])) {
                    $hour = max(0, min(23, (int)$row['work_hour_start']));
                }
                if (isset($row['work_minute_start'])) {
                    $minute = max(0, min(59, (int)$row['work_minute_start']));
                }
            }
        }
        return [$hour, $minute];
    }

    /** @return array{0:int,1:int} hour, minute */
    public static function workDayEnd(): array
    {
        $hour = 22;
        $minute = 0;
        if (class_exists(SystemRepository::class, false)) {
            $row = SystemRepository::get();
            if (is_array($row)) {
                if (isset($row['work_hour_end'])) {
                    $hour = max(0, min(23, (int)$row['work_hour_end']));
                }
                if (isset($row['work_minute_end'])) {
                    $minute = max(0, min(59, (int)$row['work_minute_end']));
                }
            }
        }
        return [$hour, $minute];
    }
}
