<?php
declare(strict_types=1);

/** Additional procedures (dops) day stats for masseur positions. */
final class DopsStats
{
    /**
     * @var array{
     *   cover_from:int,cover_to:int,positions_key:string,
     *   dops:array,clients:array,positions:array<int,true>
     * }|null
     */
    private static $cache = null;

    public static function resetRequestCache(): void
    {
        self::$cache = null;
    }

    /**
     * Prefetch dops/client stats for a calendar window (inclusive end via exclusive $toTs).
     */
    public static function warmForRange(int $fromTs, int $toTsExclusive): void
    {
        if ($fromTs < 1 || $toTsExclusive <= $fromTs || !LikespaConfig::dopsEnabled()) {
            return;
        }
        if (!Database::tableExists('base_procedures') || !Database::tableExists('base_users')) {
            return;
        }
        $positions = LikespaConfig::intList('dops_position_ids', [1]);
        if ($positions === []) {
            return;
        }
        $posKey = implode(',', $positions);
        if (self::$cache !== null
            && (int)(self::$cache['cover_from'] ?? 0) <= $fromTs
            && (int)(self::$cache['cover_to'] ?? 0) >= $toTsExclusive
            && (string)(self::$cache['positions_key'] ?? '') === $posKey
        ) {
            return;
        }
        self::warm($fromTs, $toTsExclusive, $positions, $posKey);
    }

    /**
     * @return array{count:int,sum:float,unique_clients:int,show:bool}
     */
    public static function forAdminDay(int $adminId, string $date): array
    {
        $empty = ['count' => 0, 'sum' => 0.0, 'unique_clients' => 0, 'show' => false];
        if ($adminId < 1 || $date === '' || !LikespaConfig::dopsEnabled()) {
            return $empty;
        }
        if (!Database::tableExists('base_procedures') || !Database::tableExists('base_users')) {
            return $empty;
        }

        $positions = LikespaConfig::intList('dops_position_ids', [1]);
        if ($positions === []) {
            return $empty;
        }
        [$from, $to] = PromoterBonus::monthRange($date);
        $posKey = implode(',', $positions);
        $covers = self::$cache !== null
            && (int)(self::$cache['cover_from'] ?? 0) <= $from
            && (int)(self::$cache['cover_to'] ?? 0) >= $to
            && (string)(self::$cache['positions_key'] ?? '') === $posKey;
        if (!$covers) {
            self::warm($from, $to, $positions, $posKey);
        }

        if (!isset(self::$cache['positions'][$adminId])) {
            return $empty;
        }
        $dops = self::$cache['dops'][$adminId][$date] ?? ['count' => 0, 'sum' => 0.0];
        $clients = (int)(self::$cache['clients'][$adminId][$date] ?? 0);
        $count = (int)($dops['count'] ?? 0);
        $sum = (float)($dops['sum'] ?? 0);
        return [
            'count' => $count,
            'sum' => $sum,
            'unique_clients' => $clients,
            'show' => $count > 0 || $clients > 0,
        ];
    }

    /** @param list<int> $positions */
    private static function warm(int $from, int $to, array $positions, string $posKey): void
    {
        self::$cache = [
            'cover_from' => $from,
            'cover_to' => $to,
            'positions_key' => $posKey,
            'dops' => [],
            'clients' => [],
            'positions' => [],
        ];
        $pdo = Database::pdo();
        $in = implode(',', array_map('intval', $positions));
        $st = $pdo->query(
            "SELECT id FROM base_users WHERE ID_STATUS IN ($in) AND enabled = 1"
        );
        $adminIds = [];
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $adminIds[] = $id;
                    self::$cache['positions'][$id] = true;
                }
            }
        }
        if ($adminIds === []) {
            return;
        }
        $adminIn = implode(',', $adminIds);
        $typeId = LikespaConfig::int('dops_procedure_type', 44);

        $stUc = $pdo->query(
            "SELECT ID_OPERATOR AS id_operator,
                    FROM_UNIXTIME(DTSTART, '%Y-%m-%d') AS day_key,
                    COUNT(DISTINCT ID_USER) AS unique_clients
             FROM base_procedures
             WHERE DTSTART >= {$from} AND DTSTART < {$to}
               AND ID_OPERATOR IN ($adminIn)
               AND ENABLED = 1 AND SUMM > 0
             GROUP BY ID_OPERATOR, day_key"
        );
        if ($stUc) {
            while ($row = $stUc->fetch(PDO::FETCH_ASSOC)) {
                $aid = (int)($row['id_operator'] ?? 0);
                $day = (string)($row['day_key'] ?? '');
                if ($aid > 0 && $day !== '') {
                    self::$cache['clients'][$aid][$day] = (int)($row['unique_clients'] ?? 0);
                }
            }
        }

        $stDop = $pdo->query(
            "SELECT ID_OPERATOR AS id_operator,
                    FROM_UNIXTIME(DTSTART, '%Y-%m-%d') AS day_key,
                    COUNT(*) AS cnt,
                    SUM(SUMM) AS total_sum
             FROM base_procedures
             WHERE DTSTART >= {$from} AND DTSTART < {$to}
               AND ID_OPERATOR IN ($adminIn)
               AND TYPE = " . (int)$typeId . "
               AND ENABLED = 1 AND SUMM > 0
             GROUP BY ID_OPERATOR, day_key"
        );
        if ($stDop) {
            while ($row = $stDop->fetch(PDO::FETCH_ASSOC)) {
                $aid = (int)($row['id_operator'] ?? 0);
                $day = (string)($row['day_key'] ?? '');
                if ($aid > 0 && $day !== '') {
                    self::$cache['dops'][$aid][$day] = [
                        'count' => (int)($row['cnt'] ?? 0),
                        'sum' => (float)($row['total_sum'] ?? 0),
                    ];
                }
            }
        }
    }
}
