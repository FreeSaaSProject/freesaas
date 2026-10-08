<?php
declare(strict_types=1);

final class ClienticsIdMapRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_clientics_id_map');
    }

    public static function findLocalId(string $accountId, string $entity, string $extId): ?int
    {
        if (!self::tableReady() || $accountId === '' || $entity === '' || $extId === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT local_id FROM base_clientics_id_map
             WHERE account_id = ? AND entity = ? AND ext_id = ? LIMIT 1'
        );
        $st->execute([$accountId, $entity, $extId]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
    }

    public static function upsert(string $accountId, string $entity, string $extId, int $localId): void
    {
        if (!self::tableReady() || $accountId === '' || $entity === '' || $extId === '' || $localId < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_clientics_id_map (account_id, entity, ext_id, local_id, updated_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), updated_at = VALUES(updated_at)'
        );
        $st->execute([$accountId, $entity, $extId, $localId, time()]);
    }

    /** @return array<string,int> entity => count */
    public static function countsByEntity(string $accountId = ''): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $pdo = Database::pdo();
        if ($accountId !== '') {
            $st = $pdo->prepare(
                'SELECT entity, COUNT(*) AS c FROM base_clientics_id_map WHERE account_id = ? GROUP BY entity'
            );
            $st->execute([$accountId]);
        } else {
            $st = $pdo->query('SELECT entity, COUNT(*) AS c FROM base_clientics_id_map GROUP BY entity');
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string)$row['entity']] = (int)$row['c'];
        }
        return $out;
    }
}
