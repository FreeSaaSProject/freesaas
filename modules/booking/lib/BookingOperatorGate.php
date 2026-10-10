<?php
declare(strict_types=1);

/**
 * Who may perform cart services for booking slots:
 * enabled, on-shift (caller), SHOW_IN_LOADS, not assist_team,
 * RESTRICT_*, rates matrix content[type]>0.
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

    /** @var array<int,array<int|string,mixed>|null> */
    private static $ratesCache = [];

    /**
     * @param list<array{id:int,type_id:int}> $products
     */
    public static function canPerform(int $operatorId, array $products, int $dayStart, bool $online = false): bool
    {
        if ($operatorId < 1 || $products === []) {
            return false;
        }
        $user = self::loadUser($operatorId);
        if ($user === null) {
            return false;
        }
        if (!empty($user['ASSIST_TEAM']) || !empty($user['assist_team'])) {
            return false;
        }
        if (array_key_exists('enabled', $user) && (int)$user['enabled'] !== 1 && (string)$user['enabled'] !== '1') {
            return false;
        }
        $statusId = (int)($user['ID_STATUS'] ?? $user['status_id'] ?? 0);
        if (!self::statusShowInLoads($statusId)) {
            return false;
        }

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

        $ratesMap = self::ratesContent($operatorId, $dayStart);
        $useRates = $ratesMap !== null;

        foreach ($products as $p) {
            $gid = (int)($p['id'] ?? 0);
            $typeId = (int)($p['type_id'] ?? 0);
            if ($gid > 0 && isset($restrictProcSet[$gid])) {
                return false;
            }
            if ($typeId > 0 && isset($restrictTypeSet[$typeId])) {
                return false;
            }
            if ($useRates) {
                if ($typeId < 1) {
                    return false;
                }
                $val = $ratesMap[$typeId] ?? $ratesMap[(string)$typeId] ?? null;
                if ($val === null || (string)$val === '2' || !((float)$val > 0)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * When rates module/table exists: map or empty array from latest row.
     * When rates unavailable: null → skip matrix check.
     *
     * @return array<int|string,mixed>|null
     */
    private static function ratesContent(int $operatorId, int $dayStart): ?array
    {
        if (!class_exists(RatesRepository::class, false) || !Database::tableExists('base_procent_history')) {
            return null;
        }
        $cacheKey = $operatorId . ':' . $dayStart;
        if (array_key_exists($cacheKey, self::$ratesCache)) {
            return self::$ratesCache[$cacheKey];
        }
        $row = RatesRepository::latestForOperator($operatorId, $dayStart > 0 ? $dayStart : time());
        if ($row === null) {
            // Legacy: no matrix → cannot do any typed service
            self::$ratesCache[$cacheKey] = [];
            return [];
        }
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        self::$ratesCache[$cacheKey] = $content;
        return $content;
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
