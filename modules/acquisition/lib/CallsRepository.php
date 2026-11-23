<?php
declare(strict_types=1);

/**
 * Inquiry / call journal (beautime base_calls).
 * ID_RESULT 0 = unmarked; client_id 0 = no client card yet.
 */
final class CallsRepository
{
    public const RESULT_UNMARKED = 0;
    public const RESULT_NEW = 1;
    public const RESULT_BOOKED = 2;
    public const RESULT_LOST = 3;
    public const RESULT_NA = 4;
    public const RESULT_CONFIRM = 5;
    public const RESULT_WONT_COME = 6;
    public const RESULT_NO_ANSWER = 7;

    public const KIND_UNKNOWN = 0;
    public const KIND_PRIMARY = 1;
    public const KIND_SECONDARY = 2;
    public const KIND_COMPLAINT = 3;
    public const KIND_DIRECTORS = 4;
    public const KIND_OTHER = 5;

    public const TYPE_IN = 1;
    public const TYPE_OUT = 2;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_calls');
    }

    /** @return array<int,string> */
    public static function resultLabels(): array
    {
        return [
            self::RESULT_UNMARKED => t('acquisition.calls.result.0'),
            self::RESULT_NEW => t('acquisition.calls.result.1'),
            self::RESULT_BOOKED => t('acquisition.calls.result.2'),
            self::RESULT_LOST => t('acquisition.calls.result.3'),
            self::RESULT_NA => t('acquisition.calls.result.4'),
            self::RESULT_CONFIRM => t('acquisition.calls.result.5'),
            self::RESULT_WONT_COME => t('acquisition.calls.result.6'),
            self::RESULT_NO_ANSWER => t('acquisition.calls.result.7'),
        ];
    }

    /** @return array<int,string> */
    public static function kindLabels(): array
    {
        return [
            self::KIND_UNKNOWN => t('acquisition.calls.kind.0'),
            self::KIND_PRIMARY => t('acquisition.calls.kind.1'),
            self::KIND_SECONDARY => t('acquisition.calls.kind.2'),
            self::KIND_COMPLAINT => t('acquisition.calls.kind.3'),
            self::KIND_DIRECTORS => t('acquisition.calls.kind.4'),
            self::KIND_OTHER => t('acquisition.calls.kind.5'),
        ];
    }

    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            return '7' . substr($digits, 1);
        }
        return $digits;
    }

    /**
     * @param array{
     *   q?:string,unmarked?:int,admin_id?:int,result?:int,day_start?:int,day_end?:int,
     *   limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['ID > 0'];
        $params = [];
        if (!empty($opts['unmarked'])) {
            $where[] = '(ID_RESULT = 0 OR DT_FILLED = 0)';
        }
        if (isset($opts['result']) && (int)$opts['result'] >= 0 && ($opts['unmarked'] ?? 0) < 1) {
            $where[] = 'ID_RESULT = ?';
            $params[] = (int)$opts['result'];
        }
        $adminId = (int)($opts['admin_id'] ?? 0);
        if ($adminId > 0) {
            $where[] = 'ID_ADMIN = ?';
            $params[] = $adminId;
        }
        $dayStart = (int)($opts['day_start'] ?? 0);
        $dayEnd = (int)($opts['day_end'] ?? 0);
        if ($dayStart > 0) {
            $where[] = 'DT_CALL >= ?';
            $params[] = $dayStart;
        }
        if ($dayEnd > 0) {
            $where[] = 'DT_CALL <= ?';
            $params[] = $dayEnd;
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $phone = self::normalizePhone($q);
            if ($phone !== '') {
                $where[] = 'PHONE LIKE ?';
                $params[] = '%' . $phone . '%';
            } else {
                $where[] = '(REQUEST LIKE ? OR RESULT_TEXT LIKE ?)';
                $params[] = '%' . $q . '%';
                $params[] = '%' . $q . '%';
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $limit = max(1, min(200, (int)($opts['limit'] ?? 50)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_calls WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $st = $pdo->prepare(
            "SELECT ID AS id, ID_ADMIN AS admin_id, ID_CLIENT AS client_id,
                    ID_CLIENT_TYPE AS client_kind, ID_RESULT AS result_id,
                    DT_CALL AS dt_call, DT_FILLED AS dt_filled, PHONE AS phone,
                    TYPE AS direction, CHANNEL AS channel, PROVIDER AS provider,
                    PROVIDER_CALL_ID AS provider_call_id, REQUEST AS request,
                    RESULT_TEXT AS result_text, FILEDATA AS filedata,
                    ID_EVENT AS event_id, ID_ORDER AS order_id
             FROM base_calls WHERE $sqlWhere
             ORDER BY DT_CALL DESC, ID DESC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::cast($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function countUnmarked(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->query(
            'SELECT COUNT(*) FROM base_calls WHERE ID_RESULT = 0 OR DT_FILLED = 0'
        );
        return (int)$st->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ADMIN AS admin_id, ID_CLIENT AS client_id,
                    ID_CLIENT_TYPE AS client_kind, ID_RESULT AS result_id,
                    DT_CALL AS dt_call, DT_FILLED AS dt_filled, PHONE AS phone,
                    TYPE AS direction, CHANNEL AS channel, PROVIDER AS provider,
                    PROVIDER_CALL_ID AS provider_call_id, REQUEST AS request,
                    RESULT_TEXT AS result_text, FILEDATA AS filedata,
                    ID_EVENT AS event_id, ID_ORDER AS order_id
             FROM base_calls WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * Idempotent ingest (VATS / site / messenger).
     * @param array<string,mixed> $data
     */
    public static function upsertIngest(array $data): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('acquisition.err.no_table'));
        }
        $provider = trim((string)($data['provider'] ?? ''));
        $providerCallId = trim((string)($data['provider_call_id'] ?? ''));
        if ($provider !== '' && $providerCallId !== '') {
            $st = Database::pdo()->prepare(
                'SELECT ID FROM base_calls WHERE PROVIDER = ? AND PROVIDER_CALL_ID = ? LIMIT 1'
            );
            $st->execute([$provider, $providerCallId]);
            $existing = (int)$st->fetchColumn();
            if ($existing > 0) {
                return $existing;
            }
        }
        return self::create($data);
    }

    /** @param array<string,mixed> $post */
    public static function create(array $post): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('acquisition.err.no_table'));
        }
        $phone = self::normalizePhone((string)($post['phone'] ?? ''));
        $dtCall = self::parseDt($post['dt_call'] ?? time());
        if ($dtCall < 1) {
            $dtCall = time();
        }
        $resultId = max(0, (int)($post['result_id'] ?? 0));
        $dtFilled = $resultId > 0 ? time() : 0;
        if (!empty($post['dt_filled'])) {
            $dtFilled = self::parseDt($post['dt_filled']);
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_calls
             (ID_ADMIN, ID_CLIENT, ID_CLIENT_TYPE, ID_RESULT, DT_CALL, DT_FILLED, PHONE,
              TYPE, CHANNEL, PROVIDER, PROVIDER_CALL_ID, REQUEST, RESULT_TEXT, FILEDATA,
              ID_EVENT, ID_ORDER)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            max(0, (int)($post['admin_id'] ?? Auth::userId())),
            max(0, (int)($post['client_id'] ?? 0)),
            max(0, (int)($post['client_kind'] ?? 0)),
            $resultId,
            $dtCall,
            $dtFilled,
            $phone,
            max(1, min(2, (int)($post['direction'] ?? self::TYPE_IN))),
            substr(trim((string)($post['channel'] ?? 'manual')), 0, 32),
            substr(trim((string)($post['provider'] ?? '')), 0, 32),
            substr(trim((string)($post['provider_call_id'] ?? '')), 0, 64),
            trim((string)($post['request'] ?? '')),
            trim((string)($post['result_text'] ?? '')),
            trim((string)($post['filedata'] ?? '')),
            max(0, (int)($post['event_id'] ?? 0)),
            max(0, (int)($post['order_id'] ?? 0)),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $post */
    public static function update(int $id, array $post): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new InvalidArgumentException(t('acquisition.calls.err.not_found'));
        }
        $resultId = array_key_exists('result_id', $post)
            ? max(0, (int)$post['result_id'])
            : (int)$row['result_id'];
        $dtFilled = (int)$row['dt_filled'];
        if ($resultId > 0 && $dtFilled < 1) {
            $dtFilled = time();
        }
        if (array_key_exists('dt_filled', $post)) {
            $dtFilled = self::parseDt($post['dt_filled']);
        }
        $phone = array_key_exists('phone', $post)
            ? self::normalizePhone((string)$post['phone'])
            : (string)$row['phone'];
        $st = Database::pdo()->prepare(
            'UPDATE base_calls SET
             ID_ADMIN=?, ID_CLIENT=?, ID_CLIENT_TYPE=?, ID_RESULT=?, DT_CALL=?, DT_FILLED=?,
             PHONE=?, TYPE=?, CHANNEL=?, REQUEST=?, RESULT_TEXT=?, FILEDATA=?,
             ID_EVENT=?, ID_ORDER=?
             WHERE ID=?'
        );
        $st->execute([
            array_key_exists('admin_id', $post) ? max(0, (int)$post['admin_id']) : (int)$row['admin_id'],
            array_key_exists('client_id', $post) ? max(0, (int)$post['client_id']) : (int)$row['client_id'],
            array_key_exists('client_kind', $post) ? max(0, (int)$post['client_kind']) : (int)$row['client_kind'],
            $resultId,
            array_key_exists('dt_call', $post) ? self::parseDt($post['dt_call']) : (int)$row['dt_call'],
            $dtFilled,
            $phone,
            array_key_exists('direction', $post)
                ? max(1, min(2, (int)$post['direction']))
                : (int)$row['direction'],
            array_key_exists('channel', $post)
                ? substr(trim((string)$post['channel']), 0, 32)
                : (string)$row['channel'],
            array_key_exists('request', $post) ? trim((string)$post['request']) : (string)$row['request'],
            array_key_exists('result_text', $post)
                ? trim((string)$post['result_text'])
                : (string)$row['result_text'],
            array_key_exists('filedata', $post) ? trim((string)$post['filedata']) : (string)$row['filedata'],
            array_key_exists('event_id', $post) ? max(0, (int)$post['event_id']) : (int)$row['event_id'],
            array_key_exists('order_id', $post) ? max(0, (int)$post['order_id']) : (int)$row['order_id'],
            $id,
        ]);
    }

    /** Soft-archive: mark N/A rather than hard delete. */
    public static function archive(int $id): void
    {
        self::update($id, [
            'result_id' => self::RESULT_NA,
            'dt_filled' => time(),
        ]);
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        foreach ([
            'id', 'admin_id', 'client_id', 'client_kind', 'result_id', 'dt_call', 'dt_filled',
            'direction', 'event_id', 'order_id',
        ] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int)$row[$k];
            }
        }
        foreach (['phone', 'channel', 'provider', 'provider_call_id', 'request', 'result_text', 'filedata'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (string)$row[$k];
            }
        }
        return $row;
    }

    /** @param mixed $raw */
    private static function parseDt($raw): int
    {
        if (is_numeric($raw) && (int)$raw > 0) {
            return (int)$raw;
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return 0;
        }
        $ts = strtotime($s);
        return $ts !== false ? $ts : 0;
    }
}
