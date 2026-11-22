<?php
declare(strict_types=1);

/**
 * Known outbound group chats (Max / Telegram) for notify and recipients UI.
 */
final class BotChatsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bot_chats');
    }

    public static function normalizePlatform(string $platform): string
    {
        $p = strtolower(trim($platform));
        if ($p === 'tg') {
            $p = 'telegram';
        }
        return in_array($p, ['max', 'telegram'], true) ? $p : 'max';
    }

    /**
     * @param array{platform?:string,chat_id:string,title?:string,purpose?:string,description?:string,enabled?:int|bool} $data
     */
    public static function upsert(array $data): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $chatId = trim((string)($data['chat_id'] ?? ''));
        if ($chatId === '') {
            return 0;
        }
        $platform = self::normalizePlatform((string)($data['platform'] ?? 'max'));
        $title = trim((string)($data['title'] ?? ''));
        $purpose = trim((string)($data['purpose'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $enabled = !isset($data['enabled']) || !empty($data['enabled']) ? 1 : 0;
        $now = time();
        $existing = self::findByChat($platform, $chatId);
        if ($existing !== null) {
            $id = (int)$existing['id'];
            if ($title === '') {
                $title = (string)($existing['title'] ?? '');
            }
            if ($purpose === '') {
                $purpose = (string)($existing['purpose'] ?? '');
            }
            if ($description === '') {
                $description = (string)($existing['description'] ?? '');
            }
            $st = Database::pdo()->prepare(
                'UPDATE base_bot_chats
                 SET TITLE = ?, PURPOSE = ?, DESCRIPTION = ?, ENABLED = ?, DTUPDATE = ?
                 WHERE ID = ?'
            );
            $st->execute([$title, $purpose, $description, $enabled, $now, $id]);
            return $id;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_bot_chats (PLATFORM, CHAT_ID, TITLE, PURPOSE, DESCRIPTION, ENABLED, DTCREATE, DTUPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$platform, $chatId, $title, $purpose, $description, $enabled, $now, $now]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * Remember a chat seen on inbound (does not disable; fills title if empty).
     */
    public static function remember(string $platform, string $chatId, string $title = ''): void
    {
        $chatId = trim($chatId);
        if ($chatId === '' || !self::tableReady()) {
            return;
        }
        $platform = self::normalizePlatform($platform);
        $existing = self::findByChat($platform, $chatId);
        if ($existing !== null) {
            if ($title !== '' && trim((string)($existing['title'] ?? '')) === '') {
                self::upsert([
                    'platform' => $platform,
                    'chat_id' => $chatId,
                    'title' => $title,
                    'enabled' => (int)($existing['enabled'] ?? 1),
                    'purpose' => (string)($existing['purpose'] ?? ''),
                    'description' => (string)($existing['description'] ?? ''),
                ]);
            }
            return;
        }
        self::upsert([
            'platform' => $platform,
            'chat_id' => $chatId,
            'title' => $title,
            'enabled' => 1,
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_bot_chats WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::normalizeRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByChat(string $platform, string $chatId): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $chatId = trim($chatId);
        if ($chatId === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_bot_chats WHERE PLATFORM = ? AND CHAT_ID = ? LIMIT 1'
        );
        $st->execute([self::normalizePlatform($platform), $chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::normalizeRow($row) : null;
    }

    /**
     * @param array{q?:string,enabled?:int|null,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $params = [];
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null) {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$opts['enabled'];
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(TITLE LIKE ? OR CHAT_ID LIKE ? OR PURPOSE LIKE ? OR DESCRIPTION LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_bot_chats WHERE ' . $sqlWhere);
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $limit = max(1, min(500, (int)($opts['limit'] ?? 100)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_bot_chats WHERE ' . $sqlWhere
            . ' ORDER BY ENABLED DESC, TITLE ASC, ID ASC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $st->execute($params);
        $items = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $items[] = self::normalizeRow($row);
            }
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function delete(int $id): bool
    {
        if ($id < 1 || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_bot_chats WHERE ID = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    public static function setEnabled(int $id, bool $enabled): bool
    {
        if ($id < 1 || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_bot_chats SET ENABLED = ?, DTUPDATE = ? WHERE ID = ?'
        );
        $st->execute([$enabled ? 1 : 0, time(), $id]);
        return $st->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id' => (int)($row['ID'] ?? $row['id'] ?? 0),
            'platform' => self::normalizePlatform((string)($row['PLATFORM'] ?? $row['platform'] ?? 'max')),
            'chat_id' => trim((string)($row['CHAT_ID'] ?? $row['chat_id'] ?? '')),
            'title' => trim((string)($row['TITLE'] ?? $row['title'] ?? '')),
            'purpose' => trim((string)($row['PURPOSE'] ?? $row['purpose'] ?? '')),
            'description' => trim((string)($row['DESCRIPTION'] ?? $row['description'] ?? '')),
            'enabled' => (int)($row['ENABLED'] ?? $row['enabled'] ?? 0),
            'dtcreate' => (int)($row['DTCREATE'] ?? $row['dtcreate'] ?? 0),
            'dtupdate' => (int)($row['DTUPDATE'] ?? $row['dtupdate'] ?? 0),
        ];
    }
}
