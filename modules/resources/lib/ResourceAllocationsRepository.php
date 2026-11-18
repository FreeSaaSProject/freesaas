<?php
declare(strict_types=1);

final class ResourceAllocationsRepository
{
    public const SOURCE_ORDER = 'order';
    public const SOURCE_EVENT = 'event';
    public const SOURCE_PROCEDURE = 'procedure';

    public static function tableReady(): bool
    {
        return Database::tableExists('base_resource_allocations');
    }

    /**
     * Allocations overlapping [rangeStart, rangeEnd).
     *
     * @param array{order?:int,event?:int,procedure?:int|list<int>}|null $exclude
     * @return list<array{id:int,resource_id:int,dt_start:int,dt_end:int,qty_used:int,source:string,source_id:int}>
     */
    public static function listOverlapping(int $rangeStart, int $rangeEnd, ?array $exclude = null): array
    {
        if ($rangeEnd <= $rangeStart || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_RESOURCE AS resource_id, DT_START AS dt_start, DT_END AS dt_end,
                    QTY_USED AS qty_used, SOURCE AS source, SOURCE_ID AS source_id
             FROM base_resource_allocations
             WHERE DT_START < ? AND DT_END > ?
             ORDER BY DT_START ASC'
        );
        $st->execute([$rangeEnd, $rangeStart]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($exclude === null || $exclude === []) {
            return $rows;
        }
        $exOrder = max(0, (int)($exclude['order'] ?? 0));
        $exEvent = max(0, (int)($exclude['event'] ?? 0));
        $exProc = [];
        $rawProc = $exclude['procedure'] ?? null;
        if (is_array($rawProc)) {
            foreach ($rawProc as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $exProc[$pid] = true;
                }
            }
        } else {
            $pid = (int)$rawProc;
            if ($pid > 0) {
                $exProc[$pid] = true;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $src = (string)($row['source'] ?? '');
            $sid = (int)($row['source_id'] ?? 0);
            if ($exOrder > 0 && $src === self::SOURCE_ORDER && $sid === $exOrder) {
                continue;
            }
            if ($exEvent > 0 && $src === self::SOURCE_EVENT && $sid === $exEvent) {
                continue;
            }
            if ($exProc !== [] && $src === self::SOURCE_PROCEDURE && isset($exProc[$sid])) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function release(string $source, int $sourceId): void
    {
        if ($sourceId < 1 || $source === '' || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            'DELETE FROM base_resource_allocations WHERE SOURCE = ? AND SOURCE_ID = ?'
        )->execute([$source, $sourceId]);
    }

    /**
     * @param list<int> $sourceIds
     */
    public static function releaseMany(string $source, array $sourceIds): void
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn (int $id): bool => $id > 0)));
        if ($sourceIds === [] || $source === '' || !self::tableReady()) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $params = array_merge([$source], $sourceIds);
        Database::pdo()->prepare(
            "DELETE FROM base_resource_allocations WHERE SOURCE = ? AND SOURCE_ID IN ($placeholders)"
        )->execute($params);
    }

    /**
     * @param list<array{resource_id:int,qty_used:int}> $rows
     */
    public static function insertBatch(string $source, int $sourceId, int $dtStart, int $dtEnd, array $rows): void
    {
        if ($sourceId < 1 || $dtStart < 1 || $dtEnd <= $dtStart || $rows === [] || !self::tableReady()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_resource_allocations
             (ID_RESOURCE, DT_START, DT_END, QTY_USED, SOURCE, SOURCE_ID)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $rid = (int)($row['resource_id'] ?? 0);
            $used = max(1, (int)($row['qty_used'] ?? 1));
            if ($rid < 1) {
                continue;
            }
            $st->execute([$rid, $dtStart, $dtEnd, $used, $source, $sourceId]);
        }
    }

    /**
     * @param list<int> $resourceIds
     */
    public static function deleteByResourceIds(array $resourceIds): void
    {
        $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds), static fn (int $id): bool => $id > 0)));
        if ($resourceIds === [] || !self::tableReady()) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
        Database::pdo()->prepare("DELETE FROM base_resource_allocations WHERE ID_RESOURCE IN ($placeholders)")
            ->execute($resourceIds);
    }

    public static function countActiveForResource(int $resourceId, int $now): int
    {
        if ($resourceId < 1 || !self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_resource_allocations
             WHERE ID_RESOURCE = ? AND DT_END > ?'
        );
        $st->execute([$resourceId, $now]);
        return (int)$st->fetchColumn();
    }
}
