<?php
declare(strict_types=1);

final class VacationsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'v.ID',
            'user' => 'v.ID_USER',
            'type' => 'v.ID_TYPE',
            'start' => 'v.DT_START',
            'end' => 'v.DT_END',
        ];
    }

    public static function canSeeOthers(): bool
    {
        return Auth::capability('CAN_SEE_SUM_OTHERS') || AgentHttp::readScopeUnrestricted();
    }

    /**
     * Agent JSON update = patch: omitted fields keep the stored row (checkboxes included).
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function patchPost(array $post, array $row): array
    {
        foreach (['user_id', 'id_type', 'dt_start', 'dt_end', 'descr', 'direction', 'days_paid', 'days_unpaid'] as $k) {
            if (!array_key_exists($k, $post) || $post[$k] === null || $post[$k] === '') {
                $post[$k] = (string)($row[$k] ?? '');
            }
        }
        foreach (['show_user', 'sign_req'] as $k) {
            if (!array_key_exists($k, $post)) {
                $post[$k] = !empty($row[$k]) ? '1' : '';
            }
        }
        return $post;
    }

    /**
     * Restrict bulk ids to the actor's own rows when CAN_SEE_SUM_OTHERS is off.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function scopeOwnedIds(array $ids): array
    {
        $ids = self::sanitizeIds($ids);
        if ($ids === [] || self::canSeeOthers() || !Database::tableExists('base_vacations')) {
            return $ids;
        }
        $uid = Auth::userId();
        if ($uid < 1) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_vacations WHERE ID IN ($ph) AND ID_USER = ?"
        );
        $st->execute(array_merge($ids, [$uid]));
        $allowed = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $allowed[] = (int)$id;
        }
        return $allowed;
    }

    /**
     * @param array{
     *   q?:string,user_id?:int,sign?:int,id_type?:int,direction?:int,
     *   year?:int|string,month?:int|string,show_user_only?:bool,
     *   order?:string,limit?:int,offset?:int,scope_user_id?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_vacations')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];

        $scopeUser = (int)($opts['scope_user_id'] ?? 0);
        if ($scopeUser > 0) {
            $where[] = 'v.ID_USER = ?';
            $params[] = $scopeUser;
        }
        if (!empty($opts['show_user_only'])) {
            $where[] = 'v.SHOW_USER = 1';
        }

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(v.DESCR LIKE ? OR CAST(v.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $userId = (int)($opts['user_id'] ?? 0);
        if ($userId > 0) {
            $where[] = 'v.ID_USER = ?';
            $params[] = $userId;
        }
        $sign = (int)($opts['sign'] ?? 0);
        if ($sign === 1) {
            $where[] = 'v.SIGN_REQ = 1 AND v.ID_USER_SIGN = 0';
        } elseif ($sign === 2) {
            $where[] = 'v.SIGN_REQ = 1 AND v.ID_USER_SIGN > 0';
        }
        $idType = (int)($opts['id_type'] ?? 0);
        if (in_array($idType, [1, 2, 3], true)) {
            $where[] = 'v.ID_TYPE = ?';
            $params[] = $idType;
        }
        $direction = (int)($opts['direction'] ?? 0);
        if ($direction === 1) {
            $where[] = 'v.DIRECTION = 1';
        } elseif ($direction === -1) {
            $where[] = 'v.DIRECTION = -1';
        }

        $yearRaw = $opts['year'] ?? '';
        $monthRaw = $opts['month'] ?? '';
        $year = (is_numeric($yearRaw) && (int)$yearRaw > 0) ? (int)$yearRaw : 0;
        $month = (is_numeric($monthRaw) && (int)$monthRaw >= 1 && (int)$monthRaw <= 12) ? (int)$monthRaw : 0;
        if ($year > 0) {
            if ($month > 0) {
                $tStart = mktime(0, 0, 0, $month, 1, $year) ?: 0;
                $tEnd = mktime(0, 0, 0, $month + 1, 1, $year) ?: 0;
            } else {
                $tStart = mktime(0, 0, 0, 1, 1, $year) ?: 0;
                $tEnd = mktime(0, 0, 0, 1, 1, $year + 1) ?: 0;
            }
            if ($tStart > 0 && $tEnd > $tStart) {
                // Overlap: start or end falls inside selected window (oper semantics).
                $where[] = '((v.DT_START >= ? AND v.DT_START < ?) OR (v.DT_END >= ? AND v.DT_END < ?))';
                $params[] = $tStart;
                $params[] = $tEnd;
                $params[] = $tStart;
                $params[] = $tEnd;
            }
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_vacations v WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'v.DT_START DESC, v.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT v.ID AS id, v.ID_OPERATOR AS operator_id, v.ID_USER AS user_id,
                       v.ID_USER_SIGN AS user_sign, v.SIGN_REQ AS sign_req,
                       v.DT_CREATE AS dt_create, v.DT_START AS dt_start, v.DT_END AS dt_end,
                       v.DESCR AS descr, v.DAYS_LONG AS days_long,
                       v.DAYS_PAID_AMOUNT AS days_paid, v.DAYS_UNPAID_AMOUNT AS days_unpaid,
                       v.ID_TYPE AS id_type, v.DIRECTION AS direction, v.SHOW_USER AS show_user
                FROM base_vacations v
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::castRow($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_vacations')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS operator_id, ID_USER AS user_id,
                    ID_USER_SIGN AS user_sign, SIGN_REQ AS sign_req,
                    DT_CREATE AS dt_create, DT_START AS dt_start, DT_END AS dt_end,
                    DESCR AS descr, DAYS_LONG AS days_long,
                    DAYS_PAID_AMOUNT AS days_paid, DAYS_UNPAID_AMOUNT AS days_unpaid,
                    ID_TYPE AS id_type, DIRECTION AS direction, SHOW_USER AS show_user
             FROM base_vacations WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::castRow($row) : null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, ?array $existing = null): array
    {
        $userId = (int)($post['user_id'] ?? 0);
        if ($userId < 1) {
            throw new InvalidArgumentException(t('vacations.err.user_required'));
        }
        $idType = (int)($post['id_type'] ?? 3);
        if (!in_array($idType, [1, 2, 3], true)) {
            $idType = 3;
        }
        $dtStart = self::parseDate((string)($post['dt_start'] ?? ''));
        $dtEnd = self::parseDate((string)($post['dt_end'] ?? ''));
        if ($dtStart < 1 || $dtEnd < 1 || $dtEnd < $dtStart) {
            throw new InvalidArgumentException(t('vacations.err.dates'));
        }
        $daysLong = (int)round(($dtEnd - $dtStart) / 86400) + 1;
        $direction = (int)($post['direction'] ?? 0);
        if (!in_array($direction, [-1, 0, 1], true)) {
            $direction = 0;
        }
        $showUser = !empty($post['show_user']) ? 1 : 0;
        $signReq = !empty($post['sign_req']) ? 1 : 0;
        if ($signReq && !$showUser) {
            throw new InvalidArgumentException(t('vacations.err.sign_show'));
        }

        $daysPaid = (int)($post['days_paid'] ?? 0);
        $daysUnpaid = (int)($post['days_unpaid'] ?? 0);
        if ($idType === 1) {
            $daysPaid = $daysLong;
            $daysUnpaid = 0;
            if ($direction === 0) {
                $direction = -1;
            }
        } elseif ($idType === 2) {
            $daysPaid = 0;
            $daysUnpaid = $daysLong;
            if ($direction === 0) {
                $direction = -1;
            }
        }

        $excludeId = (int)($existing['id'] ?? 0);
        if (self::hasOverlap($userId, $idType, $dtStart, $dtEnd, $excludeId)) {
            throw new InvalidArgumentException(t('vacations.err.overlap'));
        }

        $userSign = (int)($existing['user_sign'] ?? 0);
        if (!empty($post['sign_self']) && Auth::userId() === $userId && $signReq && $userSign < 1) {
            $userSign = time();
        }

        return [
            'operator_id' => $existing ? (int)$existing['operator_id'] : Auth::userId(),
            'user_id' => $userId,
            'user_sign' => $userSign,
            'sign_req' => $signReq,
            'dt_create' => $existing ? (int)$existing['dt_create'] : time(),
            'dt_start' => $dtStart,
            'dt_end' => $dtEnd,
            'descr' => trim((string)($post['descr'] ?? '')),
            'days_long' => max(0, $daysLong),
            'days_paid' => max(0, $daysPaid),
            'days_unpaid' => max(0, $daysUnpaid),
            'id_type' => $idType,
            'direction' => $direction,
            'show_user' => $showUser,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_vacations
             (ID_OPERATOR, ID_USER, ID_USER_SIGN, SIGN_REQ, DT_CREATE, DT_START, DT_END,
              DESCR, DAYS_LONG, DAYS_PAID_AMOUNT, DAYS_UNPAID_AMOUNT, ID_TYPE, DIRECTION, SHOW_USER)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['operator_id'],
            $data['user_id'],
            $data['user_sign'],
            $data['sign_req'],
            $data['dt_create'],
            $data['dt_start'],
            $data['dt_end'],
            $data['descr'],
            $data['days_long'],
            $data['days_paid'],
            $data['days_unpaid'],
            $data['id_type'],
            $data['direction'],
            $data['show_user'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid vacation id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_vacations SET
             ID_USER = ?, ID_USER_SIGN = ?, SIGN_REQ = ?, DT_START = ?, DT_END = ?,
             DESCR = ?, DAYS_LONG = ?, DAYS_PAID_AMOUNT = ?, DAYS_UNPAID_AMOUNT = ?,
             ID_TYPE = ?, DIRECTION = ?, SHOW_USER = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['user_id'],
            $data['user_sign'],
            $data['sign_req'],
            $data['dt_start'],
            $data['dt_end'],
            $data['descr'],
            $data['days_long'],
            $data['days_paid'],
            $data['days_unpaid'],
            $data['id_type'],
            $data['direction'],
            $data['show_user'],
            $id,
        ]);
    }

    /**
     * @param list<int> $ids
     * @return int
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = self::scopeOwnedIds($ids);
        if ($ids === [] || !Database::tableExists('base_vacations')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_vacations WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * @param list<int> $ids
     * @return int
     */
    public static function signByIds(array $ids): int
    {
        $ids = self::scopeOwnedIds($ids);
        if ($ids === [] || !Database::tableExists('base_vacations')) {
            return 0;
        }
        $signer = Auth::userId();
        if ($signer < 1) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$signer], $ids);
        $st = Database::pdo()->prepare(
            "UPDATE base_vacations SET ID_USER_SIGN = ?
             WHERE ID IN ($placeholders) AND SIGN_REQ = 1 AND ID_USER_SIGN = 0"
        );
        $st->execute($params);
        return $st->rowCount();
    }

    public static function typeLabel(int $type): string
    {
        $key = 'vacations.type.' . $type;
        $label = t($key);
        return $label !== $key ? $label : ('#' . $type);
    }

    public static function directionLabel(int $dir): string
    {
        $key = 'vacations.dir.' . $dir;
        $label = t($key);
        return $label !== $key ? $label : (string)$dir;
    }

    public static function signLabel(array $row): string
    {
        if ((int)($row['sign_req'] ?? 0) < 1) {
            return t('vacations.sign.none');
        }
        return (int)($row['user_sign'] ?? 0) > 0
            ? t('vacations.sign.done')
            : t('vacations.sign.pending');
    }

    /** Count unsigned vacation rows awaiting signature. */
    public static function countPendingSign(?int $userId = null): int
    {
        if (!Database::tableExists('base_vacations')) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) FROM base_vacations WHERE SIGN_REQ = 1 AND ID_USER_SIGN = 0';
        $params = [];
        if ($userId !== null && $userId > 0) {
            $sql .= ' AND ID_USER = ?';
            $params[] = $userId;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /**
     * Topbar notice chips (oper НП-О / П-О).
     *
     * @return list<array{short:string,href:string,title:string,tone:string}>
     */
    public static function noticesForUser(int $uid): array
    {
        if ($uid < 1 || !Auth::can('vacations.view')) {
            return [];
        }
        $out = [];
        if (self::canSeeOthers()) {
            $n = self::countPendingSign(null);
            if ($n > 0) {
                $out[] = [
                    'short' => t('vacations.notice.unsigned_short', ['n' => $n]),
                    'href' => '/vacations?sign=1',
                    'title' => t('vacations.notice.unsigned_title'),
                    'tone' => 'warn',
                ];
            }
        } else {
            $n = self::countPendingSign($uid);
            if ($n > 0) {
                $out[] = [
                    'short' => t('vacations.notice.sign_mine_short', ['n' => $n]),
                    'href' => '/vacations?sign=1',
                    'title' => t('vacations.notice.sign_mine_title'),
                    'tone' => 'warn',
                ];
            }
        }
        return $out;
    }

    /** Years for filter select (current+1 down 10 years), like oper. @return list<int> */
    public static function yearOptions(): array
    {
        $y = (int)date('Y') + 1;
        $out = [];
        for ($i = 0; $i < 10; $i++) {
            $out[] = $y - $i;
        }
        return $out;
    }

    private static function hasOverlap(int $userId, int $idType, int $dtStart, int $dtEnd, int $excludeId): bool
    {
        $sql = 'SELECT COUNT(*) FROM base_vacations
                WHERE ID_USER = ? AND ID_TYPE = ?
                  AND ((DT_START <= ? AND DT_END > ?)
                    OR (DT_START < ? AND DT_END >= ?)
                    OR (DT_START > ? AND DT_END < ?))';
        $params = [$userId, $idType, $dtStart, $dtStart, $dtEnd, $dtEnd, $dtStart, $dtEnd];
        if ($excludeId > 0) {
            $sql .= ' AND ID <> ?';
            $params[] = $excludeId;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn() > 0;
    }

    private static function parseDate(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int)$raw;
        }
        $ts = strtotime($raw . ' 00:00:00');
        return $ts !== false ? $ts : 0;
    }

    /** @param array<string,mixed> $row */
    private static function castRow(array $row): array
    {
        foreach (['id', 'operator_id', 'user_id', 'user_sign', 'sign_req', 'dt_create', 'dt_start', 'dt_end',
            'days_long', 'days_paid', 'days_unpaid', 'id_type', 'direction', 'show_user'] as $k) {
            $row[$k] = (int)($row[$k] ?? 0);
        }
        $row['descr'] = (string)($row['descr'] ?? '');
        return $row;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sanitizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }
}
