<?php
declare(strict_types=1);

/** base_crm_links: deal/company → foreign record (target = ticket | poll_invite | …). */
final class CrmLinksRepository
{
    public const ENTITIES = ['deal', 'company'];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_crm_links');
    }

    public static function add(string $entity, int $entityId, string $target, int $targetId): void
    {
        if (!in_array($entity, self::ENTITIES, true) || $entityId < 1 || $targetId < 1 || $target === '' || !self::tableReady()) {
            throw new InvalidArgumentException(t('crm.err.link_invalid'));
        }
        Database::pdo()->prepare(
            'INSERT IGNORE INTO base_crm_links (entity, entity_id, target, target_id, created_at, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$entity, $entityId, $target, $targetId, time(), (int)Auth::userId()]);
    }

    public static function remove(string $entity, int $entityId, string $target, int $targetId): bool
    {
        if (!self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM base_crm_links WHERE entity = ? AND entity_id = ? AND target = ? AND target_id = ?'
        );
        $st->execute([$entity, $entityId, $target, $targetId]);
        return $st->rowCount() > 0;
    }

    public static function removeEntity(string $entity, int $entityId): void
    {
        if (!self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_crm_links WHERE entity = ? AND entity_id = ?')
            ->execute([$entity, $entityId]);
    }

    /** @return list<array{target_id:int,created_at:int,created_by:int}> */
    public static function targets(string $entity, int $entityId, string $target): array
    {
        if ($entityId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT target_id, created_at, created_by FROM base_crm_links
             WHERE entity = ? AND entity_id = ? AND target = ? ORDER BY created_at DESC, id DESC'
        );
        $st->execute([$entity, $entityId, $target]);
        return array_map(static function (array $r): array {
            return ['target_id' => (int)$r['target_id'], 'created_at' => (int)$r['created_at'], 'created_by' => (int)$r['created_by']];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array{entity:string,entity_id:int}> */
    public static function entitiesFor(string $target, int $targetId): array
    {
        if ($targetId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT entity, entity_id FROM base_crm_links WHERE target = ? AND target_id = ? ORDER BY id ASC'
        );
        $st->execute([$target, $targetId]);
        return array_map(static function (array $r): array {
            return ['entity' => (string)$r['entity'], 'entity_id' => (int)$r['entity_id']];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}
