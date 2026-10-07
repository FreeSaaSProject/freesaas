<?php
declare(strict_types=1);

final class BotSendsRepository
{
    public const STATUS_PENDING = 1;
    public const STATUS_SENT = 2;
    public const STATUS_ERROR = 3;
    public const STATUS_SKIPPED = 100;

    public const SOURCE_CLIENT = 1;
    public const SOURCE_STAFF = 2;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_telegram_sends');
    }

    public static function audienceToSource(string $audience): int
    {
        return strtolower($audience) === 'client' ? self::SOURCE_CLIENT : self::SOURCE_STAFF;
    }

    public static function sourceToAudience(int $source): string
    {
        return $source === self::SOURCE_CLIENT ? 'client' : 'staff';
    }

    /**
     * @param array{
     *   audience?:string,user_id?:int,client_id?:int,text:string,name?:string,
     *   dtsend?:int,deadline?:int,id_event?:int,id_data?:int,type?:int,
     *   chat_id?:string,dedupe_key?:string,buttons?:array|null
     * } $opts
     */
    public static function enqueue(array $opts): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('bots.err.table'));
        }
        $audience = strtolower((string)($opts['audience'] ?? 'staff'));
        $source = self::audienceToSource($audience);
        $userId = (int)($opts['user_id'] ?? 0);
        if ($userId < 1 && isset($opts['client_id'])) {
            $userId = (int)$opts['client_id'];
        }
        $text = (string)($opts['text'] ?? '');
        if ($userId < 1 || trim($text) === '') {
            throw new InvalidArgumentException(t('bots.err.enqueue_args'));
        }
        $dedupe = trim((string)($opts['dedupe_key'] ?? ''));
        if ($dedupe !== '' && Database::columnExists('base_telegram_sends', 'DEDUPE_KEY')) {
            $st = Database::pdo()->prepare(
                'SELECT ID FROM base_telegram_sends WHERE DEDUPE_KEY = ? AND BOT_SOURCE = ? AND STATUS = ? LIMIT 1'
            );
            $st->execute([$dedupe, $source, self::STATUS_PENDING]);
            $existing = (int)$st->fetchColumn();
            if ($existing > 0) {
                return $existing;
            }
        }

        $buttons = $opts['buttons'] ?? null;
        if (is_array($buttons) && $buttons !== []) {
            $marker = '[[INLINE_JSON]]' . json_encode($buttons, JSON_UNESCAPED_UNICODE) . '[[/INLINE_JSON]]';
            $text = rtrim($text) . "\n" . $marker;
        }

        $now = time();
        $dtsend = (int)($opts['dtsend'] ?? $now);
        if ($dtsend <= 0) {
            $dtsend = $now;
        }
        $cols = [
            'ID_USER', 'BOT_SOURCE', 'TYPE', 'NAME', 'TEXT', 'DTSEND', 'DTCREATE', 'STATUS',
            'CHAT_ID', 'ID_EVENT', 'ID_DATA', 'ID_OPERATOR',
        ];
        $vals = [
            $userId,
            $source,
            (int)($opts['type'] ?? 11),
            (string)($opts['name'] ?? ''),
            $text,
            $dtsend,
            $now,
            self::STATUS_PENDING,
            isset($opts['chat_id']) ? (string)$opts['chat_id'] : null,
            (int)($opts['id_event'] ?? 0) ?: null,
            (int)($opts['id_data'] ?? 0) ?: null,
            (int)($opts['id_operator'] ?? 0),
        ];
        if (Database::columnExists('base_telegram_sends', 'DT_SEND_DEADLINE')) {
            $cols[] = 'DT_SEND_DEADLINE';
            $deadline = (int)($opts['deadline'] ?? 0);
            $vals[] = $deadline > 0 ? $deadline : null;
        }
        if (Database::columnExists('base_telegram_sends', 'DEDUPE_KEY')) {
            $cols[] = 'DEDUPE_KEY';
            $vals[] = $dedupe !== '' ? $dedupe : null;
        }
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO base_telegram_sends (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
        Database::pdo()->prepare($sql)->execute($vals);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @return array{items:list<array>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $params = [];
        if (isset($opts['status']) && (int)$opts['status'] >= 0) {
            $where[] = 'STATUS = ?';
            $params[] = (int)$opts['status'];
        }
        if (!empty($opts['audience'])) {
            $where[] = 'BOT_SOURCE = ?';
            $params[] = self::audienceToSource((string)$opts['audience']);
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR TEXT LIKE ? OR CAST(ID AS CHAR) = ? OR CAST(ID_USER AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_telegram_sends WHERE ' . $sqlWhere);
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $limit = min(200, max(1, (int)($opts['limit'] ?? 50)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_telegram_sends WHERE ' . $sqlWhere
            . ' ORDER BY ID DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['items' => $items, 'total' => $total];
    }

    public static function countPending(?string $audience = null): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) FROM base_telegram_sends WHERE STATUS = ? AND DTSEND <= ?';
        $params = [self::STATUS_PENDING, time()];
        if ($audience !== null && $audience !== '') {
            $sql .= ' AND BOT_SOURCE = ?';
            $params[] = self::audienceToSource($audience);
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function claimPending(int $limit, ?string $audience = null): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $limit = min(100, max(1, $limit));
        $sql = 'SELECT * FROM base_telegram_sends WHERE STATUS = ? AND DTSEND <= ?';
        $params = [self::STATUS_PENDING, time()];
        if ($audience !== null && $audience !== '') {
            $sql .= ' AND BOT_SOURCE = ?';
            $params[] = self::audienceToSource($audience);
        }
        $sql .= ' ORDER BY DTSEND ASC, ID ASC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function mark(int $id, int $status, string $descr = '', string $error = ''): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_telegram_sends SET STATUS = ?, STATUS_DESCR = ?, ERROR = ? WHERE ID = ?'
        );
        $st->execute([
            $status,
            $descr !== '' ? substr($descr, 0, 255) : null,
            $error !== '' ? substr($error, 0, 512) : null,
            $id,
        ]);
    }

    public static function setChatId(int $id, string $chatId): void
    {
        if ($id < 1) {
            return;
        }
        $st = Database::pdo()->prepare('UPDATE base_telegram_sends SET CHAT_ID = ? WHERE ID = ?');
        $st->execute([$chatId, $id]);
    }

    /**
     * Strip [[INLINE_JSON]]…[[/INLINE_JSON]] marker; return [text, markup|null].
     *
     * @return array{0:string,1:?array}
     */
    public static function extractButtons(string $text): array
    {
        if (strpos($text, '[[INLINE_JSON]]') === false) {
            return [$text, null];
        }
        if (!preg_match('/\[\[INLINE_JSON\]\](.*)\[\[\/INLINE_JSON\]\]/s', $text, $m)) {
            return [$text, null];
        }
        $markup = json_decode($m[1], true);
        $clean = trim(str_replace($m[0], '', $text));
        $clean = trim(preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean);
        return [$clean, is_array($markup) ? $markup : null];
    }
}
