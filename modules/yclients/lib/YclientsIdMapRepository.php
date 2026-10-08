<?php
declare(strict_types=1);

final class YclientsIdMapRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_yclients_id_map');
    }

    public static function findLocalId(int $companyId, string $entity, string $extId): ?int
    {
        if (!self::tableReady() || $companyId < 1 || $entity === '' || $extId === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT local_id FROM base_yclients_id_map
             WHERE company_id = ? AND entity = ? AND ext_id = ? LIMIT 1'
        );
        $st->execute([$companyId, $entity, $extId]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
    }

    public static function upsert(int $companyId, string $entity, string $extId, int $localId): void
    {
        if (!self::tableReady() || $companyId < 1 || $entity === '' || $extId === '' || $localId < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_yclients_id_map (company_id, entity, ext_id, local_id, updated_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), updated_at = VALUES(updated_at)'
        );
        $st->execute([$companyId, $entity, $extId, $localId, time()]);
    }

    /** @return array<string,int> entity => count */
    public static function countsByEntity(int $companyId = 0): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $pdo = Database::pdo();
        if ($companyId > 0) {
            $st = $pdo->prepare(
                'SELECT entity, COUNT(*) AS c FROM base_yclients_id_map WHERE company_id = ? GROUP BY entity'
            );
            $st->execute([$companyId]);
        } else {
            $st = $pdo->query('SELECT entity, COUNT(*) AS c FROM base_yclients_id_map GROUP BY entity');
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string)$row['entity']] = (int)$row['c'];
        }
        return $out;
    }
}
