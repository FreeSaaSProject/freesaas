<?php
declare(strict_types=1);

/**
 * Admin calendar scope for restricted viewers (freelance administrators).
 *
 * SoT = the viewer's own rates profile (rates module, "skills" block): per procedure type
 * checkbox `hide_calendar` (`ban_calendar`). Any checked type makes the viewer "scoped":
 *   - specialists show up only when they can perform at least one type that is NOT checked
 *     (rates % > 0 and no ban_booking for that type);
 *   - items of checked types (events / procedures / advance orders) collapse into grey
 *     «busy» blocks: time + specialist only, no client / phone / sum / basket / links;
 *   - items the viewer created themselves (event creator / procedure admin) stay in full.
 *
 * Soft dependency: without the rates module (or the column) nothing is restricted.
 * Superadmin is never restricted.
 */
final class CalendarViewScope
{
    /** @var array<string,mixed>|null */
    private static $state = null;

    /** @var array<int,int> good id => procedure type id */
    private static $goodTypes = [];

    /** @var array<string,bool> */
    private static $opVisible = [];

    public static function reset(): void
    {
        self::$state = null;
        self::$goodTypes = [];
        self::$opVisible = [];
    }

    /** @return array{active:bool,hidden:array<int,true>,allowed:list<int>,self:int} */
    private static function state(): array
    {
        if (self::$state !== null) {
            /** @var array{active:bool,hidden:array<int,true>,allowed:list<int>,self:int} $s */
            $s = self::$state;
            return $s;
        }
        $state = ['active' => false, 'hidden' => [], 'allowed' => [], 'self' => 0];
        self::$state = $state;

        if (!Auth::check() || Auth::isSuperAdmin()) {
            return $state;
        }
        if (!class_exists('RatesRepository', false)
            || !class_exists('ProcedureTypesRepository', false)
            || !RatesRepository::hasBanCalendarColumn()
        ) {
            return $state;
        }
        $uid = Auth::userId();
        if ($uid < 1) {
            return $state;
        }
        $row = RatesRepository::latestForOperator($uid, time());
        $raw = is_array($row) && is_array($row['ban_calendar'] ?? null) ? $row['ban_calendar'] : [];
        $hidden = [];
        foreach ($raw as $tid => $on) {
            if (!empty($on) && (int)$tid > 0) {
                $hidden[(int)$tid] = true;
            }
        }
        if ($hidden === []) {
            return $state;
        }
        $allowed = [];
        foreach (array_keys(ProcedureTypesRepository::names()) as $tid) {
            $tid = (int)$tid;
            if ($tid < 1 || isset($hidden[$tid]) || RatesRepository::isSpecOrBreakType($tid)) {
                continue;
            }
            $allowed[] = $tid;
        }
        $state = ['active' => true, 'hidden' => $hidden, 'allowed' => $allowed, 'self' => $uid];
        self::$state = $state;
        return $state;
    }

    public static function active(): bool
    {
        return self::state()['active'];
    }

    /** @return array<int,true> */
    public static function hiddenTypes(): array
    {
        return self::state()['hidden'];
    }

    /** @return list<int> */
    public static function allowedTypes(): array
    {
        return self::state()['allowed'];
    }

    /**
     * Specialist is visible when they can take at least one allowed (unchecked) type that day.
     */
    public static function operatorVisible(int $operatorId, int $dayStart): bool
    {
        $s = self::state();
        if (!$s['active'] || $operatorId < 1 || $operatorId === $s['self']) {
            return true;
        }
        $key = $operatorId . ':' . date('Y-m-d', $dayStart > 0 ? $dayStart : time());
        if (isset(self::$opVisible[$key])) {
            return self::$opVisible[$key];
        }
        $ok = false;
        $asOf = class_exists('RatesRepository', false)
            ? RatesRepository::asOfForDay($dayStart > 0 ? $dayStart : time())
            : time();
        $row = RatesRepository::latestForOperator($operatorId, $asOf);
        if (is_array($row)) {
            $content = is_array($row['content'] ?? null) ? $row['content'] : [];
            $ban = is_array($row['ban_booking'] ?? null) ? $row['ban_booking'] : [];
            foreach ($s['allowed'] as $tid) {
                $pct = (float)str_replace(',', '.', (string)($content[$tid] ?? $content[(string)$tid] ?? 0));
                if ($pct > 0 && empty($ban[$tid]) && empty($ban[(string)$tid])) {
                    $ok = true;
                    break;
                }
            }
        }
        self::$opVisible[$key] = $ok;
        return $ok;
    }

    /**
     * Drop shift badges of specialists the viewer must not see. Desk admins / assistants stay.
     *
     * @param list<array<string,mixed>> $shifts
     * @return list<array<string,mixed>>
     */
    public static function filterShifts(array $shifts, int $dayStart): array
    {
        if (!self::active()) {
            return $shifts;
        }
        $out = [];
        foreach ($shifts as $sh) {
            $group = (int)($sh['group'] ?? 0);
            if ($group === 2 || $group === 3 || (int)($sh['assist_team'] ?? 0) > 0) {
                $out[] = $sh;
                continue;
            }
            if (self::operatorVisible((int)($sh['id_operator'] ?? 0), $dayStart)) {
                $out[] = $sh;
            }
        }
        return $out;
    }

    /**
     * Apply scope to day/week data. Hidden specialists disappear; hidden types become busy rows.
     * Busy rows from procedures and orders are merged into the events list (kind block + busy=1).
     *
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $procedures
     * @param list<array<string,mixed>> $orders
     * @return array{events:list<array<string,mixed>>,procedures:list<array<string,mixed>>,orders:list<array<string,mixed>>}
     */
    public static function maskRows(array $events, array $procedures, array $orders): array
    {
        $s = self::state();
        if (!$s['active']) {
            return ['events' => $events, 'procedures' => $procedures, 'orders' => $orders];
        }
        $self = $s['self'];
        $busy = [];

        $outEvents = [];
        foreach ($events as $ev) {
            $op = (int)($ev['id_operator'] ?? 0);
            $dayStart = self::dayStartOf((int)($ev['dtstart'] ?? 0));
            if (!self::operatorVisible($op, $dayStart)) {
                continue;
            }
            if ((int)($ev['id_operator_create'] ?? 0) === $self && $self > 0) {
                $outEvents[] = $ev;
                continue;
            }
            if (!empty($ev['is_block'])) {
                $busy[] = self::busyRow($op, (int)$ev['dtstart'], (int)$ev['dtend'], (int)($ev['id'] ?? 0), (string)($ev['operator_name'] ?? ''));
                continue;
            }
            $goods = [];
            foreach (is_array($ev['items'] ?? null) ? $ev['items'] : [] as $it) {
                if (is_array($it) && (int)($it['id_good'] ?? 0) > 0) {
                    $goods[] = (int)$it['id_good'];
                }
            }
            if (self::typesHidden(self::typesForGoods($goods), true)) {
                $busy[] = self::busyRow($op, (int)$ev['dtstart'], (int)$ev['dtend'], (int)($ev['id'] ?? 0), (string)($ev['operator_name'] ?? ''));
                continue;
            }
            $outEvents[] = $ev;
        }

        $outProcs = [];
        foreach ($procedures as $pr) {
            $op = (int)($pr['id_operator'] ?? 0);
            $dayStart = self::dayStartOf((int)($pr['dtstart'] ?? 0));
            if (!self::operatorVisible($op, $dayStart)) {
                continue;
            }
            if ($self > 0 && (int)($pr['id_admin'] ?? 0) === $self) {
                $outProcs[] = $pr;
                continue;
            }
            $types = [];
            $typeId = (int)($pr['type'] ?? 0);
            if ($typeId > 0) {
                $types[$typeId] = true;
            } else {
                $types = self::typesForGoods([(int)($pr['id_good'] ?? 0)]);
            }
            if (self::typesHidden($types, true)) {
                $busy[] = self::busyRow($op, (int)($pr['dtstart'] ?? 0), (int)($pr['dtend'] ?? 0), (int)($pr['id'] ?? 0), (string)($pr['operator_name'] ?? ''));
                continue;
            }
            $outProcs[] = $pr;
        }

        $outOrders = [];
        foreach ($orders as $ord) {
            $op = (int)($ord['id_cosmetolog'] ?? $ord['id_operator'] ?? 0);
            $ts = (int)($ord['dt_event'] ?? 0);
            if ($op > 0 && !self::operatorVisible($op, self::dayStartOf($ts))) {
                continue;
            }
            $goods = [];
            if (class_exists('OrdersRepository', false)) {
                foreach (OrdersRepository::decodeCart((string)($ord['cart'] ?? '')) as $line) {
                    if ((int)($line['id'] ?? 0) > 0) {
                        $goods[] = (int)$line['id'];
                    }
                }
            }
            if (self::typesHidden(self::typesForGoods($goods), true)) {
                $end = $ts + (class_exists('CalendarTimeline', false) ? CalendarTimeline::orderDurationSec($ord) : 3600);
                $busy[] = self::busyRow($op, $ts, $end, (int)($ord['id'] ?? 0), '');
                continue;
            }
            $outOrders[] = $ord;
        }

        $merged = array_merge($outEvents, $busy);
        usort($merged, static function (array $a, array $b): int {
            return ((int)($a['dtstart'] ?? 0) <=> (int)($b['dtstart'] ?? 0));
        });
        return ['events' => $merged, 'procedures' => $outProcs, 'orders' => $outOrders];
    }

    /**
     * May the viewer open / modify this event (edit page, API)?
     *
     * @param array<string,mixed> $event EventsRepository row
     */
    public static function canSeeEvent(array $event): bool
    {
        $s = self::state();
        if (!$s['active']) {
            return true;
        }
        if ($s['self'] > 0 && (int)($event['id_operator_create'] ?? 0) === $s['self']) {
            return true;
        }
        $op = (int)($event['id_operator'] ?? 0);
        if (!self::operatorVisible($op, self::dayStartOf((int)($event['dtstart'] ?? 0)))) {
            return false;
        }
        if (!empty($event['is_block'])) {
            return false;
        }
        $goods = [];
        foreach (is_array($event['items'] ?? null) ? $event['items'] : [] as $it) {
            if (is_array($it) && (int)($it['id_good'] ?? 0) > 0) {
                $goods[] = (int)$it['id_good'];
            }
        }
        return !self::typesHidden(self::typesForGoods($goods), true);
    }

    /**
     * Product ids the viewer may NOT put into a visit (hidden types). Empty when unscoped.
     *
     * @param list<int> $goodIds
     * @return list<int>
     */
    public static function forbiddenGoods(array $goodIds): array
    {
        if (!self::active()) {
            return [];
        }
        $hidden = self::hiddenTypes();
        $out = [];
        foreach (array_unique(array_map('intval', $goodIds)) as $gid) {
            if ($gid < 1) {
                continue;
            }
            $tid = self::typesForGoods([$gid]);
            foreach ($tid as $t => $_) {
                if (isset($hidden[$t])) {
                    $out[] = $gid;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Staff id list restricted to visible specialists (for pickers).
     *
     * @param array<int,string> $choices id => label
     * @return array<int,string>
     */
    public static function filterStaffChoices(array $choices, int $dayStart, int $keepId = 0): array
    {
        if (!self::active()) {
            return $choices;
        }
        $out = [];
        foreach ($choices as $id => $label) {
            $id = (int)$id;
            if ($id === $keepId || self::operatorVisible($id, $dayStart)) {
                $out[$id] = $label;
            }
        }
        return $out;
    }

    /** @param array<int,true> $types */
    private static function typesHidden(array $types, bool $emptyIsHidden): bool
    {
        if ($types === []) {
            return $emptyIsHidden;
        }
        $hidden = self::state()['hidden'];
        foreach ($types as $tid => $_) {
            if (isset($hidden[(int)$tid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<int> $goodIds
     * @return array<int,true> procedure type ids
     */
    private static function typesForGoods(array $goodIds): array
    {
        $need = [];
        foreach ($goodIds as $gid) {
            $gid = (int)$gid;
            if ($gid > 0 && !array_key_exists($gid, self::$goodTypes)) {
                $need[$gid] = true;
            }
        }
        if ($need !== [] && Database::tableExists('base_products') && Database::columnExists('base_products', 'type_id')) {
            $in = implode(',', array_map('intval', array_keys($need)));
            $rows = Database::pdo()->query("SELECT id, type_id FROM base_products WHERE id IN ($in)");
            foreach ($rows ? ($rows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $r) {
                self::$goodTypes[(int)$r['id']] = (int)$r['type_id'];
                unset($need[(int)$r['id']]);
            }
        }
        foreach (array_keys($need) as $gid) {
            self::$goodTypes[(int)$gid] = 0;
        }
        $out = [];
        foreach ($goodIds as $gid) {
            $t = self::$goodTypes[(int)$gid] ?? 0;
            if ($t > 0) {
                $out[$t] = true;
            }
        }
        return $out;
    }

    private static function dayStartOf(int $ts): int
    {
        if ($ts <= 0) {
            return (int)strtotime(date('Y-m-d 00:00:00'));
        }
        return (int)strtotime(date('Y-m-d 00:00:00', $ts));
    }

    /** @return array<string,mixed> */
    private static function busyRow(int $operatorId, int $dtStart, int $dtEnd, int $id, string $operatorName): array
    {
        // Source id is deliberately dropped: a busy row must not address a real event / procedure / order.
        return [
            'id' => 0,
            'id_operator' => $operatorId,
            'id_operator_create' => 0,
            'id_user' => 0,
            'dtstart' => $dtStart,
            'dtend' => $dtEnd,
            'descr' => '',
            'phone' => '',
            'name' => '',
            'client_name' => '',
            'operator_name' => $operatorName,
            'is_block' => 1,
            'busy' => 1,
            'enabled' => 1,
            'items' => [],
            'content_ids' => [],
            'content_price_map' => [],
            'content_qty_map' => [],
            'real_summ' => 0.0,
        ];
    }
}
