<?php
declare(strict_types=1);

/**
 * Gift / spa certificates on base_certificates.
 */
final class CertificatesRepository
{
    public const KIND_MONEY = 1;
    public const KIND_ABON = 2;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_certificates');
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'c.ID',
            'code' => 'c.CODE',
            'kind' => 'c.KIND',
            'dt_create' => 'c.DT_CREATE',
            'face_value' => 'c.FACE_VALUE',
            'used_amount' => 'c.USED_AMOUNT',
            'dt_use_expire' => 'c.DT_USE_EXPIRE',
        ];
    }

    public static function kindLabel(int $kind): string
    {
        if ($kind === self::KIND_ABON) {
            return t('certificates.kind.abon');
        }
        return t('certificates.kind.money');
    }

    /**
     * @param array{
     *   q?:string,id_buyer?:int,id_holder?:int,id_operator?:int,kind?:int,
     *   expired?:int|null,money_left?:int,id_event_create?:int,
     *   dt_from?:int,dt_to?:int,limit?:int,offset?:int,order?:string
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.CODE LIKE ? OR c.DESCR LIKE ? OR CAST(c.ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        foreach (['id_buyer' => 'ID_BUYER', 'id_holder' => 'ID_HOLDER', 'id_operator' => 'ID_OPERATOR', 'id_event_create' => 'ID_EVENT_CREATE'] as $opt => $col) {
            $v = (int)($opts[$opt] ?? 0);
            if ($v > 0) {
                $where[] = 'c.' . $col . ' = ?';
                $params[] = $v;
            }
        }
        $kind = (int)($opts['kind'] ?? 0);
        if ($kind === self::KIND_MONEY || $kind === self::KIND_ABON) {
            $where[] = 'c.KIND = ?';
            $params[] = $kind;
        }
        if (array_key_exists('expired', $opts) && $opts['expired'] !== null && $opts['expired'] !== '') {
            $ex = (int)$opts['expired'];
            if ($ex === 1) {
                $where[] = 'c.EXPIRED = 1';
            } elseif ($ex === 2) {
                $where[] = 'c.EXPIRED = 0';
            } elseif ($ex === 3) {
                $where[] = 'c.EXPIRED = 0 AND c.DT_USE_EXPIRE > 0 AND c.DT_USE_EXPIRE < ?';
                $params[] = time();
            }
        }
        if (!empty($opts['money_left'])) {
            $where[] = 'c.FACE_VALUE > c.USED_AMOUNT AND c.EXPIRED = 0';
        }
        $dtFrom = (int)($opts['dt_from'] ?? 0);
        $dtTo = (int)($opts['dt_to'] ?? 0);
        if ($dtFrom > 0) {
            $where[] = 'c.DT_CREATE >= ?';
            $params[] = $dtFrom;
        }
        if ($dtTo > 0) {
            $where[] = 'c.DT_CREATE <= ?';
            $params[] = $dtTo;
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_certificates c WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'c.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT c.ID AS id, c.CODE AS code, c.ID_OPERATOR AS id_operator,
                       c.ID_BUYER AS id_buyer, c.ID_HOLDER AS id_holder,
                       c.ID_EVENT_CREATE AS id_event_create, c.KIND AS kind,
                       c.SALE_AMOUNT AS sale_amount, c.FACE_VALUE AS face_value,
                       c.USED_AMOUNT AS used_amount, c.DT_CREATE AS dt_create,
                       c.DT_ACTIVATION_EXPIRE AS dt_activation_expire,
                       c.DT_USE_EXPIRE AS dt_use_expire, c.DT_UPDATE AS dt_update,
                       c.EXPIRED AS expired, c.PARTIAL_FORBIDDEN AS partial_forbidden,
                       c.BONUS_FORBIDDEN AS bonus_forbidden, c.USE_LIMIT_PCT AS use_limit_pct,
                       c.TYPE_IDS AS type_ids, c.PRODUCT_IDS AS product_ids,
                       c.CONTENT AS content, c.CONTENT_USED AS content_used, c.DESCR AS descr
                FROM base_certificates c
                WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeRow($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        return self::findRow($id, false);
    }

    /** Lock certificate row (current read). Must run inside a transaction. */
    public static function findForUpdate(int $id): ?array
    {
        return self::findRow($id, true);
    }

    private static function findRow(int $id, bool $forUpdate): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $sql = 'SELECT ID AS id, CODE AS code, ID_OPERATOR AS id_operator,
                    ID_BUYER AS id_buyer, ID_HOLDER AS id_holder,
                    ID_EVENT_CREATE AS id_event_create, KIND AS kind,
                    SALE_AMOUNT AS sale_amount, FACE_VALUE AS face_value,
                    USED_AMOUNT AS used_amount, DT_CREATE AS dt_create,
                    DT_ACTIVATION_EXPIRE AS dt_activation_expire,
                    DT_USE_EXPIRE AS dt_use_expire, DT_UPDATE AS dt_update,
                    EXPIRED AS expired, PARTIAL_FORBIDDEN AS partial_forbidden,
                    BONUS_FORBIDDEN AS bonus_forbidden, USE_LIMIT_PCT AS use_limit_pct,
                    TYPE_IDS AS type_ids, PRODUCT_IDS AS product_ids,
                    CONTENT AS content, CONTENT_USED AS content_used, DESCR AS descr
             FROM base_certificates WHERE ID = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    public static function findByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id FROM base_certificates WHERE CODE = ? LIMIT 1'
        );
        $st->execute([$code]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id) : null;
    }

    /** Active (not expired, balance left, holder match or unbound) for a client. */
    public static function listActiveForHolder(int $clientId, int $limit = 50): array
    {
        if ($clientId < 1 || !self::tableReady()) {
            return [];
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, CODE AS code, KIND AS kind, FACE_VALUE AS face_value,
                    USED_AMOUNT AS used_amount, DT_USE_EXPIRE AS dt_use_expire
             FROM base_certificates
             WHERE ID_HOLDER = ? AND EXPIRED = 0
               AND (DT_USE_EXPIRE = 0 OR DT_USE_EXPIRE >= ?)
               AND FACE_VALUE > USED_AMOUNT
             ORDER BY ID DESC
             LIMIT ' . max(1, $limit)
        );
        $st->execute([$clientId, $now]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'code' => (string)$row['code'],
                'kind' => (int)$row['kind'],
                'face_value' => (float)$row['face_value'],
                'used_amount' => (float)$row['used_amount'],
                'balance' => round((float)$row['face_value'] - (float)$row['used_amount'], 2),
                'dt_use_expire' => (int)$row['dt_use_expire'],
            ];
        }
        return $out;
    }

    public static function countForClient(int $clientId): array
    {
        if ($clientId < 1 || !self::tableReady()) {
            return ['bought' => 0, 'active' => 0];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM base_certificates WHERE ID_BUYER = ?');
        $st->execute([$clientId]);
        $bought = (int)$st->fetchColumn();
        $st2 = $pdo->prepare(
            'SELECT COUNT(*) FROM base_certificates
             WHERE ID_HOLDER = ? AND EXPIRED = 0
               AND (DT_USE_EXPIRE = 0 OR DT_USE_EXPIRE >= ?)
               AND FACE_VALUE > USED_AMOUNT'
        );
        $st2->execute([$clientId, time()]);
        return ['bought' => $bought, 'active' => (int)$st2->fetchColumn()];
    }

    public static function generateCode(int $len = 12): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function ensureUniqueCode(?string $preferred = null): string
    {
        $code = trim((string)$preferred);
        if ($code !== '' && self::findByCode($code) === null) {
            return $code;
        }
        for ($i = 0; $i < 20; $i++) {
            $try = self::generateCode();
            if (self::findByCode($try) === null) {
                return $try;
            }
        }
        return self::generateCode(16) . (string)time();
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, bool $isNew): array
    {
        $kind = (int)($post['kind'] ?? self::KIND_MONEY);
        if ($kind !== self::KIND_ABON) {
            $kind = self::KIND_MONEY;
        }
        $now = time();
        $dtCreate = self::parseLocalTs((string)($post['dt_create'] ?? ''));
        if ($dtCreate < 1) {
            $dtCreate = $now;
        }
        $dtAct = self::parseLocalTs((string)($post['dt_activation_expire'] ?? ''));
        if ($dtAct < 1) {
            $dtAct = $dtCreate + 86400 * 180;
        }
        $dtUse = self::parseLocalTs((string)($post['dt_use_expire'] ?? ''));
        if ($dtUse < 1) {
            $dtUse = $dtCreate + 86400 * 360;
        }

        $sale = max(0.0, (float)($post['sale_amount'] ?? 0));
        $face = max(0.0, (float)($post['face_value'] ?? 0));
        if ($kind === self::KIND_MONEY && $face <= 0 && $sale > 0) {
            $face = $sale;
        }

        $typeIds = self::parseIdList($post['type_ids'] ?? null);
        $productIds = self::parseIdList($post['product_ids'] ?? null);
        $content = self::parseContent($post['content'] ?? null, $post);
        if ($kind === self::KIND_ABON && $content !== [] && $face <= 0) {
            $face = 0.0;
            foreach ($content as $line) {
                $face += (float)($line['qty'] ?? 0) * (float)($line['price'] ?? 0);
            }
            $face = round($face, 2);
            if ($sale <= 0) {
                $sale = $face;
            }
        }

        $code = $isNew
            ? self::ensureUniqueCode((string)($post['code'] ?? ''))
            : trim((string)($post['code'] ?? ''));
        if ($code === '') {
            throw new InvalidArgumentException(t('certificates.err.code_required'));
        }

        return [
            'code' => mb_substr($code, 0, 64),
            'id_operator' => max(0, (int)($post['id_operator'] ?? 0)),
            'id_buyer' => max(0, (int)($post['id_buyer'] ?? 0)),
            'id_holder' => max(0, (int)($post['id_holder'] ?? 0)),
            'id_event_create' => max(0, (int)($post['id_event_create'] ?? 0)),
            'kind' => $kind,
            'sale_amount' => $sale,
            'face_value' => $face,
            'dt_create' => $dtCreate,
            'dt_activation_expire' => $dtAct,
            'dt_use_expire' => $dtUse,
            'expired' => !empty($post['expired']) ? 1 : 0,
            'partial_forbidden' => !empty($post['partial_forbidden']) ? 1 : 0,
            'bonus_forbidden' => !empty($post['bonus_forbidden']) ? 1 : 0,
            'use_limit_pct' => max(0, min(100, (int)($post['use_limit_pct'] ?? 0))),
            'type_ids' => $typeIds,
            'product_ids' => $productIds,
            'content' => $kind === self::KIND_ABON ? $content : [],
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 65000),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('certificates.err.table'));
        }
        if (self::findByCode((string)$data['code']) !== null) {
            throw new InvalidArgumentException(t('certificates.err.code_taken'));
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_certificates
             (CODE, ID_OPERATOR, ID_BUYER, ID_HOLDER, ID_EVENT_CREATE, KIND,
              SALE_AMOUNT, FACE_VALUE, USED_AMOUNT, DT_CREATE, DT_ACTIVATION_EXPIRE,
              DT_USE_EXPIRE, DT_UPDATE, EXPIRED, PARTIAL_FORBIDDEN, BONUS_FORBIDDEN,
              USE_LIMIT_PCT, TYPE_IDS, PRODUCT_IDS, CONTENT, CONTENT_USED, DESCR)
             VALUES (?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,NULL,?)'
        );
        $st->execute([
            (string)$data['code'],
            (int)$data['id_operator'],
            (int)$data['id_buyer'],
            (int)$data['id_holder'],
            (int)$data['id_event_create'],
            (int)$data['kind'],
            (float)$data['sale_amount'],
            (float)$data['face_value'],
            (int)$data['dt_create'],
            (int)$data['dt_activation_expire'],
            (int)$data['dt_use_expire'],
            $now,
            (int)$data['expired'],
            (int)$data['partial_forbidden'],
            (int)$data['bonus_forbidden'],
            (int)$data['use_limit_pct'],
            self::encodeJson($data['type_ids'] ?? []),
            self::encodeJson($data['product_ids'] ?? []),
            self::encodeJson($data['content'] ?? []),
            (string)($data['descr'] ?? ''),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $existing = self::findByCode((string)$data['code']);
        if ($existing !== null && (int)$existing['id'] !== $id) {
            throw new InvalidArgumentException(t('certificates.err.code_taken'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_certificates SET
             CODE=?, ID_OPERATOR=?, ID_BUYER=?, ID_HOLDER=?, ID_EVENT_CREATE=?, KIND=?,
             SALE_AMOUNT=?, FACE_VALUE=?, DT_CREATE=?, DT_ACTIVATION_EXPIRE=?,
             DT_USE_EXPIRE=?, DT_UPDATE=?, EXPIRED=?, PARTIAL_FORBIDDEN=?, BONUS_FORBIDDEN=?,
             USE_LIMIT_PCT=?, TYPE_IDS=?, PRODUCT_IDS=?, CONTENT=?, DESCR=?
             WHERE ID=?'
        );
        $st->execute([
            (string)$data['code'],
            (int)$data['id_operator'],
            (int)$data['id_buyer'],
            (int)$data['id_holder'],
            (int)$data['id_event_create'],
            (int)$data['kind'],
            (float)$data['sale_amount'],
            (float)$data['face_value'],
            (int)$data['dt_create'],
            (int)$data['dt_activation_expire'],
            (int)$data['dt_use_expire'],
            time(),
            (int)$data['expired'],
            (int)$data['partial_forbidden'],
            (int)$data['bonus_forbidden'],
            (int)$data['use_limit_pct'],
            self::encodeJson($data['type_ids'] ?? []),
            self::encodeJson($data['product_ids'] ?? []),
            self::encodeJson($data['content'] ?? []),
            (string)($data['descr'] ?? ''),
            $id,
        ]);
    }

    /**
     * @param list<array{ts:int,admin_id:int,goods:array<int,int>,ledger_id?:int}> $contentUsed
     */
    public static function updateUsage(int $id, float $usedAmount, int $expired, array $contentUsed, string $descrAppend = ''): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $descrSql = '';
        $params = [$usedAmount, $expired, self::encodeJson($contentUsed), time()];
        if ($descrAppend !== '') {
            $descrSql = ', DESCR = CONCAT(IFNULL(DESCR,\'\'), ?)';
            $params[] = "\n" . $descrAppend;
        }
        $params[] = $id;
        $st = Database::pdo()->prepare(
            'UPDATE base_certificates SET USED_AMOUNT=?, EXPIRED=?, CONTENT_USED=?, DT_UPDATE=?'
            . $descrSql . ' WHERE ID=?'
        );
        $st->execute($params);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        if (!self::tableReady() || $ids === []) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if (CertificateLedgerRepository::tableReady()) {
            $stL = Database::pdo()->prepare("DELETE FROM base_certificate_ledger WHERE ID_CERT IN ($ph)");
            $stL->execute($ids);
        }
        $st = Database::pdo()->prepare("DELETE FROM base_certificates WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @return list<int> */
    public static function dueForExpiry(int $limit = 100): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_certificates
             WHERE EXPIRED = 0 AND DT_USE_EXPIRE > 0 AND DT_USE_EXPIRE < ?
             ORDER BY DT_USE_EXPIRE ASC
             LIMIT ' . max(1, $limit)
        );
        $st->execute([time()]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public static function parseLocalTs(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        $ts = strtotime($raw);
        return $ts !== false ? (int)$ts : 0;
    }

    public static function tsToLocal(int $ts): string
    {
        return $ts > 0 ? date('Y-m-d\TH:i', $ts) : '';
    }

    /** @param mixed $raw @return list<int> */
    public static function parseIdList($raw): array
    {
        if (is_array($raw)) {
            $ids = array_map('intval', $raw);
        } else {
            $s = trim((string)$raw);
            if ($s === '') {
                return [];
            }
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                $ids = array_map('intval', $decoded);
            } else {
                $ids = array_map('intval', preg_split('/[\s,;]+/', $s) ?: []);
            }
        }
        return array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
    }

    /**
     * @param mixed $raw
     * @param array<string,mixed> $post
     * @return list<array{product_id:int,qty:int,price:float,name:string}>
     */
    public static function parseContent($raw, array $post = []): array
    {
        if (isset($post['content_product_id']) && is_array($post['content_product_id'])) {
            $out = [];
            $pids = $post['content_product_id'];
            $qtys = is_array($post['content_qty'] ?? null) ? $post['content_qty'] : [];
            $prices = is_array($post['content_price'] ?? null) ? $post['content_price'] : [];
            $names = is_array($post['content_name'] ?? null) ? $post['content_name'] : [];
            foreach ($pids as $i => $pid) {
                $pid = (int)$pid;
                $qty = (int)($qtys[$i] ?? 0);
                if ($pid < 1 || $qty < 1) {
                    continue;
                }
                $name = trim((string)($names[$i] ?? ''));
                if ($name === '' && class_exists('ProductsRepository', false)) {
                    $p = ProductsRepository::find($pid);
                    $name = $p ? (string)($p['name'] ?? '') : '';
                }
                $price = (float)($prices[$i] ?? 0);
                if ($price <= 0 && class_exists('ProductsRepository', false)) {
                    $p = $p ?? ProductsRepository::find($pid);
                    if ($p) {
                        $price = (float)($p['price'] ?? 0);
                    }
                }
                $out[] = [
                    'product_id' => $pid,
                    'qty' => $qty,
                    'price' => round($price, 2),
                    'name' => $name !== '' ? $name : ('#' . $pid),
                ];
            }
            return $out;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $pid = (int)($line['product_id'] ?? $line['id'] ?? 0);
            $qty = (int)($line['qty'] ?? 0);
            if ($pid < 1 || $qty < 1) {
                continue;
            }
            $out[] = [
                'product_id' => $pid,
                'qty' => $qty,
                'price' => round((float)($line['price'] ?? 0), 2),
                'name' => (string)($line['name'] ?? ('#' . $pid)),
            ];
        }
        return $out;
    }

    /** @param mixed $v */
    public static function encodeJson($v): ?string
    {
        if ($v === null || $v === [] || $v === '') {
            return null;
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<int> */
    public static function decodeIdList($raw): array
    {
        if (is_array($raw)) {
            return self::parseIdList($raw);
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return [];
        }
        $decoded = json_decode($s, true);
        return is_array($decoded) ? self::parseIdList($decoded) : [];
    }

    /**
     * @return list<array{product_id:int,qty:int,price:float,name:string}>
     */
    public static function decodeContent($raw): array
    {
        if (is_array($raw)) {
            return self::parseContent($raw);
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return [];
        }
        $decoded = json_decode($s, true);
        return is_array($decoded) ? self::parseContent($decoded) : [];
    }

    /**
     * @return list<array{ts:int,admin_id:int,goods:array<int,int>}>
     */
    public static function decodeContentUsed($raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $s = trim((string)$raw);
            if ($s === '') {
                return [];
            }
            $decoded = json_decode($s, true);
            if (!is_array($decoded)) {
                return [];
            }
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $goods = [];
            foreach ((array)($entry['goods'] ?? []) as $gid => $qty) {
                $gid = (int)$gid;
                $qty = (int)$qty;
                if ($gid > 0 && $qty > 0) {
                    $goods[$gid] = $qty;
                }
            }
            $item = [
                'ts' => (int)($entry['ts'] ?? 0),
                'admin_id' => (int)($entry['admin_id'] ?? 0),
                'goods' => $goods,
            ];
            $ledgerId = (int)($entry['ledger_id'] ?? 0);
            if ($ledgerId > 0) {
                $item['ledger_id'] = $ledgerId;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['code'] = (string)($row['code'] ?? '');
        $row['id_operator'] = (int)($row['id_operator'] ?? 0);
        $row['id_buyer'] = (int)($row['id_buyer'] ?? 0);
        $row['id_holder'] = (int)($row['id_holder'] ?? 0);
        $row['id_event_create'] = (int)($row['id_event_create'] ?? 0);
        $row['kind'] = (int)($row['kind'] ?? self::KIND_MONEY);
        $row['sale_amount'] = (float)($row['sale_amount'] ?? 0);
        $row['face_value'] = (float)($row['face_value'] ?? 0);
        $row['used_amount'] = (float)($row['used_amount'] ?? 0);
        $row['balance'] = round($row['face_value'] - $row['used_amount'], 2);
        $row['dt_create'] = (int)($row['dt_create'] ?? 0);
        $row['dt_activation_expire'] = (int)($row['dt_activation_expire'] ?? 0);
        $row['dt_use_expire'] = (int)($row['dt_use_expire'] ?? 0);
        $row['dt_update'] = (int)($row['dt_update'] ?? 0);
        $row['expired'] = (int)($row['expired'] ?? 0);
        $row['partial_forbidden'] = (int)($row['partial_forbidden'] ?? 0);
        $row['bonus_forbidden'] = (int)($row['bonus_forbidden'] ?? 0);
        $row['use_limit_pct'] = (int)($row['use_limit_pct'] ?? 0);
        $row['type_ids'] = self::decodeIdList($row['type_ids'] ?? null);
        $row['product_ids'] = self::decodeIdList($row['product_ids'] ?? null);
        $row['content'] = self::decodeContent($row['content'] ?? null);
        $row['content_used'] = self::decodeContentUsed($row['content_used'] ?? null);
        $row['descr'] = (string)($row['descr'] ?? '');
        return $row;
    }
}
