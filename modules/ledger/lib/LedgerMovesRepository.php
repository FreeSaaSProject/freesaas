<?php
declare(strict_types=1);

final class LedgerMovesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'm.ID',
            'dt' => 'm.DT',
            'dt_create' => 'm.DT_CREATE',
            'dt_update' => 'm.DT_UPDATE',
            'account' => 'm.ID_ACCOUNT',
            'type' => 'm.ID_TYPE',
            'sum' => 'm.SUMM',
        ];
    }

    /**
     * Oper applyPrevAccountMoveDefaults: last 5 moves of this type → unanimous default or top-2 buttons.
     *
     * @return array{
     *   defaults: array{direction?:int,account_id?:int,supplier_id?:int},
     *   buttons: array{direction?:list<array{val:string,cnt:int,total:int,label:string}>,account_id?:list<array{val:string,cnt:int,total:int,label:string}>,supplier_id?:list<array{val:string,cnt:int,total:int,label:string}>}
     * }
     */
    public static function prevSuggestionsForType(int $typeId): array
    {
        $out = ['defaults' => [], 'buttons' => []];
        if ($typeId < 1 || !Database::tableExists('base_account_moves')) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            'SELECT DIRECTION AS direction, ID_ACCOUNT AS account_id, ID_SUPPLIER AS supplier_id
             FROM base_account_moves
             WHERE ID_TYPE = ? AND DELETED = 0
             ORDER BY DT DESC, ID DESC
             LIMIT 5'
        );
        $st->execute([$typeId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            $st = Database::pdo()->prepare(
                'SELECT DIRECTION AS direction, ID_ACCOUNT AS account_id, ID_SUPPLIER AS supplier_id
                 FROM base_account_moves
                 WHERE ID_TYPE = ?
                 ORDER BY DT DESC, ID DESC
                 LIMIT 5'
            );
            $st->execute([$typeId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($rows === []) {
            return $out;
        }
        $accounts = LedgerAccountsRepository::names(false);
        $suppliers = class_exists('SuppliersRepository') ? SuppliersRepository::names(false) : [];
        foreach (['direction', 'account_id', 'supplier_id'] as $field) {
            $values = [];
            foreach ($rows as $row) {
                $v = (int)$row[$field];
                // Move form only allows credit/debit — skip "no change" (0).
                if ($field === 'direction' && !in_array($v, [-1, 1], true)) {
                    continue;
                }
                $values[] = (string)$v;
            }
            $variants = self::prevTopVariants($values, 2);
            if ($variants === []) {
                continue;
            }
            $unanimous = count($variants) === 1 && (int)$variants[0]['cnt'] === count($values);
            if ($unanimous) {
                $out['defaults'][$field] = (int)$variants[0]['val'];
                continue;
            }
            $btns = [];
            foreach ($variants as $v) {
                $btns[] = [
                    'val' => (string)$v['val'],
                    'cnt' => (int)$v['cnt'],
                    'total' => (int)$v['total'],
                    'label' => self::prevValueLabel($field, (string)$v['val'], $accounts, $suppliers),
                ];
            }
            $out['buttons'][$field] = $btns;
        }
        return $out;
    }

    /**
     * @param list<string> $values
     * @return list<array{val:string,cnt:int,total:int}>
     */
    private static function prevTopVariants(array $values, int $limit = 2): array
    {
        $freq = [];
        $firstSeen = [];
        foreach ($values as $i => $v) {
            $v = (string)$v;
            if (!isset($freq[$v])) {
                $freq[$v] = 0;
                $firstSeen[$v] = $i;
            }
            $freq[$v]++;
        }
        $items = [];
        foreach ($freq as $k => $c) {
            $items[] = ['val' => $k, 'cnt' => $c, 'seen' => $firstSeen[$k]];
        }
        usort($items, static function (array $a, array $b): int {
            if ($a['cnt'] !== $b['cnt']) {
                return $b['cnt'] <=> $a['cnt'];
            }
            return $a['seen'] <=> $b['seen'];
        });
        $total = count($values);
        $out = [];
        foreach (array_slice($items, 0, max(1, $limit)) as $it) {
            $out[] = ['val' => (string)$it['val'], 'cnt' => (int)$it['cnt'], 'total' => $total];
        }
        return $out;
    }

    /** @param array<int,string> $accounts @param array<int,string> $suppliers */
    private static function prevValueLabel(string $field, string $val, array $accounts, array $suppliers): string
    {
        if ($field === 'direction') {
            if ($val === '-1') {
                return t('ledger.dir.label.out');
            }
            if ($val === '1') {
                return t('ledger.dir.label.in');
            }
            return t('ledger.dir.label.none');
        }
        if ($field === 'account_id') {
            $id = (int)$val;
            return $accounts[$id] ?? (t('ledger.moves.col.account') . ' #' . $id);
        }
        if ($field === 'supplier_id') {
            $id = (int)$val;
            if ($id < 1) {
                return t('ledger.moves.supplier_none');
            }
            return $suppliers[$id] ?? (t('ledger.moves.field.supplier') . ' #' . $id);
        }
        return $val;
    }

    public static function accountBalance(int $accountId): float
    {
        if ($accountId < 1 || !Database::tableExists('base_account_moves')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(DIRECTION * SUMM), 0) FROM base_account_moves
             WHERE ID_ACCOUNT = ? AND DELETED = 0'
        );
        $st->execute([$accountId]);
        return (float)$st->fetchColumn();
    }

    /**
     * Newest move id for current list filters (DT DESC, ID DESC).
     *
     * @param array{q?:string,account_id?:int,type_id?:int,year?:int,month?:int,payment_id?:int,supplier_id?:int} $opts
     */
    public static function newestMoveId(array $opts = []): ?int
    {
        return self::newestMoveIdOnOrBefore($opts, null);
    }

    /**
     * Newest move with DT <= $onOrBeforeDt (recon attachment target). Null $onOrBeforeDt = any date.
     *
     * @param array{q?:string,account_id?:int,type_id?:int,year?:int,month?:int,payment_id?:int,supplier_id?:int} $opts
     */
    public static function newestMoveIdOnOrBefore(array $opts, ?int $onOrBeforeDt): ?int
    {
        if (!Database::tableExists('base_account_moves')) {
            return null;
        }
        $built = self::buildListWhere($opts);
        $where = $built['where'];
        $params = $built['params'];
        if ($onOrBeforeDt !== null) {
            $where .= ' AND m.DT <= ?';
            $params[] = $onOrBeforeDt;
        }
        $st = Database::pdo()->prepare(
            "SELECT m.ID FROM base_account_moves m WHERE $where ORDER BY m.DT DESC, m.ID DESC LIMIT 1"
        );
        $st->execute($params);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Running balance after each move (oper totalSummsArray), same filters as list, ASC by DT.
     *
     * @param array{q?:string,account_id?:int,type_id?:int,year?:int,month?:int,payment_id?:int,supplier_id?:int} $opts
     * @return array<int,float> move id => balance after that move
     */
    public static function runningBalances(array $opts = []): array
    {
        if (!Database::tableExists('base_account_moves')) {
            return [];
        }
        $built = self::buildListWhere($opts);
        $sql = 'SELECT m.ID AS id, m.DIRECTION AS direction, m.SUMM AS summ
                FROM base_account_moves m
                WHERE ' . $built['where'] . ' ORDER BY m.DT ASC, m.ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($built['params']);
        $running = 0.0;
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $running = round($running + ((int)$row['direction'] * (float)$row['summ']), 2);
            $out[(int)$row['id']] = $running;
        }
        return $out;
    }

    /**
     * @param array{q?:string,account_id?:int,type_id?:int,year?:int,month?:int,payment_id?:int,supplier_id?:int,include_deleted?:bool} $opts
     * @return array{where:string,params:list<mixed>}
     */
    private static function buildListWhere(array $opts): array
    {
        $where = ['1=1'];
        $params = [];
        if (empty($opts['include_deleted'])) {
            $where[] = 'm.DELETED = 0';
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(m.DESCR LIKE ? OR CAST(m.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $accountId = (int)($opts['account_id'] ?? 0);
        if ($accountId > 0) {
            $where[] = 'm.ID_ACCOUNT = ?';
            $params[] = $accountId;
        }
        $typeId = (int)($opts['type_id'] ?? 0);
        if ($typeId > 0) {
            $where[] = 'm.ID_TYPE = ?';
            $params[] = $typeId;
        }
        $year = (int)($opts['year'] ?? 0);
        $month = (int)($opts['month'] ?? 0);
        if ($year > 0 && $month > 0) {
            $start = mktime(0, 0, 0, $month, 1, $year) ?: 0;
            $end = mktime(23, 59, 59, $month, (int)date('t', $start), $year) ?: 0;
            $where[] = 'm.DT >= ? AND m.DT <= ?';
            $params[] = $start;
            $params[] = $end;
        } elseif ($year > 0) {
            $start = mktime(0, 0, 0, 1, 1, $year) ?: 0;
            $end = mktime(23, 59, 59, 12, 31, $year) ?: 0;
            $where[] = 'm.DT >= ? AND m.DT <= ?';
            $params[] = $start;
            $params[] = $end;
        }
        $paymentId = (int)($opts['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $where[] = 'm.ID_PAYMENT = ?';
            $params[] = $paymentId;
        }
        $supplierId = (int)($opts['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $where[] = 'm.ID_SUPPLIER = ?';
            $params[] = $supplierId;
        }
        // 1 = promised not paid, 2 = promised paid, 3 = overdue not paid (oper PROMISE_EXECUTED filter)
        $promise = (int)($opts['promise'] ?? 0);
        if ($promise === 1) {
            $where[] = 'm.DT_PROMISED > 0 AND m.PROMISE_EXECUTED = 0';
        } elseif ($promise === 2) {
            $where[] = 'm.DT_PROMISED > 0 AND m.PROMISE_EXECUTED > 0';
        } elseif ($promise === 3) {
            $where[] = 'm.DT_PROMISED > 0 AND m.DT_PROMISED < ? AND m.PROMISE_EXECUTED = 0';
            $params[] = time();
        }
        return ['where' => implode(' AND ', $where), 'params' => $params];
    }

    /** Overdue promised payments (for home notices). */
    public static function countOverduePromises(): int
    {
        if (!Database::tableExists('base_account_moves')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_account_moves
             WHERE DELETED = 0 AND DT_PROMISED > 0 AND DT_PROMISED < ? AND PROMISE_EXECUTED = 0'
        );
        $st->execute([time()]);
        return (int)$st->fetchColumn();
    }

    /**
     * @param array{
     *   q?:string,account_id?:int,type_id?:int,year?:int,month?:int,
     *   payment_id?:int,supplier_id?:int,include_deleted?:bool,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_account_moves')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $built = self::buildListWhere($opts);
        $sqlWhere = $built['where'];
        $params = $built['params'];
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_account_moves m WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'm.DT DESC, m.ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT m.ID AS id, m.ID_ACCOUNT AS account_id, m.ID_TYPE AS type_id,
                       m.DIRECTION AS direction, m.SUMM AS summ, m.DESCR AS descr,
                       m.DT AS dt, m.DT_CREATE AS dt_create, m.ID_USER_CREATE AS user_create,
                       m.UNCONFIRMED AS unconfirmed, m.DELETED AS deleted,
                       m.ID_PAYMENT AS payment_id, m.ID_SUPPLIER AS supplier_id,
                       m.ID_USER_PAYMENT AS user_payment, m.ID_MOVEMENT_LINKED AS linked_id,
                       m.ID_ACCOUNT_TO AS account_to, m.ID_FUND_MOVEMENT AS fund_movement_id,
                       m.ID_USER_UPDATE AS user_update, m.DT_UPDATE AS dt_update,
                       m.ID_ACT2_IN AS act2_in, m.ACT2_NOT_REQ AS act2_not_req,
                       m.DT_PROMISED AS dt_promised, m.PROMISE_EXECUTED AS promise_executed
                FROM base_account_moves m
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
        if ($id < 1 || !Database::tableExists('base_account_moves')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ACCOUNT AS account_id, ID_TYPE AS type_id,
                    DIRECTION AS direction, SUMM AS summ, DESCR AS descr,
                    DT AS dt, DT_CREATE AS dt_create, ID_USER_CREATE AS user_create,
                    UNCONFIRMED AS unconfirmed, DELETED AS deleted,
                    ID_PAYMENT AS payment_id, ID_SUPPLIER AS supplier_id,
                    ID_USER_PAYMENT AS user_payment, ID_MOVEMENT_LINKED AS linked_id,
                    ID_ACCOUNT_TO AS account_to, ID_FUND_MOVEMENT AS fund_movement_id,
                    ID_USER_UPDATE AS user_update, DT_UPDATE AS dt_update,
                    ID_ACT2_IN AS act2_in, ACT2_NOT_REQ AS act2_not_req,
                    DT_PROMISED AS dt_promised, PROMISE_EXECUTED AS promise_executed
             FROM base_account_moves WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::castRow($row) : null;
    }

    public static function hasMoveForPayment(int $paymentId): bool
    {
        if ($paymentId < 1) {
            return false;
        }
        // Multi-allocation table owned by payments (base_*); soft check keeps createFromPayment idempotent.
        if (Database::tableExists('base_payment_move_links')) {
            $st = Database::pdo()->prepare(
                'SELECT 1 FROM base_payment_move_links WHERE ID_PAYMENT = ? LIMIT 1'
            );
            $st->execute([$paymentId]);
            if ((bool)$st->fetchColumn()) {
                return true;
            }
        }
        if (!Database::tableExists('base_account_moves')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_account_moves WHERE ID_PAYMENT = ? AND DELETED = 0 LIMIT 1'
        );
        $st->execute([$paymentId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Linked ledger move IDs for one payment (non-deleted).
     *
     * @return list<int>
     */
    public static function moveIdsForPayment(int $paymentId): array
    {
        $map = self::moveIdsByPaymentIds([$paymentId]);
        return $map[$paymentId] ?? [];
    }

    /**
     * @param list<int> $paymentIds
     * @return array<int, list<int>> payment_id => move ids (asc)
     */
    public static function moveIdsByPaymentIds(array $paymentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_account_moves')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID, ID_PAYMENT FROM base_account_moves
             WHERE ID_PAYMENT IN ($ph) AND DELETED = 0
             ORDER BY ID ASC"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pid = (int)$row['ID_PAYMENT'];
            $out[$pid][] = (int)$row['ID'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, ?array $existing = null): array
    {
        $accountId = (int)($post['account_id'] ?? 0);
        $typeId = (int)($post['type_id'] ?? 0);
        if ($accountId < 1) {
            throw new InvalidArgumentException(t('ledger.moves.err.account'));
        }
        if ($typeId < 1) {
            throw new InvalidArgumentException(t('ledger.moves.err.type'));
        }
        $type = AccountMoveTypesRepository::find($typeId);
        if (!$type) {
            throw new InvalidArgumentException(t('ledger.moves.err.type'));
        }
        $sum = money_parse((string)($post['summ'] ?? '0'));
        if ($sum <= 0) {
            throw new InvalidArgumentException(t('ledger.moves.err.sum'));
        }
        $dt = self::parseDt((string)($post['dt'] ?? ''));
        if ($dt < 1) {
            $dt = time();
        }
        $directionRaw = $post['direction'] ?? '';
        if ($directionRaw === '' || $directionRaw === null) {
            throw new InvalidArgumentException(t('ledger.moves.err.direction'));
        }
        $direction = (int)$directionRaw;
        if (!in_array($direction, [-1, 1], true)) {
            throw new InvalidArgumentException(t('ledger.moves.err.direction'));
        }

        $paymentId = !empty($type['payment_enabled']) ? max(0, (int)($post['payment_id'] ?? 0)) : 0;
        $userPayment = !empty($type['user_payment_enabled']) ? max(0, (int)($post['user_payment'] ?? 0)) : 0;
        $supplierId = !empty($type['supplier_enabled']) ? max(0, (int)($post['supplier_id'] ?? 0)) : 0;
        $fundMovementId = 0;
        if (!empty($type['fund_enabled']) && Database::tableExists('base_fund_movements')) {
            $fundMovementId = max(0, (int)($post['fund_movement_id'] ?? 0));
        }
        $act2In = 0;
        $act2NotReq = 0;
        if (!empty($type['act_in_enabled']) && ModuleManager::isEnabled('stickers')) {
            $act2In = max(0, (int)($post['act2_in'] ?? 0));
            $act2NotReq = !empty($post['act2_not_req']) ? 1 : 0;
        }

        $dtPromised = self::parsePromisedDt((string)($post['dt_promised'] ?? ''));
        if ($dtPromised > 0) {
            $accDay = self::dayStart($dt);
            $promDay = self::dayStart($dtPromised);
            if ($promDay < $accDay) {
                throw new InvalidArgumentException(t('ledger.moves.err.promised_before_dt'));
            }
        }
        $promiseExecuted = 0;
        if ($dtPromised > 0 && $dtPromised <= time()) {
            $promiseExecuted = !empty($post['promise_executed']) ? 1 : 0;
        }

        return [
            'account_id' => $accountId,
            'type_id' => $typeId,
            'direction' => $direction,
            'summ' => $sum,
            'descr' => trim((string)($post['descr'] ?? '')),
            'dt' => $dt,
            'dt_create' => $existing ? (int)$existing['dt_create'] : time(),
            'user_create' => $existing ? (int)$existing['user_create'] : Auth::userId(),
            'unconfirmed' => !empty($post['unconfirmed']) ? 1 : 0,
            'payment_id' => $paymentId,
            'supplier_id' => $supplierId,
            'user_payment' => $userPayment,
            'fund_movement_id' => $fundMovementId,
            'act2_in' => $act2In,
            'act2_not_req' => $act2NotReq,
            'dt_promised' => $dtPromised,
            'promise_executed' => $promiseExecuted,
            'linked_id' => (int)($existing['linked_id'] ?? 0),
            'account_to' => (int)($existing['account_to'] ?? 0),
            'deleted' => (int)($existing['deleted'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        // Legacy oper schemas often have NOT NULL columns without DEFAULT (strict SQL mode).
        // Send explicit zeros / empty strings for columns that exist.
        $cols = [
            'ID_ACCOUNT', 'ID_TYPE', 'ID_ACT_IN', 'DIRECTION', 'SUMM', 'DESCR',
            'DT', 'DT_CREATE', 'ID_USER_CREATE', 'UNCONFIRMED',
            'ID_PAYMENT', 'ID_SUPPLIER', 'ID_USER_PAYMENT', 'ID_FUND_MOVEMENT',
            'ID_ACT2_IN', 'ACT2_NOT_REQ', 'DT_PROMISED', 'PROMISE_EXECUTED',
            'ID_MOVEMENT_LINKED', 'ID_ACCOUNT_TO', 'DELETED',
        ];
        $params = [
            (int)$data['account_id'],
            (int)$data['type_id'],
            0, // ID_ACT_IN
            (int)$data['direction'],
            $data['summ'],
            (string)$data['descr'],
            (int)$data['dt'],
            (int)$data['dt_create'],
            (int)$data['user_create'],
            (int)$data['unconfirmed'],
            (int)$data['payment_id'],
            (int)$data['supplier_id'],
            (int)$data['user_payment'],
            (int)$data['fund_movement_id'],
            (int)($data['act2_in'] ?? 0),
            (int)($data['act2_not_req'] ?? 0),
            (int)($data['dt_promised'] ?? 0),
            (int)($data['promise_executed'] ?? 0),
            (int)$data['linked_id'],
            (int)$data['account_to'],
            0, // DELETED
        ];
        $optionalZero = [
            'ID_INCASS' => (int)($data['incass_id'] ?? 0),
            'UNTAKEN' => (int)($data['untaken'] ?? 0),
            'DT_TAKE' => (int)($data['dt_take'] ?? 0),
            'ID_USER_TAKE' => (int)($data['user_take'] ?? 0),
            'ID_USER_UPDATE' => (int)($data['user_update'] ?? 0),
            'DT_UPDATE' => (int)($data['dt_update'] ?? 0),
            'COUNT_AS_INDIRECT' => (int)($data['count_as_indirect'] ?? 0),
            'AMORT_REQ' => (int)($data['amort_req'] ?? 0),
            'AMORT_MONTHS' => (int)($data['amort_months'] ?? 0),
            'AMORT_DATE_START' => (int)($data['amort_date_start'] ?? 0),
            'AMORT_DATE_END' => (int)($data['amort_date_end'] ?? 0),
            'ID_ACTIVE' => (int)($data['active_id'] ?? 0),
            'IS_VARIABLE' => (int)($data['is_variable'] ?? 0),
            'ID_COMMENT' => (int)($data['comment_id'] ?? 0),
        ];
        foreach ($optionalZero as $col => $val) {
            if (Database::columnExists('base_account_moves', $col)) {
                $cols[] = $col;
                $params[] = $val;
            }
        }
        $optionalEmpty = [
            'TAKE_DESCR' => (string)($data['take_descr'] ?? ''),
            'TYPES_PROC' => (string)($data['types_proc'] ?? ''),
        ];
        foreach ($optionalEmpty as $col => $val) {
            if (Database::columnExists('base_account_moves', $col)) {
                $cols[] = $col;
                $params[] = $val;
            }
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_account_moves (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')'
        );
        $st->execute($params);
        $id = (int)Database::pdo()->lastInsertId();
        LedgerReconciliationsRepository::refreshTodaySystemBalance((int)$data['account_id']);
        return $id;
    }

    /** True when move is one side of a paired transfer. */
    public static function isTransferMove(array $row): bool
    {
        return (int)($row['linked_id'] ?? 0) > 0;
    }

    /**
     * Resolve transfer pair for either side id.
     * @return array{from:array<string,mixed>,to:array<string,mixed>}|null
     */
    public static function findTransferPair(int $id): ?array
    {
        $row = self::find($id);
        if (!$row || (int)($row['deleted'] ?? 0) > 0) {
            return null;
        }
        $linked = (int)($row['linked_id'] ?? 0);
        if ($linked < 1) {
            return null;
        }
        $twin = self::find($linked);
        if (!$twin || (int)($twin['deleted'] ?? 0) > 0) {
            return null;
        }
        // Debit (direction < 0) = списание / from; credit = назначение / to.
        if ((int)$row['direction'] < 0) {
            return ['from' => $row, 'to' => $twin];
        }
        if ((int)$twin['direction'] < 0) {
            return ['from' => $twin, 'to' => $row];
        }
        // Ambiguous legacy pair: smaller id was created as "from" in createTransfer.
        if ((int)$row['id'] <= (int)$twin['id']) {
            return ['from' => $row, 'to' => $twin];
        }
        return ['from' => $twin, 'to' => $row];
    }

    /**
     * Update both sides of a transfer (keeps pairing).
     * accountFrom = debit (списание), accountTo = credit (назначение). Directions always −1 / +1.
     * @return array{from_id:int,to_id:int}
     */
    public static function updateTransfer(
        int $eitherId,
        int $typeId,
        int $accountFrom,
        int $accountTo,
        float $summ,
        int $dt,
        string $descr
    ): array {
        $pair = self::findTransferPair($eitherId);
        if ($pair === null) {
            throw new InvalidArgumentException(t('ledger.moves.err.not_found', ['id' => $eitherId]));
        }
        if ($accountFrom < 1 || $accountTo < 1) {
            throw new InvalidArgumentException(t('ledger.moves.err.account_to'));
        }
        if ($accountFrom === $accountTo) {
            throw new InvalidArgumentException(t('ledger.moves.err.same_account'));
        }
        if ($summ <= 0) {
            throw new InvalidArgumentException(t('ledger.moves.err.sum'));
        }
        $type = AccountMoveTypesRepository::find($typeId);
        if (!$type || empty($type['linked_enabled'])) {
            throw new InvalidArgumentException(t('ledger.moves.err.type'));
        }
        if ($dt < 1) {
            $dt = time();
        }
        // Ignore type.direction for legs — transfer UI is always debit → credit.
        $dirFrom = -1;
        $dirTo = 1;
        $fromId = (int)$pair['from']['id'];
        $toId = (int)$pair['to']['id'];
        $uid = Auth::userId();
        $now = time();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'UPDATE base_account_moves SET
                 ID_ACCOUNT = ?, ID_TYPE = ?, DIRECTION = ?, SUMM = ?, DESCR = ?, DT = ?,
                 ID_ACCOUNT_TO = ?, UNCONFIRMED = 0,
                 ID_USER_UPDATE = ?, DT_UPDATE = ?
                 WHERE ID = ? AND DELETED = 0'
            );
            $st->execute([$accountFrom, $typeId, $dirFrom, $summ, $descr, $dt, $accountTo, $uid, $now, $fromId]);
            $st->execute([$accountTo, $typeId, $dirTo, $summ, $descr, $dt, $accountFrom, $uid, $now, $toId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        LedgerReconciliationsRepository::refreshTodaySystemBalance($accountFrom);
        LedgerReconciliationsRepository::refreshTodaySystemBalance($accountTo);
        $oldFrom = (int)$pair['from']['account_id'];
        $oldTo = (int)$pair['to']['account_id'];
        if ($oldFrom !== $accountFrom && $oldFrom > 0) {
            LedgerReconciliationsRepository::refreshTodaySystemBalance($oldFrom);
        }
        if ($oldTo !== $accountTo && $oldTo > 0) {
            LedgerReconciliationsRepository::refreshTodaySystemBalance($oldTo);
        }
        return ['from_id' => $fromId, 'to_id' => $toId];
    }

    /** Append a note to the end of DESCR (keeps existing purpose). */
    public static function appendDescr(int $id, string $note): void
    {
        $note = trim($note);
        if ($id < 1 || $note === '' || !Database::tableExists('base_account_moves')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_account_moves
             SET DESCR = TRIM(CONCAT(IFNULL(DESCR, \'\'), ?, ?)),
                 ID_USER_UPDATE = ?, DT_UPDATE = ?
             WHERE ID = ? AND DELETED = 0'
        );
        $st->execute(["\n", $note, Auth::userId() ?: 0, time(), $id]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_account_moves SET
             ID_ACCOUNT = ?, ID_TYPE = ?, DIRECTION = ?, SUMM = ?, DESCR = ?, DT = ?,
             UNCONFIRMED = ?, ID_PAYMENT = ?, ID_SUPPLIER = ?, ID_USER_PAYMENT = ?,
             ID_FUND_MOVEMENT = ?, ID_ACT2_IN = ?, ACT2_NOT_REQ = ?,
             DT_PROMISED = ?, PROMISE_EXECUTED = ?,
             ID_USER_UPDATE = ?, DT_UPDATE = ?
             WHERE ID = ? AND DELETED = 0'
        );
        $st->execute([
            $data['account_id'], $data['type_id'], $data['direction'], $data['summ'],
            $data['descr'], $data['dt'], $data['unconfirmed'], $data['payment_id'],
            $data['supplier_id'], $data['user_payment'], $data['fund_movement_id'],
            (int)($data['act2_in'] ?? 0), (int)($data['act2_not_req'] ?? 0),
            (int)($data['dt_promised'] ?? 0), (int)($data['promise_executed'] ?? 0),
            Auth::userId(), time(), $id,
        ]);
        LedgerReconciliationsRepository::refreshTodaySystemBalance((int)$data['account_id']);
    }

    /**
     * Create paired transfer moves.
     * @return array{from_id:int,to_id:int}
     */
    public static function createTransfer(
        int $typeId,
        int $accountFrom,
        int $accountTo,
        float $summ,
        int $dt,
        string $descr
    ): array {
        if ($accountFrom < 1 || $accountTo < 1) {
            throw new InvalidArgumentException(t('ledger.moves.err.account_to'));
        }
        if ($accountFrom === $accountTo) {
            throw new InvalidArgumentException(t('ledger.moves.err.same_account'));
        }
        if ($summ <= 0) {
            throw new InvalidArgumentException(t('ledger.moves.err.sum'));
        }
        $type = AccountMoveTypesRepository::find($typeId);
        if (!$type || empty($type['linked_enabled'])) {
            throw new InvalidArgumentException(t('ledger.moves.err.type'));
        }
        if ($dt < 1) {
            $dt = time();
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $uid = Auth::userId();
            $now = time();
            // Always debit source / credit destination (ignore type.direction — it swapped UI).
            $dirFrom = -1;
            $dirTo = 1;

            $fromId = self::create([
                'account_id' => $accountFrom,
                'type_id' => $typeId,
                'direction' => $dirFrom,
                'summ' => $summ,
                'descr' => $descr,
                'dt' => $dt,
                'dt_create' => $now,
                'user_create' => $uid,
                'unconfirmed' => 0,
                'payment_id' => 0,
                'supplier_id' => 0,
                'user_payment' => 0,
                'fund_movement_id' => 0,
                'act2_in' => 0,
                'act2_not_req' => 0,
                'dt_promised' => 0,
                'promise_executed' => 0,
                'linked_id' => 0,
                'account_to' => $accountTo,
                'deleted' => 0,
            ]);
            $toId = self::create([
                'account_id' => $accountTo,
                'type_id' => $typeId,
                'direction' => $dirTo,
                'summ' => $summ,
                'descr' => $descr,
                'dt' => $dt,
                'dt_create' => $now,
                'user_create' => $uid,
                'unconfirmed' => 0,
                'payment_id' => 0,
                'supplier_id' => 0,
                'user_payment' => 0,
                'fund_movement_id' => 0,
                'act2_in' => 0,
                'act2_not_req' => 0,
                'dt_promised' => 0,
                'promise_executed' => 0,
                'linked_id' => $fromId,
                'account_to' => $accountFrom,
                'deleted' => 0,
            ]);
            $pdo->prepare('UPDATE base_account_moves SET ID_MOVEMENT_LINKED = ? WHERE ID = ?')
                ->execute([$toId, $fromId]);
            $pdo->commit();
            return ['from_id' => $fromId, 'to_id' => $toId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Auto-DPS from staff payment (oper do=3).
     * @param array<string,mixed> $payment row from PaymentsRepository::find
     * @return int move id or 0 if skipped
     */
    public static function createFromPayment(array $payment): int
    {
        $paymentId = (int)($payment['id'] ?? 0);
        $account = (int)($payment['account'] ?? 0);
        $direction = (int)($payment['direction'] ?? 0);
        $sum = (float)($payment['sum'] ?? 0);
        if ($paymentId < 1 || $account < 1 || $direction >= 0 || $sum == 0.0) {
            return 0;
        }
        if (self::hasMoveForPayment($paymentId)) {
            return 0;
        }

        $type = AccountMoveTypesRepository::findFotByPaymentAccount($account);
        if (!$type) {
            throw new RuntimeException(t('ledger.err.fot_type_missing', ['account' => $account]));
        }
        $accountId = (int)($payment['ledger_account_override'] ?? 0);
        if ($accountId < 1) {
            $accountId = (int)($type['account_id'] ?? 0);
        }
        if ($accountId < 1) {
            throw new RuntimeException('Type has no default account: #' . (int)$type['id']);
        }
        $dir = (int)($type['direction'] ?? -1);
        if ($dir === 0) {
            $dir = -1;
        }
        $dt = (int)($payment['dt'] ?? time());
        $payDescr = (string)($payment['descr'] ?? '');
        if (class_exists('DayPayroll', false) && DayPayroll::isPayoutDescr($payDescr)) {
            $payDescr = DayPayroll::maskDescr($payDescr);
        } elseif (method_exists(self::class, 'descrForList')) {
            $payDescr = self::descrForList($payDescr);
        }
        $descr = 'Авто-ДПС из платежа #' . $paymentId;
        if ($payDescr !== '') {
            $descr .= ': ' . mb_strimwidth($payDescr, 0, 120, '…');
        }

        $userCreate = Auth::userId();
        if ($userCreate < 1) {
            $userCreate = (int)($payment['user_id'] ?? 0);
        }

        return self::create([
            'account_id' => $accountId,
            'type_id' => (int)$type['id'],
            'direction' => $dir,
            'summ' => abs($sum),
            'descr' => $descr,
            'dt' => $dt,
            'dt_create' => time(),
            'user_create' => $userCreate,
            'unconfirmed' => 0,
            'payment_id' => $paymentId,
            'supplier_id' => 0,
            'user_payment' => (int)($payment['user_id'] ?? 0),
            'fund_movement_id' => 0,
            'linked_id' => 0,
            'account_to' => 0,
            'deleted' => 0,
            'incass_id' => 0,
        ]);
    }

    /** @param array<string,mixed>|null $type */
    public static function fotKind(?array $type): int
    {
        if (!is_array($type)) {
            return 0;
        }
        return AccountMoveTypesRepository::normalizeFotKind((int)($type['fot_kind'] ?? 0));
    }

    /** @param array<string,mixed>|null $type */
    public static function isFotType(?array $type): bool
    {
        return self::fotKind($type) > 0;
    }

    /**
     * Duplicate one move as a new draft dated now; opens for edit after bulk redirect.
     * Does not copy linked twin pairing.
     */
    public static function copyAndEnter(int $id): int
    {
        $src = self::find($id);
        if (!$src || (int)($src['deleted'] ?? 0) > 0) {
            throw new InvalidArgumentException(t('ledger.moves.err.not_found', ['id' => $id]));
        }
        $now = time();
        $mark = t('ledger.moves.copy_mark');
        $descr = trim((string)($src['descr'] ?? ''));
        if ($descr === '') {
            $descr = $mark;
        } elseif (mb_stripos($descr, $mark) === false) {
            $descr = $mark . "\n" . $descr;
        }
        return self::create([
            'account_id' => (int)$src['account_id'],
            'type_id' => (int)$src['type_id'],
            'direction' => (int)$src['direction'],
            'summ' => (float)$src['summ'],
            'descr' => $descr,
            'dt' => $now,
            'dt_create' => $now,
            'user_create' => Auth::userId(),
            'unconfirmed' => (int)($src['unconfirmed'] ?? 1),
            'payment_id' => (int)($src['payment_id'] ?? 0),
            'supplier_id' => (int)($src['supplier_id'] ?? 0),
            'user_payment' => (int)($src['user_payment'] ?? 0),
            'fund_movement_id' => (int)($src['fund_movement_id'] ?? 0),
            'act2_in' => (int)($src['act2_in'] ?? 0),
            'act2_not_req' => (int)($src['act2_not_req'] ?? 0),
            'dt_promised' => (int)($src['dt_promised'] ?? 0),
            'promise_executed' => (int)($src['promise_executed'] ?? 0),
            'linked_id' => 0,
            'account_to' => 0,
            'deleted' => 0,
        ]);
    }

    /**
     * @param list<int> $ids
     * @return int
     */
    public static function setUnconfirmedByIds(array $ids, int $unconfirmed): int
    {
        $ids = self::sanitizeIds($ids);
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$unconfirmed ? 1 : 0], $ids);
        $st = Database::pdo()->prepare(
            "UPDATE base_account_moves SET UNCONFIRMED = ? WHERE ID IN ($ph) AND DELETED = 0"
        );
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * Soft-delete; cascades to linked twin.
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function softDeleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        $deletedIds = [];
        foreach (self::sanitizeIds($ids) as $id) {
            $row = self::find($id);
            if (!$row || (int)$row['deleted'] > 0) {
                continue;
            }
            $pdo = Database::pdo();
            $pdo->prepare('UPDATE base_account_moves SET DELETED = 1, ID_USER_UPDATE = ?, DT_UPDATE = ? WHERE ID = ?')
                ->execute([Auth::userId(), time(), $id]);
            $deletedIds[] = $id;
            $linked = (int)$row['linked_id'];
            if ($linked > 0) {
                $pdo->prepare('UPDATE base_account_moves SET DELETED = 1, ID_USER_UPDATE = ?, DT_UPDATE = ? WHERE ID = ?')
                    ->execute([Auth::userId(), time(), $linked]);
                $deletedIds[] = $linked;
                $twin = self::find($linked);
                if ($twin) {
                    LedgerReconciliationsRepository::refreshTodaySystemBalance((int)$twin['account_id']);
                }
            }
            LedgerReconciliationsRepository::refreshTodaySystemBalance((int)$row['account_id']);
            $done++;
        }
        if ($deletedIds !== []) {
            EventBus::emit('ledger.moves.deleted', [
                'ids' => array_values(array_unique($deletedIds)),
            ]);
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    public static function directionLabel(int $dir): string
    {
        $key = 'ledger.dir.' . $dir;
        $label = t($key);
        return $label !== $key ? $label : (string)$dir;
    }

    /**
     * Attach receipt act (base_acts2_in) to moves that still need one (oper setAct2).
     *
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function attachAct2In(array $ids, int $actId): array
    {
        $done = 0;
        $skipped = [];
        $actId = max(0, $actId);
        if ($actId < 1 || !class_exists('Acts2InRepository') || !Acts2InRepository::find($actId)) {
            throw new InvalidArgumentException(t('ledger.moves.attach_act.err.act'));
        }
        foreach (self::sanitizeIds($ids) as $id) {
            $row = self::find($id);
            if (!$row || (int)$row['deleted'] > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('ledger.moves.err.not_found', ['id' => $id])];
                continue;
            }
            if ((int)($row['act2_in'] ?? 0) > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('ledger.moves.attach_act.err.already')];
                continue;
            }
            if (!empty($row['act2_not_req'])) {
                $skipped[] = ['id' => $id, 'reason' => t('ledger.moves.attach_act.err.not_req')];
                continue;
            }
            $type = AccountMoveTypesRepository::find((int)$row['type_id']);
            if (!$type || empty($type['act_in_enabled'])) {
                $skipped[] = ['id' => $id, 'reason' => t('ledger.moves.attach_act.err.type')];
                continue;
            }
            $st = Database::pdo()->prepare(
                'UPDATE base_account_moves
                 SET ID_ACT2_IN = ?, ID_USER_UPDATE = ?, DT_UPDATE = ?
                 WHERE ID = ? AND DELETED = 0 AND ID_ACT2_IN = 0 AND ACT2_NOT_REQ = 0'
            );
            $st->execute([$actId, Auth::userId(), time(), $id]);
            if ($st->rowCount() > 0) {
                $done++;
            } else {
                $skipped[] = ['id' => $id, 'reason' => t('ledger.moves.attach_act.err.already')];
            }
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    private static function parseDt(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int)$raw;
        }
        $ts = strtotime(str_replace('T', ' ', $raw));
        return $ts !== false ? $ts : 0;
    }

    /** Promised payout day; empty / epoch → off (oper 01.01.70). */
    private static function parsePromisedDt(string $raw): int
    {
        $ts = self::parseDt($raw);
        if ($ts < 1) {
            return 0;
        }
        // Treat unix epoch calendar day as "off".
        if ((int)date('Y', $ts) <= 1970) {
            return 0;
        }
        return self::dayStart($ts);
    }

    private static function dayStart(int $ts): int
    {
        if ($ts < 1) {
            return 0;
        }
        return mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts)) ?: $ts;
    }

    /**
     * List/display description without machine tags (oper day-payroll PIN/ADMIN tags).
     * Full DESCR stays intact on edit.
     */
    public static function descrForList(string $descr): string
    {
        $descr = (string)preg_replace('/\s*\[\[PIN:\d{4}\]\]/', '', $descr);
        $descr = (string)preg_replace('/\s*\[\[ADMIN:\d+\]\]/', '', $descr);
        return trim($descr);
    }

    /** @param array<string,mixed> $row */
    private static function castRow(array $row): array
    {
        foreach ([
            'id', 'account_id', 'type_id', 'direction', 'dt', 'dt_create', 'user_create',
            'unconfirmed', 'deleted', 'payment_id', 'supplier_id', 'user_payment',
            'linked_id', 'account_to', 'fund_movement_id', 'user_update', 'dt_update',
            'act2_in', 'act2_not_req', 'dt_promised', 'promise_executed',
        ] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int)$row[$k];
            }
        }
        $row['summ'] = (float)($row['summ'] ?? 0);
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
