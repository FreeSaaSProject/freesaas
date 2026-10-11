<?php
declare(strict_types=1);

final class ProceduresRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'p.ID',
            'dtstart' => 'p.DTSTART',
            'dtend' => 'p.DTEND',
            'type' => 'p.TYPE',
            'summ' => 'p.SUMM',
            'enabled' => 'p.ENABLED',
        ];
    }

    public static function tsToLocal(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        return date('Y-m-d\TH:i', $ts);
    }

    /** Safe return URL (same-origin); fallback when missing/invalid. */
    public static function sanitizeReturnUrl(?string $raw): string
    {
        $safe = NavContext::sanitize($raw);
        if ($safe === '') {
            return '/procedures';
        }
        NavContext::setReturnUrl($safe);
        return $safe;
    }

    public static function localToTs(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        $ts = strtotime($raw);
        return $ts !== false ? (int)$ts : 0;
    }

    /**
     * @param array{
     *   q?:string,
     *   enabled?:string|null,
     *   type?:int|null,
     *   date_from?:string,
     *   date_to?:string,
     *   id_user?:int|null,
     *   id_good?:int|null,
     *   id_admin?:int|null,
     *   id_operator?:int|null,
     *   restored?:string|null,
     *   order?:string,
     *   limit?:int,
     *   offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_procedures')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.NOTICE LIKE ? OR p.TOKEN LIKE ? OR CAST(p.ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null && $opts['enabled'] !== '') {
            $where[] = 'p.ENABLED = ?';
            $params[] = (int)$opts['enabled'];
        }
        if (array_key_exists('restored', $opts) && $opts['restored'] !== null && $opts['restored'] !== '') {
            $where[] = 'p.RESTORED = ?';
            $params[] = (int)$opts['restored'];
        }
        $typeId = (int)($opts['type'] ?? 0);
        if ($typeId > 0) {
            $where[] = 'p.TYPE = ?';
            $params[] = $typeId;
        }
        $dateFrom = trim((string)($opts['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $ts = strtotime($dateFrom . ' 00:00:00');
            if ($ts !== false) {
                $where[] = 'p.DTSTART >= ?';
                $params[] = (int)$ts;
            }
        }
        $dateTo = trim((string)($opts['date_to'] ?? ''));
        if ($dateTo !== '') {
            $ts = strtotime($dateTo . ' 23:59:59');
            if ($ts !== false) {
                $where[] = 'p.DTSTART <= ?';
                $params[] = (int)$ts;
            }
        }
        $idUser = (int)($opts['id_user'] ?? 0);
        if ($idUser > 0) {
            $where[] = 'p.ID_USER = ?';
            $params[] = $idUser;
        }
        $idGood = (int)($opts['id_good'] ?? 0);
        if ($idGood > 0) {
            $where[] = 'p.ID_GOOD = ?';
            $params[] = $idGood;
        }
        $idAdmin = (int)($opts['id_admin'] ?? 0);
        if ($idAdmin > 0) {
            $where[] = 'p.ID_ADMIN = ?';
            $params[] = $idAdmin;
        }
        $idOperator = (int)($opts['id_operator'] ?? 0);
        if ($idOperator > 0) {
            $where[] = 'p.ID_OPERATOR = ?';
            $params[] = $idOperator;
        }

        $sqlWhere = implode(' AND ', $where);
        $total = 0;
        if (empty($opts['skip_total'])) {
            $stc = $pdo->prepare("SELECT COUNT(*) FROM base_procedures p WHERE $sqlWhere");
            $stc->execute($params);
            $total = (int)$stc->fetchColumn();
        }

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'p.DTSTART DESC';
        }

        $joins = '';
        $extraCols = 'NULL AS type_name, NULL AS client_name, NULL AS product_name, NULL AS admin_name, NULL AS operator_name';
        if (Database::tableExists('base_procedure_types')) {
            $joins .= ' LEFT JOIN base_procedure_types t ON t.ID = p.TYPE';
            $extraCols = 't.TYPE AS type_name';
        } else {
            $extraCols = 'NULL AS type_name';
        }
        if (Database::tableExists('base_clients')) {
            $joins .= ' LEFT JOIN base_clients c ON c.id = p.ID_USER';
            $extraCols .= ', c.name AS client_name';
        } else {
            $extraCols .= ', NULL AS client_name';
        }
        if (Database::tableExists('base_products')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $extraCols .= ', g.name AS product_name';
        } else {
            $extraCols .= ', NULL AS product_name';
        }
        if (Database::tableExists('base_users')) {
            $joins .= ' LEFT JOIN base_users ua ON ua.id = p.ID_ADMIN';
            $joins .= ' LEFT JOIN base_users uo ON uo.id = p.ID_OPERATOR';
            $extraCols .= ', COALESCE(NULLIF(ua.name, \'\'), ua.login) AS admin_name';
            $extraCols .= ', COALESCE(NULLIF(uo.name, \'\'), uo.login) AS operator_name';
        } else {
            $extraCols .= ', NULL AS admin_name, NULL AS operator_name';
        }

        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT p.ID AS id, p.DTCREATE AS dtcreate, p.DTSTART AS dtstart, p.DTEND AS dtend,
                       p.DTEND_PLAN AS dtend_plan, p.ID_OPERATOR AS id_operator, p.ID_ADMIN AS id_admin,
                       p.ID_GOOD AS id_good, p.ID_TABLE AS id_table, p.ID_USER AS id_user,
                       p.ID_TERMINAL AS id_terminal, p.TYPE AS type, p.SUMM AS summ,
                       p.SUMM_DEFAULT AS summ_default, p.NOTICE AS notice, p.ENABLED AS enabled,
                       p.TOKEN AS token, p.ID_EVENT_PARENT AS id_event_parent,
                       p.`1C_ID` AS onec_id, p.`1C_BRACELET` AS onec_bracelet, p.RESTORED AS restored,
                       $extraCols
                FROM base_procedures p
                $joins
                WHERE $sqlWhere
                ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /**
     * Calendar helper: procedures whose DTSTART falls in [scanStart, scanEnd).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForDayRange(int $scanStart, int $scanEnd, ?int $staffId = null): array
    {
        if (!Database::tableExists('base_procedures') || $scanEnd <= $scanStart) {
            return [];
        }
        $pdo = Database::pdo();
        $where = 'p.DTSTART >= ? AND p.DTSTART < ?';
        $params = [$scanStart, $scanEnd];
        if ($staffId !== null && $staffId > 0) {
            $where .= ' AND (p.ID_OPERATOR = ? OR p.ID_ADMIN = ?)';
            $params[] = $staffId;
            $params[] = $staffId;
        }

        $joins = '';
        $extraCols = 'NULL AS type_name, NULL AS client_name, NULL AS product_name, NULL AS admin_name, NULL AS operator_name';
        if (Database::tableExists('base_procedure_types')) {
            $joins .= ' LEFT JOIN base_procedure_types t ON t.ID = p.TYPE';
            $extraCols = 't.TYPE AS type_name, NULL AS client_name, NULL AS product_name, NULL AS admin_name, NULL AS operator_name';
        }
        if (Database::tableExists('base_clients')) {
            $joins .= ' LEFT JOIN base_clients c ON c.id = p.ID_USER';
            $extraCols = preg_replace('/NULL AS client_name/', 'c.name AS client_name', $extraCols, 1) ?? $extraCols;
        }
        if (Database::tableExists('base_products')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $extraCols = preg_replace('/NULL AS product_name/', 'g.name AS product_name', $extraCols, 1) ?? $extraCols;
        }
        if (Database::tableExists('base_users')) {
            $joins .= ' LEFT JOIN base_users ua ON ua.id = p.ID_ADMIN';
            $joins .= ' LEFT JOIN base_users uo ON uo.id = p.ID_OPERATOR';
            $extraCols = preg_replace('/NULL AS admin_name/', "COALESCE(NULLIF(ua.name, ''), ua.login) AS admin_name", $extraCols, 1) ?? $extraCols;
            $extraCols = preg_replace('/NULL AS operator_name/', "COALESCE(NULLIF(uo.name, ''), uo.login) AS operator_name", $extraCols, 1) ?? $extraCols;
        }

        $st = $pdo->prepare(
            "SELECT p.ID AS id, p.DTSTART AS dtstart, p.DTEND AS dtend,
                    p.ID_OPERATOR AS id_operator, p.ID_ADMIN AS id_admin,
                    p.ID_GOOD AS id_good, p.ID_USER AS id_user, p.TYPE AS type,
                    p.SUMM AS summ, p.ENABLED AS enabled, p.ID_EVENT_PARENT AS id_event_parent,
                    $extraCols
             FROM base_procedures p
             $joins
             WHERE $where
             ORDER BY p.DTSTART ASC, p.ID ASC"
        );
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_procedures')) {
            return null;
        }
        $pdo = Database::pdo();
        $joins = '';
        $extraCols = 'NULL AS type_name';
        if (Database::tableExists('base_procedure_types')) {
            $joins .= ' LEFT JOIN base_procedure_types t ON t.ID = p.TYPE';
            $extraCols = 't.TYPE AS type_name';
        }
        if (Database::tableExists('base_clients')) {
            $joins .= ' LEFT JOIN base_clients c ON c.id = p.ID_USER';
            $extraCols .= ', c.name AS client_name';
        } else {
            $extraCols .= ', NULL AS client_name';
        }
        if (Database::tableExists('base_products')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $extraCols .= ', g.name AS product_name';
        } else {
            $extraCols .= ', NULL AS product_name';
        }
        if (Database::tableExists('base_users')) {
            $joins .= ' LEFT JOIN base_users ua ON ua.id = p.ID_ADMIN';
            $joins .= ' LEFT JOIN base_users uo ON uo.id = p.ID_OPERATOR';
            $extraCols .= ', COALESCE(NULLIF(ua.name, \'\'), ua.login) AS admin_name';
            $extraCols .= ', COALESCE(NULLIF(uo.name, \'\'), uo.login) AS operator_name';
        } else {
            $extraCols .= ', NULL AS admin_name, NULL AS operator_name';
        }
        $st = $pdo->prepare(
            "SELECT p.ID AS id, p.DTCREATE AS dtcreate, p.DTSTART AS dtstart, p.DTEND AS dtend,
                    p.DTEND_PLAN AS dtend_plan, p.ID_OPERATOR AS id_operator, p.ID_ADMIN AS id_admin,
                    p.ID_GOOD AS id_good, p.ID_TABLE AS id_table, p.ID_USER AS id_user,
                    p.ID_TERMINAL AS id_terminal, p.TYPE AS type, p.SUMM AS summ,
                    p.SUMM_DEFAULT AS summ_default, p.NOTICE AS notice, p.ENABLED AS enabled,
                    p.TOKEN AS token, p.ID_EVENT_PARENT AS id_event_parent,
                    p.`1C_ID` AS onec_id, p.`1C_BRACELET` AS onec_bracelet, p.RESTORED AS restored,
                    $extraCols
             FROM base_procedures p
             $joins
             WHERE p.ID = ?
             LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Tables module off → massage-table id is always 1. */
    public static function normalizeTableId(int $idTable): int
    {
        if (class_exists('TablesRepository', false)) {
            return TablesRepository::normalizeId($idTable);
        }
        return $idTable > 0 ? $idTable : 1;
    }

    /**
     * Create missing journal rows for each product in an event CONTENT.
     * Idempotent on (ID_EVENT_PARENT, ID_GOOD).
     *
     * @return array{created:list<int>,skipped:list<int>}
     */
    public static function createFromEvent(int $eventId, ?int $onlyGoodId = null): array
    {
        if ($eventId < 1 || !class_exists('EventsRepository', false)) {
            throw new InvalidArgumentException(t('procedures.err.event_required'));
        }
        $event = EventsRepository::find($eventId);
        if (!$event) {
            throw new InvalidArgumentException(t('procedures.err.event_not_found', ['id' => $eventId]));
        }
        if (!empty($event['is_block'])) {
            throw new InvalidArgumentException(t('procedures.err.event_is_block'));
        }
        if ((int)($event['id_user'] ?? 0) < 1) {
            throw new InvalidArgumentException(t('procedures.err.event_no_client'));
        }
        $goodIds = $event['content_ids'] ?? EventsRepository::parseContentIds((string)($event['content'] ?? ''));
        if ($onlyGoodId !== null && $onlyGoodId > 0) {
            $goodIds = in_array($onlyGoodId, $goodIds, true) ? [$onlyGoodId] : [];
        }
        if ($goodIds === []) {
            throw new InvalidArgumentException(t('procedures.err.event_no_content'));
        }

        $priceMap = $event['content_price_map'] ?? EventsRepository::parseContentPrices((string)($event['content_price'] ?? ''));
        $dayStart = (int)$event['dtstart'];
        if ($dayStart > 0) {
            $dayStart = (int)strtotime(date('Y-m-d 00:00:00', $dayStart));
        }
        $idTable = 1;
        if ($dayStart > 0 && class_exists('WorkweeksRepository', false)) {
            $shift = WorkweeksRepository::findByDayOperator($dayStart, (int)$event['id_operator']);
            if ($shift) {
                $idTable = self::normalizeTableId((int)$shift['id_table']);
            }
        } else {
            $idTable = self::normalizeTableId(0);
        }

        $created = [];
        $skipped = [];
        $cursor = (int)$event['dtstart'] > 0 ? (int)$event['dtstart'] : time();
        $eventEnd = (int)$event['dtend'];

        foreach ($goodIds as $gid) {
            $gid = (int)$gid;
            if ($gid < 1) {
                continue;
            }
            if (self::existsForEventGood($eventId, $gid)) {
                $skipped[] = $gid;
                continue;
            }
            $product = ProductsRepository::find($gid);
            $typeId = $product ? (int)($product['type_id'] ?? 0) : 0;
            if ($typeId < 1) {
                throw new InvalidArgumentException(t('procedures.err.product_no_type', ['id' => $gid]));
            }
            $summ = (int)round((float)($priceMap[$gid] ?? ($product['price'] ?? 0)));
            $dur = $product ? max(0, (int)($product['time_sec'] ?? 0)) : 0;
            if ($dur < 1) {
                $dur = 3600;
            }
            $dtend = $cursor + $dur;
            if ($eventEnd > $cursor && $dtend > $eventEnd && count($goodIds) === 1) {
                $dtend = $eventEnd;
            }
            $newId = self::create([
                'dtstart' => $cursor,
                'dtend' => $dtend,
                'dtend_plan' => $dtend,
                'id_operator' => (int)$event['id_operator'],
                'id_admin' => Auth::userId(),
                'id_good' => $gid,
                'id_table' => $idTable,
                'id_user' => (int)$event['id_user'],
                'id_terminal' => 0,
                'type' => $typeId,
                'summ' => max(0, $summ),
                'summ_default' => max(0, $summ),
                'notice' => t('procedures.notice.from_event', ['id' => $eventId]),
                'enabled' => 1,
                'token' => '',
                'id_event_parent' => $eventId,
                'onec_id' => '',
                'onec_bracelet' => '',
                'restored' => 0,
            ]);
            $created[] = $newId;
            $cursor = $dtend;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public static function existsForEventGood(int $eventId, int $goodId): bool
    {
        if ($eventId < 1 || $goodId < 1 || !Database::tableExists('base_procedures')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_procedures WHERE ID_EVENT_PARENT = ? AND ID_GOOD = ? LIMIT 1'
        );
        $st->execute([$eventId, $goodId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listByEventParent(int $eventId): array
    {
        if ($eventId < 1 || !Database::tableExists('base_procedures')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_GOOD AS id_good, DTSTART AS dtstart, DTEND AS dtend,
                    SUMM AS summ, ENABLED AS enabled, ID_EVENT_PARENT AS id_event_parent
             FROM base_procedures
             WHERE ID_EVENT_PARENT = ?
             ORDER BY DTSTART ASC, ID ASC'
        );
        $st->execute([$eventId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $type = max(0, (int)($post['type'] ?? 0));
        if ($type < 1) {
            throw new InvalidArgumentException(t('procedures.err.type_required'));
        }
        $idUser = max(0, (int)($post['id_user'] ?? 0));
        if ($idUser < 1) {
            throw new InvalidArgumentException(t('procedures.err.client_required'));
        }
        $idGood = max(0, (int)($post['id_good'] ?? 0));
        if ($idGood < 1) {
            throw new InvalidArgumentException(t('procedures.err.product_required'));
        }

        $dtstart = self::localToTs((string)($post['dtstart'] ?? ''));
        if ($dtstart < 1) {
            $dtstart = time();
        }
        $dtend = self::localToTs((string)($post['dtend'] ?? ''));
        $dtendPlan = self::localToTs((string)($post['dtend_plan'] ?? ''));

        $token = mb_substr(trim((string)($post['token'] ?? '')), 0, 32);

        return [
            'dtstart' => $dtstart,
            'dtend' => $dtend,
            'dtend_plan' => $dtendPlan,
            'id_operator' => max(0, (int)($post['id_operator'] ?? 0)),
            'id_admin' => max(0, (int)($post['id_admin'] ?? 0)),
            'id_good' => $idGood,
            'id_table' => self::normalizeTableId((int)($post['id_table'] ?? 0)),
            'id_user' => $idUser,
            'id_terminal' => max(0, (int)($post['id_terminal'] ?? 0)),
            'type' => $type,
            'summ' => max(0, (int)($post['summ'] ?? 0)),
            'summ_default' => max(0, (int)($post['summ_default'] ?? 0)),
            'notice' => mb_substr(trim((string)($post['notice'] ?? '')), 0, 255),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'token' => $token,
            'id_event_parent' => max(0, (int)($post['id_event_parent'] ?? 0)),
            'onec_id' => mb_substr(trim((string)($post['onec_id'] ?? '')), 0, 40),
            'onec_bracelet' => mb_substr(trim((string)($post['onec_bracelet'] ?? '')), 0, 32),
            'restored' => !empty($post['restored']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $token = (string)$data['token'];
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_procedures
             (DTCREATE, DTSTART, DTEND, DTEND_PLAN, ID_OPERATOR, ID_ADMIN, ID_GOOD, ID_TABLE,
              ID_USER, ID_TERMINAL, TYPE, SUMM, SUMM_DEFAULT, NOTICE, ENABLED, TOKEN,
              ID_EVENT_PARENT, `1C_ID`, `1C_BRACELET`, RESTORED)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            time(),
            $data['dtstart'],
            $data['dtend'],
            $data['dtend_plan'],
            $data['id_operator'],
            $data['id_admin'],
            $data['id_good'],
            $data['id_table'],
            $data['id_user'],
            $data['id_terminal'],
            $data['type'],
            $data['summ'],
            $data['summ_default'],
            $data['notice'],
            $data['enabled'],
            $token,
            $data['id_event_parent'],
            $data['onec_id'],
            $data['onec_bracelet'],
            $data['restored'],
        ]);
        $newId = (int)Database::pdo()->lastInsertId();
        if (class_exists('ProcedureBotNotify', false)) {
            ProcedureBotNotify::onSaved($newId, $data, 'new');
        }
        return $newId;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid procedure id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_procedures SET
             DTSTART = ?, DTEND = ?, DTEND_PLAN = ?, ID_OPERATOR = ?, ID_ADMIN = ?,
             ID_GOOD = ?, ID_TABLE = ?, ID_USER = ?, ID_TERMINAL = ?, TYPE = ?,
             SUMM = ?, SUMM_DEFAULT = ?, NOTICE = ?, ENABLED = ?, TOKEN = ?,
             ID_EVENT_PARENT = ?, `1C_ID` = ?, `1C_BRACELET` = ?, RESTORED = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['dtstart'],
            $data['dtend'],
            $data['dtend_plan'],
            $data['id_operator'],
            $data['id_admin'],
            $data['id_good'],
            $data['id_table'],
            $data['id_user'],
            $data['id_terminal'],
            $data['type'],
            $data['summ'],
            $data['summ_default'],
            $data['notice'],
            $data['enabled'],
            $data['token'],
            $data['id_event_parent'],
            $data['onec_id'],
            $data['onec_bracelet'],
            $data['restored'],
            $id,
        ]);
        if (class_exists('ProcedureBotNotify', false)) {
            ProcedureBotNotify::onSaved($id, $data, 'update');
        }
    }

    /**
     * @param list<int> $ids
     * @return int deleted count
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_procedures')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_procedures WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * @param list<int> $ids
     * @return int updated count
     */
    public static function setAdminByIds(array $ids, int $adminId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_procedures')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([max(0, $adminId)], $ids);
        $st = Database::pdo()->prepare(
            "UPDATE base_procedures SET ID_ADMIN = ? WHERE ID IN ($placeholders)"
        );
        $st->execute($params);
        return $st->rowCount();
    }

    public static function countByTypeId(int $typeId): int
    {
        if ($typeId < 1 || !Database::tableExists('base_procedures')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_procedures WHERE TYPE = ?');
        $st->execute([$typeId]);
        return (int)$st->fetchColumn();
    }

    public static function countByClientId(int $clientId): int
    {
        if ($clientId < 1 || !Database::tableExists('base_procedures')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_procedures WHERE ID_USER = ?');
        $st->execute([$clientId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Calendar day footer aggregates (oper $STATS lite).
     * Revenue excludes RESTORED>0 and internal 1C_ID=999.
     *
     * @return array{
     *   operators: array<int,array<string,mixed>>,
     *   revenue: float,
     *   restored: float,
     *   proc_count: int,
     *   promo_count: int
     * }
     */
    public static function dayColumnStats(int $dayStart, int $dayEnd, ?int $staffId = null): array
    {
        $empty = [
            'operators' => [],
            'revenue' => 0.0,
            'restored' => 0.0,
            'proc_count' => 0,
            'promo_count' => 0,
        ];
        if (!Database::tableExists('base_procedures') || $dayEnd <= $dayStart) {
            return $empty;
        }

        $where = 'p.DTSTART >= ? AND p.DTSTART < ? AND p.ENABLED = 1';
        $params = [$dayStart, $dayEnd];
        if ($staffId !== null && $staffId > 0) {
            $where .= ' AND (p.ID_OPERATOR = ? OR p.ID_ADMIN = ?)';
            $params[] = $staffId;
            $params[] = $staffId;
        }

        $joins = '';
        $nameCols = 'NULL AS operator_name, NULL AS is_hidden, 0 AS ID_STATUS';
        if (Database::tableExists('base_users')) {
            $joins = ' LEFT JOIN base_users u ON u.id = p.ID_OPERATOR';
            $nameCols = "COALESCE(NULLIF(u.name, ''), u.login) AS operator_name,
                         COALESCE(u.IS_HIDDEN, 0) AS is_hidden,
                         COALESCE(u.ID_STATUS, 0) AS ID_STATUS";
        }

        $st = Database::pdo()->prepare(
            "SELECT p.ID_OPERATOR AS id_operator, p.ID_ADMIN AS id_admin,
                    p.SUMM AS summ, p.SUMM_DEFAULT AS summ_default,
                    p.RESTORED AS restored, p.`1C_ID` AS onec_id, p.TYPE AS type,
                    $nameCols
             FROM base_procedures p
             $joins
             WHERE $where
             ORDER BY p.DTSTART ASC"
        );
        $st->execute($params);

        $ops = [];
        $revenue = 0.0;
        $restored = 0.0;
        $procCount = 0;
        $promoCount = 0;

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $oid = (int)($row['id_operator'] ?? 0);
            if ($oid < 1) {
                continue;
            }
            if (!isset($ops[$oid])) {
                $name = trim((string)($row['operator_name'] ?? ''));
                if ($name === '') {
                    $name = '#' . $oid;
                }
                $ops[$oid] = [
                    'id' => $oid,
                    'label' => $name,
                    'acronym' => class_exists('CalendarTimeline', false)
                        ? CalendarTimeline::acronym($name)
                        : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8'),
                    'is_hidden' => (int)($row['is_hidden'] ?? 0) > 0,
                    'ID_STATUS' => (int)($row['ID_STATUS'] ?? 0),
                    'summ' => 0.0,
                    'proc_count' => 0,
                    'promo_count' => 0,
                    'sums_by_type' => [],
                ];
            }
            $sum = (float)($row['summ'] ?? 0);
            $rest = (int)($row['restored'] ?? 0);
            $onec = trim((string)($row['onec_id'] ?? ''));
            $tid = (int)($row['type'] ?? 0);

            if ($rest > 0) {
                $restored += $sum;
                continue;
            }
            if ($onec === '999') {
                continue;
            }

            $ops[$oid]['summ'] += $sum;
            $ops[$oid]['proc_count']++;
            $ops[$oid]['sums_by_type'][$tid] = ($ops[$oid]['sums_by_type'][$tid] ?? 0.0) + $sum;
            $revenue += $sum;
            $procCount++;
            $def = (float)($row['summ_default'] ?? 0);
            if ($def > 0 && abs($sum - $def) > 0.01) {
                $ops[$oid]['promo_count']++;
                $promoCount++;
            }
        }

        return [
            'operators' => $ops,
            'revenue' => $revenue,
            'restored' => $restored,
            'proc_count' => $procCount,
            'promo_count' => $promoCount,
        ];
    }

    /**
     * Re-enable a cancelled procedure after restore settlement (oper DoAfterSaveBeforeExit).
     */
    public static function applyRestore(int $id): void
    {
        if ($id < 1 || !Database::tableExists('base_procedures')) {
            throw new InvalidArgumentException(t('procedures.err.not_found', ['id' => $id]));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_procedures SET RESTORED = 1, SUMM = SUMM_DEFAULT, ENABLED = 1 WHERE ID = ?'
        );
        $st->execute([$id]);
        if ($st->rowCount() < 1) {
            // Row may already match target state; ensure it exists.
            $check = Database::pdo()->prepare('SELECT ID FROM base_procedures WHERE ID = ? LIMIT 1');
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException(t('procedures.err.not_found', ['id' => $id]));
            }
        }
    }
}
