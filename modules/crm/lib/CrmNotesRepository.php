<?php
declare(strict_types=1);

final class CrmNotesRepository
{
    public const ENTITIES = ['company', 'deal', 'contact'];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_crm_notes');
    }

    /** @return list<array<string,mixed>> */
    public static function listFor(string $entity, int $entityId, int $limit = 100): array
    {
        if ($entityId < 1 || !in_array($entity, self::ENTITIES, true) || !self::tableReady()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $st = Database::pdo()->prepare(
            'SELECT id, entity, entity_id, body, author_user_id, created_at
             FROM base_crm_notes
             WHERE entity = ? AND entity_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );
        $st->execute([$entity, $entityId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function create(string $entity, int $entityId, string $body, int $authorUserId = 0): int
    {
        if (!in_array($entity, self::ENTITIES, true) || $entityId < 1) {
            throw new InvalidArgumentException(t('crm.err.note_entity'));
        }
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException(t('crm.err.note_body'));
        }
        if ($authorUserId < 1 && class_exists('Auth', false) && method_exists('Auth', 'userId')) {
            $authorUserId = (int)Auth::userId();
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_crm_notes (entity, entity_id, body, author_user_id, created_at)
             VALUES (?,?,?,?,?)'
        )->execute([$entity, $entityId, $body, $authorUserId, time()]);
        $id = (int)$pdo->lastInsertId();
        EventBus::emit('crm.note.created', CrmSyncContext::withOrigin([
            'id' => $id,
            'entity' => $entity,
            'entity_id' => $entityId,
            'body' => $body,
        ]));
        return $id;
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_crm_notes WHERE id = ?')->execute([$id]);
    }
}
