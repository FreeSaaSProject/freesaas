<?php
declare(strict_types=1);

final class AmocrmIdMapRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_amocrm_id_map');
    }

    public static function findLocalId(string $account, string $entity, string $extId): ?int
    {
        if (!self::tableReady() || $account === '' || $entity === '' || $extId === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT local_id FROM base_amocrm_id_map WHERE account = ? AND entity = ? AND ext_id = ? LIMIT 1'
        );
        $st->execute([$account, $entity, $extId]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
    }

    public static function findExtId(string $account, string $entity, int $localId): ?string
    {
        if (!self::tableReady() || $localId < 1) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ext_id FROM base_amocrm_id_map WHERE account = ? AND entity = ? AND local_id = ? LIMIT 1'
        );
        $st->execute([$account, $entity, $localId]);
        $v = $st->fetchColumn();
        return $v !== false ? (string)$v : null;
    }

    public static function upsert(string $account, string $entity, string $extId, int $localId): void
    {
        if (!self::tableReady() || $localId < 1) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO base_amocrm_id_map (account, entity, ext_id, local_id, updated_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), updated_at = VALUES(updated_at)'
        )->execute([$account, $entity, $extId, $localId, time()]);
    }

    /** @return array<string,int> */
    public static function countsByEntity(string $account = ''): array
    {
        if (!self::tableReady()) {
            return [];
        }
        if ($account !== '') {
            $st = Database::pdo()->prepare(
                'SELECT entity, COUNT(*) AS c FROM base_amocrm_id_map WHERE account = ? GROUP BY entity'
            );
            $st->execute([$account]);
        } else {
            $st = Database::pdo()->query('SELECT entity, COUNT(*) AS c FROM base_amocrm_id_map GROUP BY entity');
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string)$row['entity']] = (int)$row['c'];
        }
        return $out;
    }
}
