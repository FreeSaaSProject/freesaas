<?php
declare(strict_types=1);

/**
 * One-shot parity: old skill gates (RESTRICT_* + category executors) vs new (rates % + ban_*).
 * Used by bin/check-service-access-parity.php — not the live BookingOperatorGate path.
 */
final class ServiceAccessParity
{
    public const CHANNEL_BOOKING = 'booking';
    public const CHANNEL_ONLINE = 'online';
    public const CHANNEL_TERMINAL = 'terminal';

    /** @var array<int,array<string,mixed>|null> */
    private static $userCache = [];

    /** @var array<int,bool> */
    private static $showInLoadsCache = [];

    /** @var array<int,array<string,mixed>|null> */
    private static $ratesCache = [];

    /** @var array<int,array<int,true>|null> */
    private static $categoryExecutorsCache = [];

    /**
     * @param list<array{id:int,type_id:int,category?:int}> $products
     * @return array{ok:bool,blockers:list<string>}
     */
    public static function evaluateOld(int $operatorId, array $products, int $dayStart, string $channel): array
    {
        $blockers = self::commonBlockers($operatorId);
        if ($blockers !== []) {
            return ['ok' => false, 'blockers' => $blockers];
        }
        $user = self::loadUser($operatorId);
        if ($user === null) {
            return ['ok' => false, 'blockers' => ['user_missing']];
        }

        $online = $channel === self::CHANNEL_ONLINE;
        $restrictTypes = UsersRepository::parseHashIds((string)($user['RESTRICT_TYPES'] ?? ''));
        if ($online) {
            $restrictTypes = array_values(array_unique(array_merge(
                $restrictTypes,
                UsersRepository::parseHashIds((string)($user['RESTRICT_TYPES_ONLINE'] ?? ''))
            )));
        }
        $restrictProcs = UsersRepository::parseHashIds((string)($user['RESTRICT_PROCS'] ?? ''));
        $restrictTypeSet = array_fill_keys($restrictTypes, true);
        $restrictProcSet = array_fill_keys($restrictProcs, true);

        $rates = self::ratesRow($operatorId, $dayStart);
        $useRates = $rates !== null;
        $content = $useRates && is_array($rates['content'] ?? null) ? $rates['content'] : [];

        foreach ($products as $p) {
            $gid = (int)($p['id'] ?? 0);
            $typeId = (int)($p['type_id'] ?? 0);
            $catId = (int)($p['category'] ?? 0);

            if ($channel === self::CHANNEL_TERMINAL && $catId > 0) {
                $allow = self::categoryExecutorSet($catId);
                if ($allow !== null && !isset($allow[$operatorId])) {
                    $blockers[] = 'category_executors:' . $catId;
                }
            }

            if ($gid > 0 && isset($restrictProcSet[$gid])) {
                $blockers[] = 'restrict_proc:' . $gid;
            }
            if ($typeId > 0 && isset($restrictTypeSet[$typeId])) {
                $blockers[] = 'restrict_type:' . $typeId;
            }

            if (self::skipRatesMatrix($gid, $typeId)) {
                continue;
            }
            if ($useRates) {
                if ($typeId < 1) {
                    $blockers[] = 'type_id_missing';
                    continue;
                }
                $val = $content[$typeId] ?? $content[(string)$typeId] ?? null;
                if ($val === null || (string)$val === '2' || !((float)$val > 0)) {
                    $blockers[] = 'no_percent:' . $typeId;
                }
            }
        }

        $blockers = array_values(array_unique($blockers));
        return ['ok' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * New model: rates % + ban_booking / ban_online. No RESTRICT_*, no category executors.
     *
     * @param list<array{id:int,type_id:int,category?:int}> $products
     * @return array{ok:bool,blockers:list<string>}
     */
    public static function evaluateNew(int $operatorId, array $products, int $dayStart, string $channel): array
    {
        $blockers = self::commonBlockers($operatorId);
        if ($blockers !== []) {
            return ['ok' => false, 'blockers' => $blockers];
        }

        $rates = self::ratesRow($operatorId, $dayStart);
        $useRates = class_exists(RatesRepository::class, false)
            && Database::tableExists('base_procent_history');
        if ($useRates && $rates === null) {
            // Same as live gate: empty matrix → cannot do typed services
            foreach ($products as $p) {
                $typeId = (int)($p['type_id'] ?? 0);
                $gid = (int)($p['id'] ?? 0);
                if (self::skipRatesMatrix($gid, $typeId)) {
                    continue;
                }
                if ($typeId < 1) {
                    $blockers[] = 'type_id_missing';
                } else {
                    $blockers[] = 'no_rates_row';
                }
            }
            $blockers = array_values(array_unique($blockers));
            return ['ok' => $blockers === [], 'blockers' => $blockers];
        }

        $content = is_array($rates['content'] ?? null) ? $rates['content'] : [];
        $banBooking = self::banSet($rates['ban_booking'] ?? null);
        $banOnline = self::banSet($rates['ban_online'] ?? null);
        $banProcs = self::banSet($rates['ban_procs'] ?? null);

        foreach ($products as $p) {
            $gid = (int)($p['id'] ?? 0);
            $typeId = (int)($p['type_id'] ?? 0);

            if (self::skipRatesMatrix($gid, $typeId)) {
                continue;
            }
            if (!$useRates) {
                continue;
            }
            if ($typeId < 1) {
                $blockers[] = 'type_id_missing';
                continue;
            }
            $val = $content[$typeId] ?? $content[(string)$typeId] ?? null;
            if ($val === null || (string)$val === '2' || !((float)$val > 0)) {
                $blockers[] = 'no_percent:' . $typeId;
            }
            if (isset($banBooking[$typeId])) {
                $blockers[] = 'ban_booking:' . $typeId;
            }
            if ($channel === self::CHANNEL_ONLINE && isset($banOnline[$typeId])) {
                $blockers[] = 'ban_online:' . $typeId;
            }
            if ($gid > 0 && isset($banProcs[$gid])) {
                $blockers[] = 'ban_proc:' . $gid;
            }
        }

        $blockers = array_values(array_unique($blockers));
        return ['ok' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * Scan staff × services for booking / online / terminal channels.
     *
     * @return array{
     *   ok:bool,
     *   checked:int,
     *   mismatches:list<array<string,mixed>>,
     *   summary:array<string,int>
     * }
     */
    public static function compare(array $opts = []): array
    {
        $dayStart = (int)($opts['day_start'] ?? mktime(0, 0, 0));
        $limitStaff = max(1, min(500, (int)($opts['limit_staff'] ?? 200)));
        $limitProducts = max(1, min(2000, (int)($opts['limit_products'] ?? 500)));
        $operatorFilter = (int)($opts['operator_id'] ?? 0);
        $channels = $opts['channels'] ?? [
            self::CHANNEL_BOOKING,
            self::CHANNEL_ONLINE,
            self::CHANNEL_TERMINAL,
        ];
        if (!is_array($channels) || $channels === []) {
            $channels = [self::CHANNEL_BOOKING, self::CHANNEL_ONLINE, self::CHANNEL_TERMINAL];
        }

        $products = [];
        if (class_exists(BookingCatalog::class, false)) {
            foreach (BookingCatalog::listServices($limitProducts) as $p) {
                $pid = (int)($p['id'] ?? 0);
                if ($pid < 1) {
                    continue;
                }
                $products[] = [
                    'id' => $pid,
                    'type_id' => max(0, (int)($p['type_id'] ?? 0)),
                    'category' => max(0, (int)($p['category'] ?? 0)),
                    'name' => (string)($p['name'] ?? ('#' . $pid)),
                ];
            }
        }

        $staffIds = [];
        if ($operatorFilter > 0) {
            $staffIds = [$operatorFilter];
        } elseif (class_exists(UsersRepository::class, false)) {
            $listed = UsersRepository::list(['enabled' => null, 'limit' => $limitStaff]);
            foreach ($listed['items'] as $u) {
                $uid = (int)($u['id'] ?? 0);
                if ($uid > 0) {
                    $staffIds[] = $uid;
                }
            }
        }

        $mismatches = [];
        $checked = 0;
        $summary = [
            'old_only' => 0,
            'new_only' => 0,
            'both_ok' => 0,
            'both_deny' => 0,
        ];

        foreach ($staffIds as $oid) {
            $opName = '';
            if (class_exists(UsersRepository::class, false)) {
                $opName = trim(UsersRepository::nameById($oid));
            }
            foreach ($channels as $channel) {
                $channel = (string)$channel;
                foreach ($products as $p) {
                    $probe = [['id' => $p['id'], 'type_id' => $p['type_id'], 'category' => $p['category']]];
                    $old = self::evaluateOld($oid, $probe, $dayStart, $channel);
                    $new = self::evaluateNew($oid, $probe, $dayStart, $channel);
                    $checked++;
                    if ($old['ok'] && $new['ok']) {
                        $summary['both_ok']++;
                        continue;
                    }
                    if (!$old['ok'] && !$new['ok']) {
                        $summary['both_deny']++;
                        continue;
                    }
                    if ($old['ok'] && !$new['ok']) {
                        $summary['old_only']++;
                        $kind = 'old_allows_new_denies';
                    } else {
                        $summary['new_only']++;
                        $kind = 'new_allows_old_denies';
                    }
                    $mismatches[] = [
                        'kind' => $kind,
                        'channel' => $channel,
                        'operator_id' => $oid,
                        'operator_name' => $opName !== '' ? $opName : ('#' . $oid),
                        'product_id' => $p['id'],
                        'product_name' => $p['name'],
                        'type_id' => $p['type_id'],
                        'category_id' => $p['category'],
                        'old_ok' => $old['ok'],
                        'new_ok' => $new['ok'],
                        'old_blockers' => $old['blockers'],
                        'new_blockers' => $new['blockers'],
                    ];
                }
            }
        }

        return [
            'ok' => $mismatches === [],
            'checked' => $checked,
            'staff' => count($staffIds),
            'products' => count($products),
            'day_start' => $dayStart,
            'mismatches' => $mismatches,
            'summary' => $summary,
            'ban_columns' => Database::tableExists('base_procent_history')
                && Database::columnExists('base_procent_history', 'ban_booking')
                && Database::columnExists('base_procent_history', 'ban_online'),
        ];
    }

    /** @return list<string> */
    private static function commonBlockers(int $operatorId): array
    {
        if ($operatorId < 1) {
            return ['operator_invalid'];
        }
        $user = self::loadUser($operatorId);
        if ($user === null) {
            return ['user_missing'];
        }
        if (!empty($user['ASSIST_TEAM']) || !empty($user['assist_team'])) {
            return ['assist_team'];
        }
        if (array_key_exists('enabled', $user) && (int)$user['enabled'] !== 1 && (string)$user['enabled'] !== '1') {
            return ['disabled'];
        }
        $statusId = (int)($user['ID_STATUS'] ?? $user['status_id'] ?? 0);
        if (!self::statusShowInLoads($statusId)) {
            return ['not_show_in_loads'];
        }
        return [];
    }

    private static function skipRatesMatrix(int $gid, int $typeId): bool
    {
        if (class_exists('ProceduresChain', false) && ProceduresChain::isBreakProductTypeId($typeId)) {
            return true;
        }
        if (
            $gid > 0
            && class_exists('TerminalsConfig', false)
            && TerminalsConfig::chillProductId() === $gid
        ) {
            return true;
        }
        return false;
    }

    /** @return array<string,mixed>|null null = rates unavailable; empty content if no row handled by caller */
    private static function ratesRow(int $operatorId, int $dayStart): ?array
    {
        if (!class_exists(RatesRepository::class, false) || !Database::tableExists('base_procent_history')) {
            return null;
        }
        $key = $operatorId . ':' . $dayStart;
        if (array_key_exists($key, self::$ratesCache)) {
            return self::$ratesCache[$key];
        }
        $row = RatesRepository::latestForOperator($operatorId, $dayStart > 0 ? $dayStart : time());
        self::$ratesCache[$key] = $row;
        return $row;
    }

    /**
     * @param mixed $raw
     * @return array<int,true>
     */
    private static function banSet($raw): array
    {
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $k => $v) {
                if (is_int($k) || (is_string($k) && ctype_digit($k))) {
                    if (!empty($v) && (string)$v !== '0') {
                        $out[(int)$k] = true;
                    }
                } elseif ((int)$v > 0) {
                    $out[(int)$v] = true;
                }
            }
            return $out;
        }
        return [];
    }

    /** @return array<int,true>|null */
    private static function categoryExecutorSet(int $categoryId): ?array
    {
        if ($categoryId < 1) {
            return null;
        }
        if (array_key_exists($categoryId, self::$categoryExecutorsCache)) {
            return self::$categoryExecutorsCache[$categoryId];
        }
        if (!class_exists(CategoriesRepository::class, false)) {
            self::$categoryExecutorsCache[$categoryId] = null;
            return null;
        }
        $cat = CategoriesRepository::find($categoryId);
        if ($cat === null) {
            self::$categoryExecutorsCache[$categoryId] = null;
            return null;
        }
        $raw = trim((string)($cat['executors'] ?? ''));
        if ($raw === '') {
            self::$categoryExecutorsCache[$categoryId] = null;
            return null;
        }
        $raw = str_replace(['#', ' ', ';'], [',', '', ','], $raw);
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $out[$n] = true;
            }
        }
        $set = $out === [] ? null : $out;
        self::$categoryExecutorsCache[$categoryId] = $set;
        return $set;
    }

    /** @return array<string,mixed>|null */
    private static function loadUser(int $operatorId): ?array
    {
        if (array_key_exists($operatorId, self::$userCache)) {
            return self::$userCache[$operatorId];
        }
        if (!class_exists(UsersRepository::class, false)) {
            self::$userCache[$operatorId] = null;
            return null;
        }
        $user = UsersRepository::findForEdit($operatorId);
        self::$userCache[$operatorId] = $user;
        return $user;
    }

    private static function statusShowInLoads(int $statusId): bool
    {
        if ($statusId < 1) {
            return false;
        }
        if (array_key_exists($statusId, self::$showInLoadsCache)) {
            return self::$showInLoadsCache[$statusId];
        }
        if (!Database::tableExists('base_admin_statuses')
            || !Database::columnExists('base_admin_statuses', 'SHOW_IN_LOADS')
        ) {
            self::$showInLoadsCache[$statusId] = true;
            return true;
        }
        $st = Database::pdo()->prepare('SELECT SHOW_IN_LOADS FROM base_admin_statuses WHERE ID = ? LIMIT 1');
        $st->execute([$statusId]);
        $flag = (int)$st->fetchColumn() > 0;
        self::$showInLoadsCache[$statusId] = $flag;
        return $flag;
    }
}
