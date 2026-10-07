<?php
declare(strict_types=1);

/**
 * Messenger identities for staff/clients — owned by bots, not base_users/base_clients.
 *
 * BOT_SOURCE: 1=client (base_clients.id), 2=staff (base_users.id) — same as base_telegram_sends.
 */
final class BotLinksRepository
{
    public const SOURCE_CLIENT = 1;
    public const SOURCE_STAFF = 2;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_bot_links');
    }

    public static function audienceToSource(string $audience): int
    {
        return strtolower($audience) === 'client' ? self::SOURCE_CLIENT : self::SOURCE_STAFF;
    }

    /**
     * @return array{telegram_chat_id:string,max_user_id:string,BOT_NO_SEND:int}|null
     */
    public static function find(int $source, int $entityId): ?array
    {
        if (!self::tableReady() || $entityId < 1) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT telegram_chat_id, max_user_id, BOT_NO_SEND
             FROM base_bot_links WHERE BOT_SOURCE = ? AND ID_ENTITY = ? LIMIT 1'
        );
        $st->execute([$source, $entityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return self::normalizeRow($row);
    }

    /**
     * Batch load links for list pages.
     *
     * @param list<int> $entityIds
     * @return array<int, array{telegram_chat_id:string,max_user_id:string,BOT_NO_SEND:int}>
     */
    public static function mapForEntities(int $source, array $entityIds): array
    {
        $ids = [];
        foreach ($entityIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === [] || !self::tableReady()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$source], array_values($ids));
        $st = Database::pdo()->prepare(
            'SELECT ID_ENTITY, telegram_chat_id, max_user_id, BOT_NO_SEND
             FROM base_bot_links WHERE BOT_SOURCE = ? AND ID_ENTITY IN (' . $placeholders . ')'
        );
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $eid = (int)($row['ID_ENTITY'] ?? 0);
            if ($eid < 1) {
                continue;
            }
            $out[$eid] = self::normalizeRow($row);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{telegram_chat_id:string,max_user_id:string,BOT_NO_SEND:int}
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'telegram_chat_id' => BotIdentity::chatIdValue($row['telegram_chat_id'] ?? ''),
            'max_user_id' => BotIdentity::chatIdValue($row['max_user_id'] ?? ''),
            'BOT_NO_SEND' => !empty($row['BOT_NO_SEND']) ? 1 : 0,
        ];
    }

    /**
     * @return array{telegram:string,max:string,bot_no_send:bool}
     */
    public static function targets(int $source, int $entityId): array
    {
        $out = ['telegram' => '', 'max' => '', 'bot_no_send' => false];
        $row = self::find($source, $entityId);
        if ($row === null) {
            return $out;
        }
        $out['telegram'] = $row['telegram_chat_id'];
        $out['max'] = $row['max_user_id'];
        $out['bot_no_send'] = $row['BOT_NO_SEND'] === 1;
        return $out;
    }

    /**
     * @return array{id:int,name:string,tel:string,enabled:mixed,telegram_chat_id:string,max_user_id:string,BOT_NO_SEND:int}|null
     */
    public static function findEntityByChat(int $source, string $platform, string $chatId): ?array
    {
        $chatId = BotIdentity::chatIdValue($chatId);
        if ($chatId === '' || !self::tableReady()) {
            return null;
        }
        $col = $platform === 'max' ? 'max_user_id' : 'telegram_chat_id';
        $st = Database::pdo()->prepare(
            'SELECT ID_ENTITY, telegram_chat_id, max_user_id, BOT_NO_SEND
             FROM base_bot_links WHERE BOT_SOURCE = ? AND `' . $col . '` = ? LIMIT 1'
        );
        $st->execute([$source, $chatId]);
        $link = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($link)) {
            return null;
        }
        $entityId = (int)($link['ID_ENTITY'] ?? 0);
        if ($entityId < 1) {
            return null;
        }
        $entity = self::loadEntity($source, $entityId);
        if ($entity === null) {
            return null;
        }
        $entity['telegram_chat_id'] = BotIdentity::chatIdValue($link['telegram_chat_id'] ?? '');
        $entity['max_user_id'] = BotIdentity::chatIdValue($link['max_user_id'] ?? '');
        $entity['BOT_NO_SEND'] = !empty($link['BOT_NO_SEND']) ? 1 : 0;
        return $entity;
    }

    public static function bindPlatform(int $source, int $entityId, string $platform, string $chatId): bool
    {
        if (!self::tableReady() || $entityId < 1) {
            return false;
        }
        $chatId = BotIdentity::chatIdValue($chatId);
        if ($chatId === '') {
            return false;
        }
        $existing = self::find($source, $entityId) ?? [
            'telegram_chat_id' => '',
            'max_user_id' => '',
            'BOT_NO_SEND' => 0,
        ];
        if ($platform === 'max') {
            $existing['max_user_id'] = $chatId;
        } else {
            $existing['telegram_chat_id'] = $chatId;
        }
        self::upsert($source, $entityId, $existing);
        return true;
    }

    /**
     * @param array{telegram_chat_id?:string,max_user_id?:string,BOT_NO_SEND?:int|bool} $data
     */
    public static function upsert(int $source, int $entityId, array $data): void
    {
        if (!self::tableReady() || $entityId < 1) {
            throw new RuntimeException(t('bots.err.links_table'));
        }
        $tg = BotIdentity::chatIdValue($data['telegram_chat_id'] ?? '');
        $max = BotIdentity::chatIdValue($data['max_user_id'] ?? '');
        $noSend = !empty($data['BOT_NO_SEND']) ? 1 : 0;
        $now = time();
        $tgSql = $tg === '' ? null : $tg;
        $maxSql = $max === '' ? null : $max;

        $st = Database::pdo()->prepare(
            'INSERT INTO base_bot_links
             (BOT_SOURCE, ID_ENTITY, telegram_chat_id, max_user_id, BOT_NO_SEND, DTCREATE, DTUPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               telegram_chat_id = VALUES(telegram_chat_id),
               max_user_id = VALUES(max_user_id),
               BOT_NO_SEND = VALUES(BOT_NO_SEND),
               DTUPDATE = VALUES(DTUPDATE)'
        );
        $st->execute([$source, $entityId, $tgSql, $maxSql, $noSend, $now, $now]);
    }

    /**
     * Merge link fields into a user/client row for forms (mutates copy).
     *
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    public static function mergeOnto(array $entity, int $source): array
    {
        $id = (int)($entity['id'] ?? 0);
        $link = $id > 0 ? self::find($source, $id) : null;
        $entity['telegram_chat_id'] = $link['telegram_chat_id'] ?? '';
        $entity['max_user_id'] = $link['max_user_id'] ?? '';
        $entity['BOT_NO_SEND'] = $link['BOT_NO_SEND'] ?? 0;
        return $entity;
    }

    /**
     * @return array{id:int,name:string,tel:string,enabled:mixed}|null
     */
    private static function loadEntity(int $source, int $entityId): ?array
    {
        if ($source === self::SOURCE_STAFF) {
            if (!Database::tableExists('base_users')) {
                return null;
            }
            $st = Database::pdo()->prepare(
                'SELECT id, name, tel, enabled FROM base_users WHERE id = ? LIMIT 1'
            );
            $st->execute([$entityId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }
        if (!Database::tableExists('base_clients')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, tel, enabled FROM base_clients WHERE id = ? LIMIT 1'
        );
        $st->execute([$entityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
