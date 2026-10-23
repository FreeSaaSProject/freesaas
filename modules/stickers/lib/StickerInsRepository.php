<?php
declare(strict_types=1);

final class StickerInsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt_create' => 'DT_CREATE',
            'id_good' => 'ID_GOOD',
            'id_act' => 'ID_ACT',
            'qtyu_out' => 'QTYU_OUT',
        ];
    }

    /**
     * @param array{
     *   q?:string,good_q?:string,id_act?:int|null,id_good?:int|null,id_stock?:int|null,id_supplier?:int|null,
     *   year?:string|int,month?:string|int,non_act?:int,revi?:int,qqr?:int,weightable?:int,is_used?:int,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{
     *   items:list<array<string,mixed>>,
     *   total:int,
     *   sums:array{price:float,qty:float,summ:float,qtyu_in:float,qtyu_out:float,qtyu_left:float,unit_price_sum:float,summ_left:float}
     * }
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_good_sticker_ins')) {
            return ['items' => [], 'total' => 0, 'sums' => self::emptySums()];
        }
        $pdo = Database::pdo();
        [$sqlWhere, $params] = self::listWhere($opts);
        $sumsPack = self::aggregateSums($sqlWhere, $params);
        $total = (int)$sumsPack['total'];
        $sums = $sumsPack['sums'];

        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT ID AS id, ID_USER_CREATE AS id_user_create, ID_ACT AS id_act,
                       ID_GOOD AS id_good, ID_STOCK AS id_stock, ID_SUPPLIER AS id_supplier,
                       DT_CREATE AS dt_create, DT_EXPIRE AS dt_expire, DT_PRINT AS dt_print,
                       DT_REVISION AS dt_revision, DT_MOVEMENT AS dt_movement, DT_REPRINT AS dt_reprint,
                       PIN AS pin, HASH AS hash, LOG AS log, PRICE AS price, QTY AS qty,
                       QTYU_IN AS qtyu_in, QTYU_OUT AS qtyu_out, QTY_QTYU_RATE AS qty_qtyu_rate,
                       DESCR AS descr, DESCR_STOCK AS descr_stock, WEIGHT_START AS weight_start
                FROM base_good_sticker_ins WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total, 'sums' => $sums];
    }

    /**
     * Filter-wide totals (same WHERE as list).
     *
     * @param array<string,mixed> $opts
     * @return array{total:int,sums:array{price:float,qty:float,summ:float,qtyu_in:float,qtyu_out:float,qtyu_left:float,unit_price_sum:float,summ_left:float}}
     */
    public static function listSums(array $opts = []): array
    {
        if (!Database::tableExists('base_good_sticker_ins')) {
            return ['total' => 0, 'sums' => self::emptySums()];
        }
        [$sqlWhere, $params] = self::listWhere($opts);
        return self::aggregateSums($sqlWhere, $params);
    }

    /**
     * @param list<mixed> $params
     * @return array{total:int,sums:array{price:float,qty:float,summ:float,qtyu_in:float,qtyu_out:float,qtyu_left:float,unit_price_sum:float,summ_left:float}}
     */
    private static function aggregateSums(string $sqlWhere, array $params): array
    {
        $stc = Database::pdo()->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(PRICE), 0) AS price,
                    COALESCE(SUM(QTY), 0) AS qty,
                    COALESCE(SUM(PRICE * QTY), 0) AS summ,
                    COALESCE(SUM(QTYU_IN), 0) AS qtyu_in,
                    COALESCE(SUM(QTYU_OUT), 0) AS qtyu_out,
                    COALESCE(SUM(QTYU_IN - QTYU_OUT), 0) AS qtyu_left,
                    COALESCE(SUM(CASE WHEN QTY_QTYU_RATE > 0 THEN PRICE / QTY_QTYU_RATE ELSE 0 END), 0) AS unit_price_sum,
                    COALESCE(SUM(CASE WHEN QTY_QTYU_RATE > 0
                      THEN (PRICE / QTY_QTYU_RATE) * (QTYU_IN - QTYU_OUT) ELSE 0 END), 0) AS summ_left
             FROM base_good_sticker_ins WHERE $sqlWhere"
        );
        $stc->execute($params);
        $agg = $stc->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($agg['cnt'] ?? 0),
            'sums' => [
                'price' => round((float)($agg['price'] ?? 0), 2),
                'qty' => round((float)($agg['qty'] ?? 0), 3),
                'summ' => round((float)($agg['summ'] ?? 0), 2),
                'qtyu_in' => round((float)($agg['qtyu_in'] ?? 0), 3),
                'qtyu_out' => round((float)($agg['qtyu_out'] ?? 0), 3),
                'qtyu_left' => round((float)($agg['qtyu_left'] ?? 0), 3),
                'unit_price_sum' => round((float)($agg['unit_price_sum'] ?? 0), 2),
                'summ_left' => round((float)($agg['summ_left'] ?? 0), 2),
            ],
        ];
    }

    /** @return array{price:float,qty:float,summ:float,qtyu_in:float,qtyu_out:float,qtyu_left:float,unit_price_sum:float,summ_left:float} */
    public static function emptySums(): array
    {
        return [
            'price' => 0.0,
            'qty' => 0.0,
            'summ' => 0.0,
            'qtyu_in' => 0.0,
            'qtyu_out' => 0.0,
            'qtyu_left' => 0.0,
            'unit_price_sum' => 0.0,
            'summ_left' => 0.0,
        ];
    }

    /**
     * Oper-parity filters → SQL WHERE + bound params.
     *
     * @param array<string,mixed> $opts
     * @return array{0:string,1:list<mixed>}
     */
    public static function listWhere(array $opts): array
    {
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(PIN LIKE ? OR HASH LIKE ? OR DESCR LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        foreach ([
            'id_act' => 'ID_ACT',
            'id_good' => 'ID_GOOD',
            'id_stock' => 'ID_STOCK',
            'id_supplier' => 'ID_SUPPLIER',
        ] as $key => $col) {
            if (!empty($opts[$key])) {
                $where[] = $col . ' = ?';
                $params[] = (int)$opts[$key];
            }
        }
        $goodQ = trim((string)($opts['good_q'] ?? ''));
        if ($goodQ !== '' && empty($opts['id_good']) && Database::tableExists('base_products')) {
            // Name match (materials preferred via resolveGoodQuery → id_good when unique).
            $where[] = 'ID_GOOD IN (SELECT id FROM base_products WHERE name LIKE ?)';
            $params[] = '%' . $goodQ . '%';
        }
        $year = (string)($opts['year'] ?? '');
        $month = (string)($opts['month'] ?? '');
        if ($year !== '' && $year !== '0' && $year !== 'all' && ctype_digit($year)) {
            $y = (int)$year;
            if ($month !== '' && $month !== '0' && $month !== 'all' && ctype_digit($month)) {
                $m = max(1, min(12, (int)$month));
                $tstart = mktime(0, 0, 0, $m, 1, $y);
                $tend = mktime(0, 0, 0, $m + 1, 1, $y);
            } else {
                $tstart = mktime(0, 0, 0, 1, 1, $y);
                $tend = mktime(0, 0, 0, 1, 1, $y + 1);
            }
            if ($tstart !== false && $tend !== false) {
                $where[] = '(DT_CREATE >= ? AND DT_CREATE < ?)';
                $params[] = (int)$tstart;
                $params[] = (int)$tend;
            }
        }
        // Tri-states: 0=all, 1=first option, 2=second (oper parity).
        $nonAct = (int)($opts['non_act'] ?? 0);
        if ($nonAct === 1) {
            $where[] = '(DT_PRINT = 0 OR DT_REPRINT > 0)';
        } elseif ($nonAct === 2) {
            $where[] = '(DT_PRINT > 0)';
        }
        $revi = (int)($opts['revi'] ?? 0);
        if ($revi === 1) {
            $where[] = '(DT_REVISION > 0)';
        } elseif ($revi === 2) {
            $where[] = '(DT_REVISION = 0 OR DT_REVISION IS NULL)';
        }
        $qqr = (int)($opts['qqr'] ?? 0);
        if ($qqr === 1) {
            $where[] = '(QTY_QTYU_RATE = 1)';
        } elseif ($qqr === 2) {
            $where[] = '(QTY_QTYU_RATE > 1)';
        }
        $weightable = (int)($opts['weightable'] ?? 0);
        if ($weightable === 1) {
            $where[] = '(WEIGHT_START = 0 OR WEIGHT_START IS NULL)';
        } elseif ($weightable === 2) {
            $where[] = '(WEIGHT_START > 0)';
        }
        $isUsed = (int)($opts['is_used'] ?? 0);
        if ($isUsed === 1) {
            $where[] = '(QTYU_IN > QTYU_OUT)';
        } elseif ($isUsed === 2) {
            $where[] = '(QTYU_IN <= QTYU_OUT)';
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return list<int> years present in sticker create dates (desc) */
    public static function listYears(): array
    {
        if (!Database::tableExists('base_good_sticker_ins')) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT DISTINCT YEAR(FROM_UNIXTIME(DT_CREATE)) AS y
             FROM base_good_sticker_ins
             WHERE DT_CREATE > 0
             ORDER BY y DESC'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out = [];
        foreach ($rows as $y) {
            $y = (int)$y;
            if ($y >= 2000 && $y <= 2100) {
                $out[] = $y;
            }
        }
        return $out;
    }

    /**
     * Oper GOODSEARCH parity: resolve product name → id (exact, then prefix, then contains).
     * Prefers is_material=1 when several match.
     *
     * @return array{id:int,name:string}|null
     */
    public static function resolveGoodQuery(string $q): ?array
    {
        $q = trim($q);
        if ($q === '' || !Database::tableExists('base_products')) {
            return null;
        }
        $pdo = Database::pdo();
        if (ctype_digit($q)) {
            $st = $pdo->prepare('SELECT id, name FROM base_products WHERE id = ? LIMIT 1');
            $st->execute([(int)$q]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? ['id' => (int)$row['id'], 'name' => (string)$row['name']] : null;
        }
        $tries = [
            ['SELECT id, name FROM base_products WHERE name = ? ORDER BY is_material DESC, id ASC LIMIT 1', [$q]],
            ['SELECT id, name FROM base_products WHERE name LIKE ? ORDER BY is_material DESC, id ASC LIMIT 2', [$q . '%']],
            ['SELECT id, name FROM base_products WHERE name LIKE ? ORDER BY is_material DESC, id ASC LIMIT 2', ['%' . $q . '%']],
        ];
        foreach ($tries as [$sql, $params]) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) === 1) {
                return ['id' => (int)$rows[0]['id'], 'name' => (string)$rows[0]['name']];
            }
            if (count($rows) > 1 && strpos($sql, 'name = ?') !== false) {
                return ['id' => (int)$rows[0]['id'], 'name' => (string)$rows[0]['name']];
            }
            // Ambiguous prefix/contains — keep LIKE filter, do not pin id_good.
            if (count($rows) > 1) {
                return null;
            }
        }
        return null;
    }

    /**
     * Collapse twin stickers for an act (oper iframe): one row per ID_GOOD with batch QTY.
     *
     * @return list<array<string,mixed>>
     */
    public static function listGroupedForAct(int $actId): array
    {
        if ($actId < 1 || !Database::tableExists('base_good_sticker_ins')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT MIN(ID) AS id, ID_GOOD AS id_good, ID_STOCK AS id_stock, ID_SUPPLIER AS id_supplier,
                    ID_ACT AS id_act, MIN(DT_CREATE) AS dt_create, MAX(DT_EXPIRE) AS dt_expire,
                    MAX(DT_PRINT) AS dt_print, MAX(QTY) AS qty, AVG(PRICE) AS price,
                    AVG(QTY_QTYU_RATE) AS qty_qtyu_rate,
                    SUM(QTYU_IN) AS qtyu_in, SUM(QTYU_OUT) AS qtyu_out,
                    COUNT(*) AS twin_count,
                    MAX(DESCR) AS descr, MAX(DESCR_STOCK) AS descr_stock,
                    MAX(WEIGHT_START) AS weight_start,
                    GROUP_CONCAT(PIN ORDER BY ID SEPARATOR ",") AS pins
             FROM base_good_sticker_ins
             WHERE ID_ACT = ?
             GROUP BY ID_GOOD, ID_STOCK, ID_SUPPLIER, ID_ACT, PRICE, QTY_QTYU_RATE
             ORDER BY MIN(ID) ASC'
        );
        $st->execute([$actId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Staff acronym for protocol tags (oper ShortName). */
    public static function userAcronym(int $userId): string
    {
        if ($userId < 1) {
            return '?';
        }
        if (class_exists('UsersRepository', false)) {
            $short = UsersRepository::acronymsList([$userId]);
            if ($short !== '') {
                return $short;
            }
        }
        return '#' . $userId;
    }

    /** Append sticker LOG line: `[userId][dd.mm.YYYY HH:ii] msg` (UI paints acronym badge). */
    public static function appendLog(string $log, int $userId, string $msg): string
    {
        return $log . '<BR>[' . $userId . '][' . date('d.m.Y H:i') . '] ' . $msg;
    }

    /**
     * Plain protocol text: BR→newlines, numeric `[id][date]` → acronym.
     * No truncation.
     */
    public static function formatLogText(string $log): string
    {
        $text = self::normalizeLogText($log);
        if ($text === '') {
            return '';
        }
        $cache = [];
        $out = preg_replace_callback(
            '/\[(\d+)\](?=\[\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}\])/',
            static function (array $m) use (&$cache): string {
                $id = (int)$m[1];
                if (!isset($cache[$id])) {
                    $cache[$id] = self::userAcronym($id);
                }
                return '[' . $cache[$id] . ']';
            },
            $text
        );
        return is_string($out) ? $out : $text;
    }

    /**
     * Protocol HTML with colored user badges (same as panel rail / news).
     * Safe HTML: badges from UsersRepository + escaped message text.
     */
    public static function formatLogHtml(string $log): string
    {
        $text = self::normalizeLogText($log);
        if ($text === '') {
            return '';
        }
        $lines = preg_split("/\n+/", $text) ?: [];
        $parts = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match(
                '/^\[(\d+)\](\[\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}\])\s*(.*)$/u',
                $line,
                $m
            )) {
                $badge = self::userBadgeHtml((int)$m[1]);
                $rest = $m[2] . ($m[3] !== '' ? ' ' . $m[3] : '');
                $parts[] = $badge . ' ' . e($rest);
                continue;
            }
            if (preg_match(
                '/^\[([^\]]+)\](\[\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}\])\s*(.*)$/u',
                $line,
                $m
            )) {
                $uid = self::userIdByAcronym($m[1]);
                $badge = $uid > 0
                    ? self::userBadgeHtml($uid)
                    : '<span class="user-badge">' . e('[' . $m[1] . ']') . '</span>';
                $rest = $m[2] . ($m[3] !== '' ? ' ' . $m[3] : '');
                $parts[] = $badge . ' ' . e($rest);
                continue;
            }
            $parts[] = e($line);
        }
        return implode('<br>', $parts);
    }

    private static function normalizeLogText(string $log): string
    {
        return trim(strip_tags(str_replace(['<BR>', '<br>', '<br/>', '<br />'], "\n", $log)));
    }

    private static function userBadgeHtml(int $userId): string
    {
        if (class_exists('UsersRepository', false)) {
            return UsersRepository::badgeHtml($userId, false, true);
        }
        return '<span class="user-badge">[' . e(self::userAcronym($userId)) . ']</span>';
    }

    /** Resolve acronym → user id (for logs that already stored ShortName). */
    private static function userIdByAcronym(string $acr): int
    {
        $acr = trim($acr);
        if ($acr === '' || $acr === '?' || !class_exists('UsersRepository', false)) {
            return 0;
        }
        if (isset($acr[0]) && $acr[0] === '#') {
            $id = (int)substr($acr, 1);
            return $id > 0 ? $id : 0;
        }
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (UsersRepository::listEnabledStaff() as $u) {
                $id = (int)($u['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $name = trim((string)($u['name'] ?? ''));
                if ($name === '') {
                    $name = (string)($u['login'] ?? '');
                }
                $short = UsersRepository::shortName($name);
                if ($short !== '' && !isset($map[$short])) {
                    $map[$short] = $id;
                }
            }
        }
        return (int)($map[$acr] ?? 0);
    }

    /** @deprecated use formatLogText / formatLogHtml */
    public static function lastLogLine(string $log): string
    {
        return self::formatLogText($log);
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_good_sticker_ins')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER_CREATE AS id_user_create, ID_ACT AS id_act,
                    ID_GOOD AS id_good, ID_STOCK AS id_stock, ID_SUPPLIER AS id_supplier,
                    DT_CREATE AS dt_create, DT_EXPIRE AS dt_expire, DT_REVISION AS dt_revision,
                    DT_MOVEMENT AS dt_movement, DT_PRINT AS dt_print, DT_REPRINT AS dt_reprint,
                    PIN AS pin, HASH AS hash, LOG AS log, PRICE AS price, QTY AS qty,
                    QTYU_IN AS qtyu_in, QTYU_OUT AS qtyu_out, QTY_QTYU_RATE AS qty_qtyu_rate,
                    DESCR AS descr, DESCR_STOCK AS descr_stock, IS_STRANGE AS is_strange,
                    WEIGHT_START AS weight_start, TOKEN AS token
             FROM base_good_sticker_ins WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function remaining(array $row): float
    {
        return round((float)$row['qtyu_in'] - (float)$row['qtyu_out'], 3);
    }

    public static function findByHash(string $hash): ?array
    {
        $hash = strtolower(trim($hash));
        if ($hash === '' || !preg_match('/^[a-f0-9]{32}$/', $hash)
            || !Database::tableExists('base_good_sticker_ins')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_good_sticker_ins WHERE HASH = ? ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$hash]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id) : null;
    }

    /**
     * Stickers pending print: never printed or reprint flag, with remaining qty.
     * Oper ordered by product then create time; default page size 65 (5×13 A4).
     * Pass 0 for all pending (capped at StickerPrint::MAX_BATCH).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForPrint(int $limit = 65): array
    {
        if (!Database::tableExists('base_good_sticker_ins')) {
            return [];
        }
        $max = class_exists('StickerPrint', false) ? StickerPrint::MAX_BATCH : 2000;
        if ($limit <= 0) {
            $limit = $max;
        } else {
            $limit = max(1, min($max, $limit));
        }
        $st = Database::pdo()->query(
            'SELECT ID AS id, ID_GOOD AS id_good, ID_STOCK AS id_stock,
                    DT_CREATE AS dt_create, PIN AS pin, HASH AS hash,
                    QTYU_IN AS qtyu_in, QTYU_OUT AS qtyu_out, DT_PRINT AS dt_print,
                    DT_REPRINT AS dt_reprint
             FROM base_good_sticker_ins
             WHERE (DT_PRINT = 0 OR DT_REPRINT > 0) AND QTYU_IN > QTYU_OUT
             ORDER BY ID_GOOD ASC, DT_CREATE ASC, ID ASC
             LIMIT ' . (int)$limit
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Printable rows (remaining qty) for explicitly selected ids, activated or not.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function listForPrintByIds(array $ids): array
    {
        if (!Database::tableExists('base_good_sticker_ins') || $ids === []) {
            return [];
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $clean = array_values($clean);
        if ($clean === []) {
            return [];
        }
        $max = class_exists('StickerPrint', false) ? StickerPrint::MAX_BATCH : 2000;
        if (count($clean) > $max) {
            $clean = array_slice($clean, 0, $max);
        }
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_GOOD AS id_good, ID_STOCK AS id_stock,
                    DT_CREATE AS dt_create, PIN AS pin, HASH AS hash,
                    QTYU_IN AS qtyu_in, QTYU_OUT AS qtyu_out, DT_PRINT AS dt_print,
                    DT_REPRINT AS dt_reprint
             FROM base_good_sticker_ins
             WHERE ID IN ($placeholders)
               AND QTYU_IN > QTYU_OUT
             ORDER BY ID_GOOD ASC, DT_CREATE ASC, ID ASC"
        );
        $st->execute($clean);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Resolve sticker from bot input: numeric id, "id pin", pin, or hash.
     */
    public static function findByBotToken(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || !Database::tableExists('base_good_sticker_ins')) {
            return null;
        }
        if (preg_match('/^(\d+)\s+(\S+)$/', $raw, $m)) {
            $row = self::find((int)$m[1]);
            if ($row === null) {
                return null;
            }
            $pin = trim((string)$m[2]);
            if ($pin !== '' && (string)($row['pin'] ?? '') !== $pin && (string)($row['hash'] ?? '') !== $pin) {
                return null;
            }
            return $row;
        }
        if (ctype_digit($raw)) {
            $byId = self::find((int)$raw);
            if ($byId !== null) {
                return $byId;
            }
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_good_sticker_ins WHERE PIN = ? OR HASH = ? ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$raw, $raw]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id) : null;
    }

    public static function randPin(int $len = 4): string
    {
        $digits = '';
        for ($i = 0; $i < $len; $i++) {
            $digits .= (string)random_int(0, 9);
        }
        return $digits;
    }

    public static function makeHash(string $pin, int $salt = 0): string
    {
        return md5($pin . ($salt > 0 ? (string)$salt : (string)time()));
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $idAct = (int)($post['id_act'] ?? 0);
        $idGood = (int)($post['id_good'] ?? 0);
        $goodQ = trim((string)($post['good_q'] ?? ''));
        if ($idGood < 1 && $goodQ !== '') {
            $hit = self::resolveGoodQuery($goodQ);
            $idGood = $hit !== null ? (int)$hit['id'] : 0;
        }
        if ($idAct < 1) {
            throw new InvalidArgumentException(t('stickers.err.act_required'));
        }
        if ($idGood < 1) {
            throw new InvalidArgumentException(t('stickers.err.good_required'));
        }
        $act = Acts2InRepository::find($idAct);
        if (!$act) {
            throw new InvalidArgumentException(t('stickers.err.act_not_found', ['id' => $idAct]));
        }
        if (!empty($act['is_signed']) && !Auth::capability('SUPERADMIN_ACCESS')) {
            throw new InvalidArgumentException(t('stickers.err.act_signed_locked'));
        }
        $qty = max(1, (int)($post['qty'] ?? 1));
        $rate = (float)str_replace(',', '.', (string)($post['qty_qtyu_rate'] ?? '1'));
        if ($rate <= 0) {
            throw new InvalidArgumentException(t('stickers.err.rate_required'));
        }
        $price = (float)str_replace(',', '.', (string)($post['price'] ?? '0'));
        return [
            'id_act' => $idAct,
            'id_good' => $idGood,
            'id_stock' => (int)$act['id_stock'],
            'id_supplier' => (int)$act['id_supplier'],
            'qty' => $qty,
            'qty_qtyu_rate' => $rate,
            'qtyu_in' => $rate,
            'price' => $price,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'descr_stock' => mb_substr(trim((string)($post['descr_stock'] ?? '')), 0, 255),
            'dt_expire' => max(0, (int)($post['dt_expire'] ?? 0)),
            'weight_start' => (float)str_replace(',', '.', (string)($post['weight_start'] ?? '0')),
            'activate' => !empty($post['activate']) && StickerActivation::manualAllowed(),
        ];
    }

    /**
     * Create twin stickers for QTY units (oper parity).
     *
     * @param array<string,mixed> $data
     * @return list<int> created ids
     */
    public static function createBatch(array $data, int $userId): array
    {
        $pdo = Database::pdo();
        $ids = [];
        $now = time();
        $qty = (int)$data['qty'];
        $dtPrint = !empty($data['activate']) ? $now : 0;
        $sql = 'INSERT INTO base_good_sticker_ins
                (ID_USER_CREATE, ID_ACT, ID_GOOD, ID_STOCK, ID_SUPPLIER, DT_CREATE, DT_EXPIRE,
                 DT_REVISION, DT_MOVEMENT, DT_PRINT, DT_REPRINT, PIN, HASH, LOG, PRICE, QTY,
                 QTYU_IN, QTYU_OUT, QTY_QTYU_RATE, DESCR_STOCK, DESCR, IS_STRANGE, WEIGHT_START, TOKEN)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, \'\', ?, \'\')';
        $st = $pdo->prepare($sql);
        for ($i = 0; $i < $qty; $i++) {
            $pin = self::randPin(4);
            $hash = self::makeHash($pin, $now + $i);
            $st->execute([
                $userId,
                $data['id_act'],
                $data['id_good'],
                $data['id_stock'],
                $data['id_supplier'],
                $now,
                $data['dt_expire'],
                $dtPrint,
                $pin,
                $hash,
                '',
                $data['price'],
                $qty,
                $data['qtyu_in'],
                $data['qty_qtyu_rate'],
                $data['descr_stock'],
                $data['descr'],
                $data['weight_start'],
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        Acts2InRepository::recalcSumm((int)$data['id_act']);
        return $ids;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new InvalidArgumentException(t('stickers.err.sticker_not_found', ['id' => $id]));
        }
        if ((float)$row['qtyu_out'] > 0 && !Auth::capability('SUPERADMIN_ACCESS')) {
            throw new InvalidArgumentException(t('stickers.err.sticker_used_locked'));
        }
        if ((int)$data['qty'] !== (int)$row['qty']) {
            throw new InvalidArgumentException(t('stickers.err.qty_locked'));
        }
        $dtPrint = (int)$row['dt_print'];
        if (!empty($data['activate']) && $dtPrint < 1) {
            $dtPrint = time();
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET
             ID_GOOD = ?, PRICE = ?, QTYU_IN = ?, QTY_QTYU_RATE = ?,
             DESCR = ?, DESCR_STOCK = ?, DT_EXPIRE = ?, WEIGHT_START = ?, DT_PRINT = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['id_good'],
            $data['price'],
            $data['qtyu_in'],
            $data['qty_qtyu_rate'],
            $data['descr'],
            $data['descr_stock'],
            $data['dt_expire'],
            $data['weight_start'],
            $dtPrint,
            $id,
        ]);
        // Sync twin batch fields
        Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET
             PRICE = ?, QTY_QTYU_RATE = ?, QTYU_IN = ?, DESCR = ?, DESCR_STOCK = ?
             WHERE ID_ACT = ? AND ID_GOOD = ? AND QTY = ? AND QTYU_OUT = 0'
        )->execute([
            $data['price'],
            $data['qty_qtyu_rate'],
            $data['qtyu_in'],
            $data['descr'],
            $data['descr_stock'],
            (int)$row['id_act'],
            (int)$row['id_good'],
            (int)$row['qty'],
        ]);
        Acts2InRepository::recalcSumm((int)$row['id_act']);
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<int>}
     */
    public static function deleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        $actIds = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = self::find($id);
            if (!$row) {
                continue;
            }
            if ((float)$row['qtyu_out'] > 0) {
                $skipped[] = $id;
                continue;
            }
            Database::pdo()->prepare('DELETE FROM base_good_sticker_ins WHERE ID = ?')->execute([$id]);
            $actIds[(int)$row['id_act']] = true;
            $done++;
        }
        foreach (array_keys($actIds) as $actId) {
            Acts2InRepository::recalcSumm((int)$actId);
        }
        return ['done' => $done, 'skipped' => $skipped];
    }
}
