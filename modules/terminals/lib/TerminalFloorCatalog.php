<?php
declare(strict_types=1);

/**
 * Category matrix for /t (oper goods × specialists with price + next slot).
 * Supports cart (multi-gid), dop_cat cross-category rows, executor busy cells.
 */
final class TerminalFloorCatalog
{
    /**
     * Products for category: primary category OR dop_cat contains #id#.
     *
     * @param list<array<string,mixed>> $catalog
     * @param list<int> $keepIds always include (e.g. already in cart)
     * @return list<array<string,mixed>>
     */
    public static function productsForCategory(array $catalog, int $categoryId, array $keepIds = []): array
    {
        if ($categoryId < 1) {
            return [];
        }
        $keep = [];
        foreach ($keepIds as $kid) {
            $kid = (int)$kid;
            if ($kid > 0) {
                $keep[$kid] = true;
            }
        }
        $marker = '#' . $categoryId . '#';
        $out = [];
        $seen = [];
        foreach ($catalog as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $inCat = (int)($p['category'] ?? 0) === $categoryId;
            $dop = (string)($p['dop_cat'] ?? '');
            $inDop = $dop !== '' && strpos($dop, $marker) !== false;
            if (!$inCat && !$inDop && !isset($keep[$pid])) {
                continue;
            }
            $out[] = $p;
            $seen[$pid] = true;
        }
        foreach ($keep as $pid => $_) {
            if (isset($seen[$pid]) || !class_exists(ProductsRepository::class, false)) {
                continue;
            }
            $full = ProductsRepository::find($pid);
            if ($full !== null) {
                $out[] = $full;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $products
     * @param list<int> $selectedGids
     * @param list<int> $allowExecutors terminal EXECUTORS allow-list (empty = all)
     * @param array<int,array{free_time:int,terminal_name:string}> $busyMap
     * @param array{
     *   base_pct?:float,
     *   promo_active?:bool,
     *   type_limits?:array<int,int|string>
     * } $discountCtx oper-parity: max(base, promo capped by type_limits)
     * @return array{
     *   columns:list<array<string,mixed>>,
     *   rows:list<array<string,mixed>>,
     *   cart:array{items:list<array{id:int,name:string,price:float,price_list?:float,pct?:int}>,sum:float}
     * }
     */
    public static function buildMatrix(
        array $products,
        int $categoryId,
        int $filterOperatorId,
        int $terminalId,
        float $clientBalance,
        float $discountPct,
        array $allowExecutors,
        array $busyMap,
        array $selectedGids = [],
        array $discountCtx = []
    ): array {
        $basePct = array_key_exists('base_pct', $discountCtx)
            ? (float)$discountCtx['base_pct']
            : $discountPct;
        $promoActive = !empty($discountCtx['promo_active']);
        $typeLimits = is_array($discountCtx['type_limits'] ?? null)
            ? $discountCtx['type_limits']
            : [];
        $dayStart = (int)mktime(0, 0, 0);
        $dayEnd = (int)mktime(23, 59, 59);
        $tableNames = class_exists(TablesRepository::class, false)
            ? TablesRepository::names(false)
            : [];
        $selectedSet = [];
        foreach ($selectedGids as $gid) {
            $gid = (int)$gid;
            if ($gid > 0) {
                $selectedSet[$gid] = true;
            }
        }

        $columns = [];
        $shifts = class_exists(WorkweeksRepository::class, false)
            ? WorkweeksRepository::listForDay($dayStart)
            : [];
        foreach ($shifts as $shift) {
            $opId = (int)($shift['id_operator'] ?? 0);
            if ($opId < 1 || !empty($shift['assist_team'])) {
                continue;
            }
            if ($filterOperatorId > 0 && $opId !== $filterOperatorId) {
                continue;
            }
            if ($allowExecutors !== [] && !in_array($opId, $allowExecutors, true)) {
                continue;
            }
            $name = trim((string)($shift['operator_name'] ?? ''));
            if ($name === '' && class_exists(UsersRepository::class, false)) {
                $u = UsersRepository::find($opId);
                $name = trim((string)($u['name'] ?? $u['login'] ?? ('#' . $opId)));
            }
            $parts = preg_split('/\s+/u', $name) ?: [];
            $tableId = (int)($shift['id_table'] ?? 0);
            $tableName = '';
            if ($tableId > 0) {
                $tableName = (string)($tableNames[$tableId] ?? '');
                if ($tableName === '') {
                    $tableName = 'o' . $tableId;
                }
            }
            $busy = isset($busyMap[$opId]);
            $columns[] = [
                'id' => $opId,
                'name' => $name !== '' ? $name : ('#' . $opId),
                'first' => (string)($parts[0] ?? ''),
                'last' => (string)($parts[1] ?? ($parts[0] ?? '')),
                'color' => trim((string)($shift['color'] ?? '')) !== '' ? (string)$shift['color'] : '#b8956a',
                'color_text' => trim((string)($shift['color_text'] ?? '')) !== '' ? (string)$shift['color_text'] : '#fff',
                'table_id' => $tableId,
                'table_name' => $tableName,
                'price_col' => (int)($shift['price_col'] ?? 1),
                'busy' => $busy,
                'busy_until' => $busy ? (int)($busyMap[$opId]['free_time'] ?? 0) : 0,
                'busy_terminal' => $busy ? (string)($busyMap[$opId]['terminal_name'] ?? '') : '',
            ];
        }

        // One occupancy + work window for the whole matrix (avoid N×M BookingAvailability::forDay).
        $slotEngine = class_exists(BookingSlots::class, false)
            && class_exists(BookingOccupancy::class, false)
            && class_exists(BookingAvailability::class, false);
        $used = [];
        $rangeStart = $dayStart;
        $workHourEnd = 22;
        $workMinuteEnd = 0;
        $respectStaffShift = class_exists(TerminalsConfig::class, false)
            ? !TerminalsConfig::ignoreStaffShiftWindows()
            : true;
        if ($slotEngine) {
            $used = BookingOccupancy::build($dayStart, $dayEnd + 1, null);
            [$workHourStart, $workMinuteStart] = BookingAvailability::workDayStart();
            [$workHourEnd, $workMinuteEnd] = BookingAvailability::workDayEnd();
            $rangeStart = (int)mktime(
                $workHourStart,
                $workMinuteStart,
                0,
                (int)date('n', $dayStart),
                (int)date('j', $dayStart),
                (int)date('Y', $dayStart)
            );
            if ($dayStart === (int)mktime(0, 0, 0)) {
                $lead = class_exists(TerminalsConfig::class, false)
                    ? TerminalsConfig::leadSec()
                    : (class_exists(BookingSlots::class, false) ? BookingSlots::defaultLeadSec() : 180);
                $rangeStart = max($rangeStart, time() + $lead);
            }
        }
        /** @var array<int,array{id:int,name:string,time_sec:int,time_net:int,type_id:int}> $slotProducts */
        $slotProducts = [];
        $preloadIds = array_values(array_unique(array_merge(
            array_map(static fn (array $p): int => (int)($p['id'] ?? 0), $products),
            array_keys($selectedSet)
        )));
        if ($slotEngine && class_exists(BookingAvailability::class, false)) {
            foreach (BookingAvailability::loadProducts($preloadIds) as $sp) {
                $slotProducts[(int)$sp['id']] = $sp;
            }
        }

        $cartItems = [];
        $cartSum = 0.0;
        $rows = [];
        /** @var array<string,int> $firstSlotCache opId|gids → first slot ts */
        $firstSlotCache = [];
        foreach ($products as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $typeId = max(0, (int)($p['type_id'] ?? 0));
            if (isset($slotProducts[$pid])) {
                $typeId = max($typeId, (int)$slotProducts[$pid]['type_id']);
                if (($p['name'] ?? '') === '' && $slotProducts[$pid]['name'] !== '') {
                    $p['name'] = $slotProducts[$pid]['name'];
                }
            } elseif ($typeId < 1 && class_exists(ProductsRepository::class, false)) {
                $full = ProductsRepository::find($pid);
                $typeId = $full ? max(0, (int)($full['type_id'] ?? 0)) : 0;
                if ($full) {
                    $p = array_merge($full, $p);
                }
            }
            $basePrice = (float)($p['price'] ?? 0);
            if ($basePrice <= 0) {
                $basePrice = (float)($p['price3'] ?? 0);
            }
            $priced = self::priceForProduct($basePrice, $typeId, $basePct, $promoActive, $typeLimits);
            $price = $priced['price'];
            $pct = $priced['pct'];
            $isSelected = isset($selectedSet[$pid]);
            if ($isSelected) {
                $cartItems[] = [
                    'id' => $pid,
                    'name' => (string)($p['name'] ?? ('#' . $pid)),
                    'price' => $price,
                    'price_list' => $basePrice,
                    'pct' => $pct,
                ];
                $cartSum += $price;
            }

            $cells = [];
            foreach ($columns as $col) {
                $opId = (int)$col['id'];
                $can = true;
                if (class_exists(BookingOperatorGate::class, false)) {
                    $can = BookingOperatorGate::canPerform(
                        $opId,
                        [['id' => $pid, 'type_id' => $typeId]],
                        $dayStart,
                        false
                    );
                }
                if (!$can) {
                    $cells[] = ['kind' => 'none', 'price' => $price, 'slot' => 0, 'note' => t('terminals.t.cell_none')];
                    continue;
                }
                if ($isSelected) {
                    $without = array_values(array_filter(
                        array_keys($selectedSet),
                        static fn (int $g): bool => $g !== $pid
                    ));
                    $cells[] = [
                        'kind' => 'selected',
                        'price' => $price,
                        'slot' => 0,
                        'href_admin' => $opId,
                        'href_gids' => self::gidsString($without),
                    ];
                    continue;
                }
                if (!empty($col['busy'])) {
                    $cells[] = [
                        'kind' => 'busy',
                        'price' => $price,
                        'slot' => 0,
                        'note' => t('terminals.t.cell_busy', [
                            'term' => (string)$col['busy_terminal'],
                            'until' => $col['busy_until'] > 0 ? date('H:i:s', (int)$col['busy_until']) : '',
                        ]),
                    ];
                    continue;
                }
                $probeGids = array_values(array_unique(array_merge(array_keys($selectedSet), [$pid])));
                $probeSum = $cartSum + $price;
                if ($probeSum > $clientBalance + 0.009) {
                    $cells[] = [
                        'kind' => 'nofunds',
                        'price' => $price,
                        'slot' => 0,
                        'note' => t('terminals.t.cell_nofunds'),
                    ];
                    continue;
                }
                $slot = 0;
                if ($slotEngine) {
                    $cacheKey = $opId . '|' . self::gidsString($probeGids);
                    if (array_key_exists($cacheKey, $firstSlotCache)) {
                        $slot = $firstSlotCache[$cacheKey];
                    } else {
                        $probeProducts = [];
                        foreach ($probeGids as $gid) {
                            if (isset($slotProducts[$gid])) {
                                $probeProducts[] = $slotProducts[$gid];
                            }
                        }
                        if ($probeProducts !== []) {
                            $opRangeStart = $rangeStart;
                            $opWhEnd = $workHourEnd;
                            $opWmEnd = $workMinuteEnd;
                            if ($respectStaffShift) {
                                $clipped = BookingAvailability::clipToStaffShift(
                                    $dayStart,
                                    (int)($col['price_col'] ?? 1),
                                    $opRangeStart,
                                    $opWhEnd,
                                    $opWmEnd
                                );
                                $opRangeStart = $clipped['range_start'];
                                $opWhEnd = $clipped['hour_end'];
                                $opWmEnd = $clipped['minute_end'];
                            }
                            $check = BookingSlots::check(
                                $probeProducts,
                                $opId,
                                $used,
                                $opRangeStart,
                                $dayEnd,
                                $opWhEnd,
                                $opWmEnd,
                                class_exists(TerminalsConfig::class, false)
                                    ? TerminalsConfig::slotIntervalSec()
                                    : null
                            );
                            $slots = array_values($check['timeSlots'] ?? []);
                            sort($slots);
                            $slot = (int)($slots[0] ?? 0);
                        }
                        $firstSlotCache[$cacheKey] = $slot;
                    }
                }
                if ($slot < 1) {
                    $cells[] = ['kind' => 'noslot', 'price' => $price, 'slot' => 0, 'note' => t('terminals.t.no_slots')];
                    continue;
                }
                $cells[] = [
                    'kind' => 'book',
                    'price' => $price,
                    'slot' => $slot,
                    'href_admin' => $opId,
                    'href_gids' => self::gidsString($probeGids),
                ];
            }
            $rows[] = [
                'id' => $pid,
                'name' => (string)($p['name'] ?? ('#' . $pid)),
                'selected' => $isSelected,
                'cells' => $cells,
            ];
        }

        // Selected rows first (order table), then the rest.
        usort($rows, static function (array $a, array $b): int {
            $as = !empty($a['selected']) ? 0 : 1;
            $bs = !empty($b['selected']) ? 0 : 1;
            if ($as !== $bs) {
                return $as <=> $bs;
            }
            return ((int)$a['id']) <=> ((int)$b['id']);
        });

        return [
            'columns' => $columns,
            'rows' => $rows,
            'cart' => [
                'items' => $cartItems,
                'sum' => round($cartSum, 2),
            ],
        ];
    }

    /** @param list<int> $gids */
    public static function gidsString(array $gids): string
    {
        $out = [];
        foreach ($gids as $g) {
            $g = (int)$g;
            if ($g > 0) {
                $out[$g] = $g;
            }
        }
        return implode('#', array_values($out));
    }

    /**
     * @param array<int,int|string> $typeLimits
     * @return array{price:float,pct:int}
     */
    public static function priceForProduct(
        float $listPrice,
        int $typeId,
        float $basePct,
        bool $promoActive,
        array $typeLimits = []
    ): array {
        if (class_exists(TerminalDiscount::class, false)) {
            return TerminalDiscount::bestPrice($listPrice, $basePct, $typeId, $promoActive, $typeLimits);
        }
        if ($listPrice <= 0 || $basePct <= 0) {
            return ['price' => round(max(0.0, $listPrice), 2), 'pct' => max(0, (int)$basePct)];
        }
        $pct = max(0, min(100, (int)$basePct));
        return [
            'price' => round($listPrice * (1.0 - $pct / 100.0), 2),
            'pct' => $pct,
        ];
    }

    private static function applyDiscount(float $price, float $pct): float
    {
        return self::priceForProduct($price, 0, $pct, false, [])['price'];
    }
}
