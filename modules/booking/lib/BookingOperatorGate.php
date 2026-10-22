<?php
declare(strict_types=1);

/**
 * Who may perform cart services for booking slots:
 * enabled, on-shift (caller), SHOW_IN_LOADS, not assist_team,
 * rates matrix content[type]>0, rates ban_booking / ban_online / ban_procs
 * (SoT; fail closed if rates table missing). Legacy RESTRICT_* ignored
 * (migrated into rates bans). See bin/check-service-access-parity.php.
 *
 * IS_HIDDEN does NOT block booking — those staff are only excluded from payroll
 * / load charts elsewhere; if they are enabled and scheduled, they are bookable.
 */
final class BookingOperatorGate
{
    /** @var array<int,array<string,mixed>|null> */
    private static $userCache = [];

    /** @var array<int,bool> */
    private static $showInLoadsCache = [];

    /** @var array<int,array<string,mixed>|null|false> */
    private static $ratesRowCache = [];

    /**
     * @param list<array{id:int,type_id:int}> $products
     */
    public static function canPerform(int $operatorId, array $products, int $dayStart, bool $online = false): bool
    {
        return self::explain($operatorId, $products, $dayStart, $online)['ok'];
    }

    /**
     * Structured blockers for UI / agents.
     *
     * @param list<array{id:int,type_id:int}> $products
     * @return array{ok:bool,blockers:list<string>}
     */
    public static function explain(int $operatorId, array $products, int $dayStart, bool $online = false): array
    {
        $blockers = [];
        if ($operatorId < 1 || $products === []) {
            return ['ok' => false, 'blockers' => ['operator_or_products']];
        }
        $user = self::loadUser($operatorId);
        if ($user === null) {
            return ['ok' => false, 'blockers' => ['user_missing']];
        }
        if (!empty($user['ASSIST_TEAM']) || !empty($user['assist_team'])) {
            return ['ok' => false, 'blockers' => ['assist_team']];
        }
        if (array_key_exists('enabled', $user) && (int)$user['enabled'] !== 1 && (string)$user['enabled'] !== '1') {
            return ['ok' => false, 'blockers' => ['disabled']];
        }
        $statusId = (int)($user['ID_STATUS'] ?? $user['status_id'] ?? 0);
        if (!self::statusShowInLoads($statusId)) {
            return ['ok' => false, 'blockers' => ['not_show_in_loads']];
        }

        $ratesRow = self::ratesRow($operatorId, $dayStart);
        // null = rates SoT unavailable → fail closed for typed services
        $ratesMissing = $ratesRow === null;
        $content = [];
        $banBooking = [];
        $banOnline = [];
        $banProcs = [];
        if (!$ratesMissing) {
            if ($ratesRow !== []) {
                $content = is_array($ratesRow['content'] ?? null) ? $ratesRow['content'] : [];
                $banBooking = self::banTypeSet($ratesRow['ban_booking'] ?? null);
                $banOnline = self::banTypeSet($ratesRow['ban_online'] ?? null);
                $banProcs = self::banTypeSet($ratesRow['ban_procs'] ?? null);
            }
        }

        foreach ($products as $p) {
            $gid = (int)($p['id'] ?? 0);
            $typeId = (int)($p['type_id'] ?? 0);
            if (class_exists('ProceduresChain', false) && ProceduresChain::isBreakProductTypeId($typeId)) {
                continue;
            }
            if (
                $gid > 0
                && class_exists('TerminalsConfig', false)
                && TerminalsConfig::chillProductId() === $gid
            ) {
                continue;
            }
            if ($ratesMissing) {
                $blockers[] = 'rates_unavailable';
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
            if ($online && isset($banOnline[$typeId])) {
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
     * @param mixed $raw
     * @return array<int,true>
     */
    private static function banTypeSet($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
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

    /**
     * null = rates module/table unavailable (skip matrix).
     * [] = rates on but no row for operator (deny typed services).
     * array = normalized rates row.
     *
     * @return array<string,mixed>|null
     */
    private static function ratesRow(int $operatorId, int $dayStart): ?array
    {
        if (!class_exists(RatesRepository::class, false) || !Database::tableExists('base_procent_history')) {
            return null;
        }
        $cacheKey = $operatorId . ':' . $dayStart;
        if (array_key_exists($cacheKey, self::$ratesRowCache)) {
            $hit = self::$ratesRowCache[$cacheKey];
            return $hit === false ? [] : $hit;
        }
        $asOf = class_exists(RatesRepository::class, false)
            ? RatesRepository::asOfForDay($dayStart)
            : ($dayStart > 0 ? $dayStart : time());
        $row = RatesRepository::latestForOperator($operatorId, $asOf);
        if ($row === null) {
            self::$ratesRowCache[$cacheKey] = false;
            return [];
        }
        self::$ratesRowCache[$cacheKey] = $row;
        return $row;
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
