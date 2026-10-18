<?php
declare(strict_types=1);

/**
 * Shared gate for create + order edit: operator can do services, on shift, slot free.
 *
 * Edit policy when only the date changes and the current staff is unavailable
 * that day: reject (do not clear staff silently). Message explains not_on_shift /
 * cannot_do / slot — operator must pick another staff or another datetime.
 */
final class BookingValidate
{
    /**
     * Dense slot gate (5-min grid). Listing may thin slots; assert always uses dense forDay.
     *
     * @param list<int> $productIds
     * @param int|list<int>|null $excludeProcedureId omit procedure(s) from occupancy
     * @param array{respect_staff_shift?:bool} $opts forwarded to BookingAvailability::forDay
     * @throws InvalidArgumentException
     */
    public static function assertSlot(
        array $productIds,
        int $operatorId,
        int $dtStart,
        ?int $excludeOrderId = null,
        bool $allowPastLead = false,
        ?int $leadSec = null,
        $excludeProcedureId = null,
        array $opts = []
    ): void {
        if (!class_exists(BookingAvailability::class, false)) {
            return;
        }
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static function (int $id): bool {
                return $id > 0;
            }
        )));
        if ($productIds === []) {
            throw new InvalidArgumentException(t('booking.err.products'));
        }
        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('booking.err.operator'));
        }
        if ($dtStart < 1) {
            throw new InvalidArgumentException(t('booking.err.dt_start'));
        }

        // Align to 5-minute grid used by BookingSlots
        $mod = $dtStart % BookingSlots::INTERVAL_SEC;
        if ($mod !== 0) {
            $dtStart -= $mod;
        }

        $dateYmd = date('Y-m-d', $dtStart);
        $avail = BookingAvailability::forDay(
            $dateYmd,
            $productIds,
            $operatorId,
            $excludeOrderId,
            $allowPastLead,
            $leadSec,
            $excludeProcedureId,
            $opts
        );

        foreach ($avail['operators'] as $op) {
            if ((int)$op['id'] !== $operatorId) {
                continue;
            }
            if (!empty($op['available']) && in_array($dtStart, $op['slots'], true)) {
                return;
            }
            // On shift + eligible but this exact minute busy / outside work window
            throw new InvalidArgumentException(t('booking.err.slot'));
        }

        // Not in operators list — diagnose
        $dayStart = BookingAvailability::parseDayStart($dateYmd);
        if ($dayStart === null) {
            throw new InvalidArgumentException(t('booking.err.date'));
        }
        $onShift = false;
        if (class_exists(WorkweeksRepository::class, false)) {
            foreach (WorkweeksRepository::listForDay($dayStart) as $shift) {
                if ((int)($shift['id_operator'] ?? 0) !== $operatorId) {
                    continue;
                }
                if (!empty($shift['assist_team'])) {
                    continue;
                }
                $onShift = true;
                break;
            }
        }
        if (!$onShift) {
            throw new InvalidArgumentException(t('booking.err.not_on_shift'));
        }
        $products = BookingAvailability::loadProducts($productIds);
        if (!BookingOperatorGate::canPerform($operatorId, $products, $dayStart, false)) {
            throw new InvalidArgumentException(t('booking.err.cannot_do'));
        }
        throw new InvalidArgumentException(t('booking.err.slot'));
    }

    /**
     * Validate an advance order update payload against the slot engine.
     * Skips when status is not "new", or staff/time/products incomplete.
     *
     * @param array<string,mixed> $prev
     * @param array<string,mixed> $data merged update fields
     * @param list<array{id:int,name?:string,price?:float}>|null $cartLines
     * @throws InvalidArgumentException
     */
    public static function assertOrderUpdate(int $orderId, array $prev, array $data, ?array $cartLines): void
    {
        $status = (string)($data['status'] ?? $prev['status'] ?? '0');
        if (!in_array($status, OrdersRepository::NEW_STATUSES, true)) {
            return;
        }
        $operatorId = max(0, (int)($data['id_cosmetolog'] ?? $prev['id_cosmetolog'] ?? 0));
        $dtStart = max(0, (int)($data['dt_event'] ?? $prev['dt_event'] ?? 0));
        if ($operatorId < 1 || $dtStart < 1) {
            return;
        }

        $lines = $cartLines;
        if ($lines === null) {
            $lines = OrdersRepository::decodeCart((string)($prev['cart'] ?? ''));
        }
        $productIds = [];
        foreach ($lines as $line) {
            $pid = (int)($line['id'] ?? 0);
            if ($pid > 0) {
                $productIds[] = $pid;
            }
        }
        if ($productIds === []) {
            return;
        }

        self::assertSlot($productIds, $operatorId, $dtStart, $orderId > 0 ? $orderId : null, true);
    }

    /**
     * Procedure edit / chain relocate: validate a concrete [dtStart, dtEnd) window.
     * Uses actual procedure duration (not catalog time_sec — breaks often have 0 → 60min default).
     * Breaks/chill may end after work_hour_end (solid chill continues past closing).
     *
     * @param int|list<int>|null $excludeProcedureId
     * @param array{allow_past_work_end?:bool,allow_past_shift_end?:bool,respect_staff_shift?:bool} $opts
     * @throws InvalidArgumentException
     */
    public static function assertProcedureWindow(
        int $operatorId,
        int $dtStart,
        int $dtEnd,
        int $productId,
        $excludeProcedureId = null,
        array $opts = []
    ): void {
        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('booking.err.operator'));
        }
        if ($dtStart < 1 || $dtEnd <= $dtStart) {
            throw new InvalidArgumentException(t('booking.err.slot'));
        }
        if ($productId < 1) {
            throw new InvalidArgumentException(t('booking.err.products'));
        }
        if (!class_exists(BookingAvailability::class, false)) {
            return;
        }

        $dateYmd = date('Y-m-d', $dtStart);
        $dayStart = BookingAvailability::parseDayStart($dateYmd);
        if ($dayStart === null) {
            throw new InvalidArgumentException(t('booking.err.date'));
        }

        $onShift = false;
        $priceCol = 1;
        if (class_exists(WorkweeksRepository::class, false)) {
            foreach (WorkweeksRepository::listForDay($dayStart) as $shift) {
                if ((int)($shift['id_operator'] ?? 0) !== $operatorId) {
                    continue;
                }
                if (!empty($shift['assist_team'])) {
                    continue;
                }
                $onShift = true;
                $priceCol = (int)($shift['price_col'] ?? 1);
                break;
            }
        }
        if (!$onShift) {
            throw new InvalidArgumentException(t('booking.err.not_on_shift'));
        }

        $products = BookingAvailability::loadProducts([$productId]);
        if ($products === []) {
            throw new InvalidArgumentException(t('booking.err.products'));
        }
        if (!BookingOperatorGate::canPerform($operatorId, $products, $dayStart, false)) {
            throw new InvalidArgumentException(t('booking.err.cannot_do'));
        }

        $allowPastWorkEnd = !empty($opts['allow_past_work_end']) || !empty($opts['allow_past_shift_end']);
        if (!$allowPastWorkEnd
            && class_exists('ProceduresChain', false)
            && ProceduresChain::isBreakProductTypeId((int)($products[0]['type_id'] ?? 0))
        ) {
            $allowPastWorkEnd = true;
        }
        if (
            !$allowPastWorkEnd
            && $productId > 0
            && class_exists('TerminalsConfig', false)
            && TerminalsConfig::chillProductId() === $productId
        ) {
            $allowPastWorkEnd = true;
        }

        [$workHourEnd, $workMinuteEnd] = BookingAvailability::workDayEnd();
        $dayMonth = (int)date('n', $dayStart);
        $dayDay = (int)date('j', $dayStart);
        $dayYear = (int)date('Y', $dayStart);
        $workEnd = (int)mktime($workHourEnd, $workMinuteEnd, 59, $dayMonth, $dayDay, $dayYear);
        if (!$allowPastWorkEnd && $dtEnd > $workEnd + 1) {
            throw new InvalidArgumentException(t('booking.err.slot_past_work_end', [
                'end' => date('H:i', $dtEnd),
                'work_end' => sprintf('%02d:%02d', $workHourEnd, $workMinuteEnd),
            ]));
        }

        $respectStaffShift = !array_key_exists('respect_staff_shift', $opts) || !empty($opts['respect_staff_shift']);
        if ($respectStaffShift && !$allowPastWorkEnd && class_exists(WorkweeksRepository::class, false)) {
            $staffRange = WorkweeksRepository::shiftRangeForDay($dayStart, $priceCol);
            if ($staffRange !== null) {
                $shiftEnd = (int)$staffRange['end'];
                if ($dtEnd > $shiftEnd + 1) {
                    throw new InvalidArgumentException(t('booking.err.slot_past_shift_end', [
                        'end' => date('H:i', $dtEnd),
                        'shift_end' => date('H:i', $shiftEnd),
                    ]));
                }
            }
        }

        $dayEnd = (int)mktime(23, 59, 59, $dayMonth, $dayDay, $dayYear);
        // Occupancy scan may need past midnight when chill spills over work end.
        $scanEnd = max($dayEnd, $dtEnd) + 1;
        $used = BookingOccupancy::build($dayStart, $scanEnd, null, $excludeProcedureId);
        $busy = $used[$operatorId] ?? [];
        foreach ($busy as $key => $ev) {
            $evStart = (int)($ev['DTSTART'] ?? 0);
            $evEnd = (int)($ev['DTEND'] ?? 0);
            if ($evStart < 1 || $evEnd <= $evStart) {
                continue;
            }
            if ($evStart < $dtEnd && $evEnd > $dtStart) {
                throw new InvalidArgumentException(self::describeBusyConflict((string)$key, $ev));
            }
        }
    }

    /**
     * Human-readable blocker for procedure edit (id + service + client + time).
     *
     * @param array{DTSTART?:int,DTEND?:int,ID_GOOD?:int} $ev
     */
    public static function describeBusyConflict(string $key, array $ev): string
    {
        $time = self::formatBusyTime((int)($ev['DTSTART'] ?? 0), (int)($ev['DTEND'] ?? 0));
        if (preg_match('/^p(\d+)$/', $key, $m) === 1) {
            $pid = (int)$m[1];
            $pr = class_exists('ProceduresRepository', false)
                ? ProceduresRepository::find($pid)
                : null;
            if (is_array($pr)) {
                $name = trim((string)($pr['product_name'] ?? ''));
                if ($name === '') {
                    $name = '#' . (int)($pr['id_good'] ?? 0);
                }
                $clientId = (int)($pr['id_user'] ?? 0);
                $client = trim((string)($pr['client_name'] ?? ''));
                if ($client === '' && $clientId > 0) {
                    $client = '#' . $clientId;
                }
                return t('booking.err.slot_busy_procedure', [
                    'id' => $pid,
                    'name' => $name,
                    'client_id' => $clientId,
                    'client' => $client !== '' ? $client : '—',
                    'time' => $time,
                ]);
            }
            return t('booking.err.slot_busy_procedure', [
                'id' => $pid,
                'name' => '#' . (int)($ev['ID_GOOD'] ?? 0),
                'client_id' => 0,
                'client' => '—',
                'time' => $time,
            ]);
        }
        if (preg_match('/^e(\d+)$/', $key, $m) === 1) {
            $eid = (int)$m[1];
            $label = t('booking.err.slot_busy_event_label');
            if (class_exists('EventsRepository', false)) {
                $evRow = EventsRepository::find($eid);
                if (is_array($evRow)) {
                    $content = trim((string)($evRow['content'] ?? ''));
                    if ($content !== '') {
                        $label = $content;
                    }
                }
            }
            return t('booking.err.slot_busy_event', [
                'id' => $eid,
                'name' => $label,
                'time' => $time,
            ]);
        }
        if (preg_match('/^o(\d+)$/', $key, $m) === 1) {
            $oid = (int)$m[1];
            $clientId = 0;
            $client = '—';
            $name = t('booking.err.slot_busy_order_label');
            if (class_exists('OrdersRepository', false)) {
                $ord = OrdersRepository::find($oid);
                if (is_array($ord)) {
                    $clientId = (int)($ord['id_user'] ?? $ord['id_client'] ?? 0);
                    $client = trim((string)($ord['client_name'] ?? $ord['name'] ?? ''));
                    if ($client === '' && $clientId > 0 && class_exists('ClientsRepository', false)) {
                        $c = ClientsRepository::find($clientId);
                        if (is_array($c)) {
                            $client = trim((string)($c['name'] ?: $c['tel'] ?: ''));
                        }
                    }
                    if ($client === '') {
                        $client = $clientId > 0 ? ('#' . $clientId) : '—';
                    }
                    $lines = OrdersRepository::decodeCart((string)($ord['cart'] ?? ''));
                    $parts = [];
                    foreach ($lines as $line) {
                        $n = trim((string)($line['name'] ?? ''));
                        if ($n !== '') {
                            $parts[] = $n;
                        }
                    }
                    if ($parts !== []) {
                        $name = implode(', ', $parts);
                    }
                }
            }
            return t('booking.err.slot_busy_order', [
                'id' => $oid,
                'name' => $name,
                'client_id' => $clientId,
                'client' => $client,
                'time' => $time,
            ]);
        }
        return t('booking.err.slot_busy_unknown', ['time' => $time, 'key' => $key]);
    }

    private static function formatBusyTime(int $start, int $end): string
    {
        if ($start < 1) {
            return '—';
        }
        $a = date('H:i', $start);
        if ($end > $start) {
            return $a . '–' . date('H:i', $end);
        }
        return $a;
    }
}
