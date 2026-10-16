<?php
declare(strict_types=1);

/**
 * Thin storefront bridge to the booking slot engine.
 * Slot math stays in booking; this only detects when cart needs a wizard
 * and shapes public master cards (first name + photo, no full FIO).
 */
final class SitesBooking
{
    private const MASTERS_LOOKAHEAD_DAYS = 14;

    public static function active(): bool
    {
        if (!class_exists(ModuleManager::class, false)) {
            return false;
        }
        $on = ModuleManager::isEnabled('booking')
            || (ModuleManager::isConfigEnabled('booking')
                && class_exists(BookingAvailability::class, false));
        if (!$on) {
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
     * Public first name only (legacy eventCreator2: second word of FIO).
     */
    public static function publicDisplayName(string $fullName, int $operatorId = 0): string
    {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
        if ($fullName !== '') {
            $parts = preg_split('/\s+/u', $fullName) ?: [];
            if (count($parts) >= 2) {
                return (string)$parts[1];
            }
            if (isset($parts[0]) && $parts[0] !== '') {
                return (string)$parts[0];
            }
        }
        return $operatorId > 0
            ? t('sites.cart.master_n', ['n' => $operatorId])
            : t('sites.cart.master_fallback');
    }

    /**
     * @return array{id:int,display_name:string,photo:string,initial:string}
     */
    public static function operatorPublicCard(int $operatorId, string $fallbackName = ''): array
    {
        $name = $fallbackName;
        $photo = '';
        $row = self::loadPublicStaff($operatorId);
        if ($row !== null) {
            if (trim((string)($row['name'] ?? '')) !== '') {
                $name = (string)$row['name'];
            }
            $photo = trim((string)($row['photo'] ?? ''));
        }
        $display = self::publicDisplayName($name, $operatorId);
        $initial = mb_strtoupper(mb_substr($display, 0, 1));
        return [
            'id' => $operatorId,
            'display_name' => $display,
            'photo' => $photo,
            'initial' => $initial !== '' ? $initial : '•',
        ];
    }

    /**
     * Masters with at least one free slot in the next N days (for master-first mode).
     *
     * @param list<int> $productIds
     * @return list<array{id:int,display_name:string,photo:string,initial:string,first_date:string,slots_hint:int}>
     */
    public static function listMastersAhead(array $productIds, int $days = self::MASTERS_LOOKAHEAD_DAYS): array
    {
        $days = max(1, min(21, $days));
        $seen = [];
        $today = (int)mktime(0, 0, 0);
        for ($i = 0; $i < $days; $i++) {
            $dayStart = $today + ($i * 86400);
            $ymd = date('Y-m-d', $dayStart);
            try {
                $av = BookingAvailability::forDay($ymd, $productIds, null, null, false, self::leadSec());
            } catch (Throwable $e) {
                continue;
            }
            foreach ($av['operators'] ?? [] as $op) {
                if (empty($op['available']) || empty($op['slots'])) {
                    continue;
                }
                $id = (int)($op['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }
                $card = self::operatorPublicCard($id, (string)($op['name'] ?? ''));
                $thinned = self::filterClientSlots(array_map('intval', $op['slots'] ?? []));
                $seen[$id] = $card + [
                    'first_date' => $ymd,
                    'slots_hint' => count($thinned),
                ];
            }
        }
        $out = array_values($seen);
        usort($out, static function (array $a, array $b): int {
            return strcmp((string)$a['display_name'], (string)$b['display_name']);
        });
        return $out;
    }

    /**
     * Dates with slots for one master (master-first step 2).
     * Pass $operatorId = 0 for any master (union).
     *
     * @param list<int> $productIds
     * @return list<array{date:string,slots:int}>
     */
    public static function listDatesForOperator(array $productIds, int $operatorId = 0, int $days = self::MASTERS_LOOKAHEAD_DAYS): array
    {
        $days = max(1, min(21, $days));
        $out = [];
        $today = (int)mktime(0, 0, 0);
        for ($i = 0; $i < $days; $i++) {
            $dayStart = $today + ($i * 86400);
            $ymd = date('Y-m-d', $dayStart);
            try {
                $av = BookingAvailability::forDay(
                    $ymd,
                    $productIds,
                    $operatorId > 0 ? $operatorId : null,
                    null,
                    false,
                    self::leadSec()
                );
            } catch (Throwable $e) {
                continue;
            }
            $slots = 0;
            foreach ($av['operators'] ?? [] as $op) {
                if (empty($op['available']) || empty($op['slots'])) {
                    continue;
                }
                if ($operatorId > 0 && (int)($op['id'] ?? 0) !== $operatorId) {
                    continue;
                }
                $slots += count(self::filterClientSlots(array_map('intval', $op['slots'] ?? [])));
            }
            if ($slots > 0) {
                $out[] = ['date' => $ymd, 'slots' => $slots];
            }
        }
        return $out;
    }

    /** Storefront lead seconds (own ini; default 10 min — not likespa/terminals). */
    public static function leadSec(): int
    {
        $raw = Config::get('sites', 'booking_lead_sec', 600);
        $n = is_numeric($raw) ? (int)$raw : 600;
        return max(0, min(7200, $n));
    }

    /**
     * Client-facing slots: first free + 15-min grid + first after a gap.
     *
     * @param list<int> $slots unix timestamps (5-min engine grid)
     * @return list<int>
     */
    public static function filterClientSlots(array $slots): array
    {
        if (class_exists(BookingSlots::class, false)) {
            return BookingSlots::filterListSlots($slots);
        }
        $slots = array_values(array_unique(array_filter(
            array_map('intval', $slots),
            static fn (int $t): bool => $t > 0
        )));
        sort($slots, SORT_NUMERIC);
        return $slots;
    }

    /**
     * Shape day availability for the public cart JSON (no full FIO).
     * Slots are thinned for client display; engine still validates any 5-min start.
     *
     * @param array<string,mixed> $data BookingAvailability::forDay result
     * @return list<array{id:int,display_name:string,photo:string,initial:string,slots:list<int>,slots_hhmm:list<string>}>
     */
    public static function publicOperatorsFromDay(array $data): array
    {
        $ops = [];
        foreach ($data['operators'] ?? [] as $op) {
            if (empty($op['available']) || empty($op['slots'])) {
                continue;
            }
            $id = (int)($op['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $card = self::operatorPublicCard($id, (string)($op['name'] ?? ''));
            $rawSlots = array_values(array_map('intval', $op['slots'] ?? []));
            $slots = self::filterClientSlots($rawSlots);
            $hhmm = [];
            foreach ($slots as $ts) {
                $hhmm[] = date('H:i', $ts);
            }
            $ops[] = $card + [
                'slots' => $slots,
                'slots_hhmm' => $hhmm,
            ];
        }
        return $ops;
    }

    /**
     * Pick any operator who has a free slot at $dtStart (for «any master»).
     *
     * @param list<int> $productIds
     */
    public static function resolveOperatorForSlot(string $ymd, array $productIds, int $dtStart): int
    {
        if ($dtStart < 1) {
            return 0;
        }
        try {
            $data = BookingAvailability::forDay($ymd, $productIds, null, null, false, self::leadSec());
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($data['operators'] ?? [] as $op) {
            if (empty($op['available']) || empty($op['slots'])) {
                continue;
            }
            $id = (int)($op['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            foreach ($op['slots'] as $ts) {
                if ((int)$ts === $dtStart) {
                    return $id;
                }
            }
        }
        return 0;
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

    /** @return array{name?:string,photo?:string}|null */
    private static function loadPublicStaff(int $operatorId): ?array
    {
        if ($operatorId < 1 || !Database::tableExists('base_users')) {
            return null;
        }
        $cols = ['id', 'name'];
        if (Database::columnExists('base_users', 'photo')) {
            $cols[] = 'photo';
        }
        $sqlCols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
        $st = Database::pdo()->prepare("SELECT $sqlCols FROM base_users WHERE id = ? LIMIT 1");
        $st->execute([$operatorId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
