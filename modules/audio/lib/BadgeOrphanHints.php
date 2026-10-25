<?php
declare(strict_types=1);

/**
 * Lightweight “who likely owns these orphans?” for /audio?unassigned=1&badge=CODE
 * with a bounded date window. Never runs without badge + from/to.
 */
final class BadgeOrphanHints
{
    /**
     * @return array{
     *   badge:string,
     *   from:int,
     *   to:int,
     *   orphan_count:int,
     *   candidates:list<array{
     *     admin_id:int,
     *     weight:int,
     *     assignment_sec:int,
     *     assigned_count:int,
     *     assigned_playtime:int,
     *     windows:list<array{from:int,to:int}>
     *   }>
     * }|null
     */
    public static function analyze(string $badgeCode, int $fromTs, int $toTs): ?array
    {
        $badgeCode = trim($badgeCode);
        if ($badgeCode === '' || $badgeCode === '0' || $fromTs < 1 || $toTs < $fromTs) {
            return null;
        }
        if (!Database::tableExists('base_records')) {
            return null;
        }

        $orphanCount = self::countOrphans($badgeCode, $fromTs, $toTs);
        if ($orphanCount < 1) {
            return null;
        }

        /** @var array<int, array{admin_id:int,weight:int,assignment_sec:int,assigned_count:int,assigned_playtime:int,windows:list<array{from:int,to:int}>}> $byAdmin */
        $byAdmin = [];
        $dictParam = self::dictophoneParam($badgeCode);

        if (Database::tableExists('base_badge_assignments')) {
            $st = Database::pdo()->prepare(
                'SELECT ID_OPERATOR AS op, DT_START AS dt_start, DT_END AS dt_end
                 FROM base_badge_assignments
                 WHERE ID_BADGE = ? AND ID_BADGE != \'0\'
                   AND DT_START <= ? AND DT_END >= ?
                 ORDER BY DT_START ASC
                 LIMIT 40'
            );
            $st->execute([$badgeCode, $toTs, $fromTs]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $op = (int)($row['op'] ?? 0);
                if ($op < 1) {
                    continue;
                }
                $aStart = (int)($row['dt_start'] ?? 0);
                $aEnd = (int)($row['dt_end'] ?? 0);
                $overlap = max(0, min($aEnd, $toTs) - max($aStart, $fromTs) + 1);
                if ($overlap < 1) {
                    continue;
                }
                if (!isset($byAdmin[$op])) {
                    $byAdmin[$op] = self::emptyCandidate($op);
                }
                $byAdmin[$op]['assignment_sec'] += $overlap;
                $byAdmin[$op]['windows'][] = ['from' => $aStart, 'to' => $aEnd];
            }
        }

        // Uses idx_dictophone_date (ID_DICTOPHONE, DT_RECORDED).
        $st = Database::pdo()->prepare(
            'SELECT ID_ADMIN AS op, COUNT(*) AS cnt, COALESCE(SUM(PLAYTIME), 0) AS play
             FROM base_records
             WHERE ID_DICTOPHONE = ? AND ID_ADMIN > 0
               AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED < 1)
             GROUP BY ID_ADMIN
             ORDER BY cnt DESC
             LIMIT 8'
        );
        $st->execute([$dictParam, $fromTs, $toTs]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $op = (int)($row['op'] ?? 0);
            if ($op < 1) {
                continue;
            }
            if (!isset($byAdmin[$op])) {
                $byAdmin[$op] = self::emptyCandidate($op);
            }
            $byAdmin[$op]['assigned_count'] = (int)($row['cnt'] ?? 0);
            $byAdmin[$op]['assigned_playtime'] = (int)($row['play'] ?? 0);
        }

        if ($byAdmin === []) {
            return [
                'badge' => $badgeCode,
                'from' => $fromTs,
                'to' => $toTs,
                'orphan_count' => $orphanCount,
                'candidates' => [],
            ];
        }

        foreach ($byAdmin as &$c) {
            // Prefer assignment coverage; assigned peers are a secondary signal.
            $c['weight'] = ($c['assignment_sec'] * 2)
                + ($c['assigned_count'] * 120)
                + (int)floor($c['assigned_playtime'] / 30);
        }
        unset($c);

        $candidates = array_values($byAdmin);
        usort($candidates, static function (array $a, array $b): int {
            return ((int)$b['weight'] <=> (int)$a['weight'])
                ?: ((int)$a['admin_id'] <=> (int)$b['admin_id']);
        });
        $candidates = array_slice($candidates, 0, 5);

        return [
            'badge' => $badgeCode,
            'from' => $fromTs,
            'to' => $toTs,
            'orphan_count' => $orphanCount,
            'candidates' => $candidates,
        ];
    }

    /**
     * Resolve the same date window RecordsRepository::list uses.
     *
     * @param array{year?:int,month?:int,day?:int,range_from?:int,range_to?:int,from?:int,to?:int} $opts
     * @return array{0:int,1:int}|null [from,to] or null when unbounded
     */
    public static function windowFromFilters(array $opts): ?array
    {
        $from = (int)($opts['from'] ?? 0);
        $to = (int)($opts['to'] ?? 0);
        $year = (int)($opts['year'] ?? 0);
        $month = (int)($opts['month'] ?? 0);
        $day = (int)($opts['day'] ?? 0);
        $rangeFrom = (int)($opts['range_from'] ?? 0);
        $rangeTo = (int)($opts['range_to'] ?? 0);

        if ($from < 1 && $year > 0) {
            if ($month > 0 && $day > 0) {
                $from = (int)mktime(0, 0, 0, $month, $day, $year);
                $to = (int)mktime(23, 59, 59, $month, $day, $year);
            } elseif ($month > 0) {
                $from = (int)mktime(0, 0, 0, $month, 1, $year);
                $to = (int)mktime(23, 59, 59, $month, (int)date('t', $from ?: time()), $year);
            } else {
                $from = (int)mktime(0, 0, 0, 1, 1, $year);
                $to = (int)mktime(23, 59, 59, 12, 31, $year);
            }
        }
        if ($rangeFrom > 0) {
            $from = $from > 0 ? max($from, $rangeFrom) : $rangeFrom;
        }
        if ($rangeTo > 0) {
            $to = $to > 0 ? min($to, $rangeTo) : $rangeTo;
        }
        if ($from < 1 || $to < $from) {
            return null;
        }
        return [$from, $to];
    }

    private static function countOrphans(string $badgeCode, int $fromTs, int $toTs): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_records
             WHERE ID_ADMIN < 1 AND ID_DICTOPHONE = ?
               AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED < 1)'
        );
        $st->execute([self::dictophoneParam($badgeCode), $fromTs, $toTs]);
        return (int)$st->fetchColumn();
    }

    /** Prefer int bind so idx_dictophone_date can be used. */
    private static function dictophoneParam(string $badgeCode)
    {
        return ctype_digit($badgeCode) ? (int)$badgeCode : $badgeCode;
    }

    /** @return array{admin_id:int,weight:int,assignment_sec:int,assigned_count:int,assigned_playtime:int,windows:list<array{from:int,to:int}>} */
    private static function emptyCandidate(int $adminId): array
    {
        return [
            'admin_id' => $adminId,
            'weight' => 0,
            'assignment_sec' => 0,
            'assigned_count' => 0,
            'assigned_playtime' => 0,
            'windows' => [],
        ];
    }
}
