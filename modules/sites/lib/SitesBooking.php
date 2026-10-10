<?php
declare(strict_types=1);

/**
 * Thin storefront bridge to the booking slot engine.
 * Slot math stays in booking; this only detects when cart needs a wizard.
 */
final class SitesBooking
{
    public static function active(): bool
    {
        if (!class_exists(ModuleManager::class, false) || !ModuleManager::isEnabled('booking')) {
            return false;
        }
        return class_exists(BookingAvailability::class, false)
            && class_exists(BookingBook::class, false)
            && class_exists(BookingCatalog::class, false);
    }

    /**
     * True when booking is on and every cart line is a bookable service.
     * Mixed goods carts keep the simple checkout (no slot).
     */
    public static function needsBooking(): bool
    {
        if (!self::active()) {
            return false;
        }
        $ids = self::productIds();
        if ($ids === []) {
            return false;
        }
        $bookable = [];
        foreach (BookingCatalog::listServices() as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid > 0) {
                $bookable[$pid] = true;
            }
        }
        foreach ($ids as $id) {
            if (!isset($bookable[$id])) {
                return false;
            }
        }
        return true;
    }

    /** Unique product ids in cart (for availability query). */
    public static function productIds(): array
    {
        $ids = [];
        foreach (SitesCart::lines() as $line) {
            $id = (int)($line['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * Flattened product ids (qty expanded) for slot duration / book.
     *
     * @return list<int>
     */
    public static function productIdsFlat(): array
    {
        $ids = [];
        foreach (SitesCart::orderLines() as $line) {
            $id = (int)($line['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Overlay meta for cart UI (FREE_TICKET threshold, hints).
     *
     * @return array{
     *   free_ticket_threshold:int,
     *   free_ticket_eligible:bool,
     *   free_ticket_hint:string,
     *   free_ticket_not_today:string
     * }
     */
    public static function cartMeta(): array
    {
        $total = SitesCart::total();
        $payload = EventBus::emit('sites.cart.booking', [
            'total' => $total,
            'product_ids' => self::productIds(),
            'free_ticket_threshold' => 0,
            'free_ticket_eligible' => false,
            'free_ticket_hint' => '',
            'free_ticket_not_today' => '',
        ]);
        return [
            'free_ticket_threshold' => max(0, (int)($payload['free_ticket_threshold'] ?? 0)),
            'free_ticket_eligible' => !empty($payload['free_ticket_eligible']),
            'free_ticket_hint' => (string)($payload['free_ticket_hint'] ?? ''),
            'free_ticket_not_today' => (string)($payload['free_ticket_not_today'] ?? ''),
        ];
    }
}
