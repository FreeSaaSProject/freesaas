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
     * @param list<int> $productIds
     * @throws InvalidArgumentException
     */
    public static function assertSlot(
        array $productIds,
        int $operatorId,
        int $dtStart,
        ?int $excludeOrderId = null,
        bool $allowPastLead = false
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
            $allowPastLead
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
}
