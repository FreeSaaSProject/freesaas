<?php
declare(strict_types=1);

/**
 * Fixture picking for booking/floor smoke on live DBs: an operator on today's shift
 * paired with a service they may perform and a free slot — instead of "first staff /
 * first service", which fails on real rates bans and shift rosters.
 */
final class BookingSmokeFixtures
{
    /**
     * @param array{operator?:int,product?:int,prefer_chill?:bool,lead?:int,slot_opts?:array<string,mixed>} $opts
     * @return array{operator:int,product:int,reason:string}
     */
    public static function pickPair(int $dayStart, array $opts = []): array
    {
        $forcedOp = (int)($opts['operator'] ?? 0);
        $forcedProduct = (int)($opts['product'] ?? 0);
        if ($forcedOp > 0 && $forcedProduct > 0) {
            return ['operator' => $forcedOp, 'product' => $forcedProduct, 'reason' => 'forced'];
        }
        if (!class_exists(BookingOperatorGate::class, false) || !class_exists(BookingAvailability::class, false)) {
            return ['operator' => 0, 'product' => 0, 'reason' => 'booking module unavailable'];
        }

        $operators = $forcedOp > 0 ? [$forcedOp] : self::operatorsOnShift($dayStart);
        if ($operators === []) {
            return ['operator' => 0, 'product' => 0, 'reason' => 'no non-admin staff on shift ' . date('Y-m-d', $dayStart)];
        }
        $products = $forcedProduct > 0 ? [$forcedProduct] : self::services(!empty($opts['prefer_chill']));
        if ($products === []) {
            return ['operator' => 0, 'product' => 0, 'reason' => 'no bookable services'];
        }

        $lead = isset($opts['lead']) ? (int)$opts['lead'] : null;
        $slotOpts = is_array($opts['slot_opts'] ?? null) ? $opts['slot_opts'] : [];
        foreach ($products as $pid) {
            $loaded = BookingAvailability::loadProducts([$pid]);
            if ($loaded === []) {
                continue;
            }
            foreach ($operators as $oid) {
                if (empty(BookingOperatorGate::explain($oid, $loaded, $dayStart, false)['ok'])) {
                    continue;
                }
                if (self::slots(date('Y-m-d', $dayStart), $pid, $oid, $lead, $slotOpts) === []) {
                    continue;
                }
                return ['operator' => $oid, 'product' => $pid, 'reason' => 'on shift + skill + free slot'];
            }
        }
        return [
            'operator' => 0,
            'product' => 0,
            'reason' => 'none of staff [' . implode(',', $operators) . '] has shift + skill + free slot for a bookable service today',
        ];
    }

    /**
     * Free start times for one operator (pass the caller's slot opts, e.g. TerminalsConfig::bookingSlotOpts()).
     *
     * @param array<string,mixed> $slotOpts
     * @return list<int>
     */
    public static function slots(string $dateYmd, int $productId, int $operatorId, ?int $lead, array $slotOpts = []): array
    {
        try {
            $av = BookingAvailability::forDay($dateYmd, [$productId], $operatorId, null, false, $lead, null, $slotOpts);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($av['operators'] as $op) {
            if ((int)$op['id'] === $operatorId && !empty($op['available'])) {
                return array_values(array_map('intval', $op['slots'] ?? []));
            }
        }
        return [];
    }

    /**
     * First free slot at or after $notBefore.
     *
     * @param list<int> $slots
     */
    public static function firstSlotFrom(array $slots, int $notBefore): int
    {
        foreach ($slots as $ts) {
            if ($ts >= $notBefore) {
                return $ts;
            }
        }
        return 0;
    }

    /** @return list<int> */
    private static function operatorsOnShift(int $dayStart): array
    {
        if (!class_exists(WorkweeksRepository::class, false)) {
            return [];
        }
        $out = [];
        foreach (WorkweeksRepository::listForDay($dayStart) as $shift) {
            $oid = (int)($shift['id_operator'] ?? 0);
            if ($oid > 0 && empty($shift['assist_team']) && !in_array($oid, $out, true)) {
                $out[] = $oid;
            }
        }
        return $out;
    }

    /** @return list<int> */
    private static function services(bool $preferChill): array
    {
        if (!class_exists(BookingCatalog::class, false)) {
            return [];
        }
        $chill = [];
        $plain = [];
        foreach (BookingCatalog::listServices(200) as $svc) {
            $pid = (int)($svc['id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            if ($preferChill && class_exists(ProductsRepository::class, false)) {
                $full = ProductsRepository::find($pid);
                $net = max(0, (int)($full['time_net'] ?? 0));
                $sec = max(0, (int)($full['time_sec'] ?? 0));
                if ($sec > $net && $net > 0) {
                    $chill[] = $pid;
                    continue;
                }
            }
            $plain[] = $pid;
        }
        return array_merge($chill, $plain);
    }
}
