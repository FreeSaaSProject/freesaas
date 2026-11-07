<?php
declare(strict_types=1);

final class BitrixIdMapRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bitrix_id_map');
    }

    public static function findLocalId(string $portal, string $entity, string $extId): ?int
    {
        if (!self::tableReady() || $portal === '' || $entity === '' || $extId === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT local_id FROM base_bitrix_id_map
             WHERE portal = ? AND entity = ? AND ext_id = ? LIMIT 1'
        );
        $st->execute([$portal, $entity, $extId]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
    }

    public static function findExtId(string $portal, string $entity, int $localId): ?string
    {
        if (!self::tableReady() || $portal === '' || $entity === '' || $localId < 1) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ext_id FROM base_bitrix_id_map
             WHERE portal = ? AND entity = ? AND local_id = ? LIMIT 1'
        );
        $st->execute([$portal, $entity, $localId]);
        $v = $st->fetchColumn();
        return $v !== false ? (string)$v : null;
    }

    public static function upsert(string $portal, string $entity, string $extId, int $localId): void
    {
        if (!self::tableReady() || $portal === '' || $entity === '' || $extId === '' || $localId < 1) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO base_bitrix_id_map (portal, entity, ext_id, local_id, updated_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), updated_at = VALUES(updated_at)'
        )->execute([$portal, $entity, $extId, $localId, time()]);
    }

    /** @return array<string,int> */
    public static function countsByEntity(string $portal = ''): array
    {
        if (!self::tableReady()) {
            return [];
        }
        if ($portal !== '') {
            $st = Database::pdo()->prepare(
                'SELECT entity, COUNT(*) AS c FROM base_bitrix_id_map WHERE portal = ? GROUP BY entity'
            );
            $st->execute([$portal]);
        } else {
            $st = Database::pdo()->query('SELECT entity, COUNT(*) AS c FROM base_bitrix_id_map GROUP BY entity');
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string)$row['entity']] = (int)$row['c'];
        }
        return $out;
    }
}
