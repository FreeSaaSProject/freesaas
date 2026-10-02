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
            'account' => 'm.ID_ACCOUNT',
            'type' => 'm.ID_TYPE',
            'sum' => 'm.SUMM',
        ];
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
     * True if an active past reconciliation locks edits for this account at $dt.
     */
    public static function isReconLocked(int $accountId, int $dt): bool
    {
        if ($accountId < 1 || !Database::tableExists('base_account_reconciliations')) {
            return false;
        }
        $todayStart = mktime(0, 0, 0) ?: time();
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_account_reconciliations
             WHERE ID_ACCOUNT = ? AND DISABLED = 0
               AND DT_RECONCILIATION < ?
               AND DT_RECONCILIATION >= ?
             LIMIT 1'
        );
        // Lock if recon date is on/after move day and before today
        $moveDay = mktime(0, 0, 0, (int)date('n', $dt), (int)date('j', $dt), (int)date('Y', $dt)) ?: $dt;
        $st->execute([$accountId, $todayStart, $moveDay]);
        return (bool)$st->fetchColumn();
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

        $sqlWhere = implode(' AND ', $where);
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
                       m.ID_ACCOUNT_TO AS account_to, m.ID_FUND_MOVEMENT AS fund_movement_id
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
                    ID_USER_UPDATE AS user_update, DT_UPDATE AS dt_update
             FROM base_account_moves WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::castRow($row) : null;
    }

    public static function hasMoveForPayment(int $paymentId): bool
    {
        if ($paymentId < 1 || !Database::tableExists('base_account_moves')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_account_moves WHERE ID_PAYMENT = ? AND DELETED = 0 LIMIT 1'
        );
        $st->execute([$paymentId]);
        return (bool)$st->fetchColumn();
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
        $sum = (float)str_replace(',', '.', (string)($post['summ'] ?? '0'));
        if ($sum <= 0) {
            throw new InvalidArgumentException(t('ledger.moves.err.sum'));
        }
        $dt = self::parseDt((string)($post['dt'] ?? ''));
        if ($dt < 1) {
            $dt = time();
        }
        $direction = (int)($type['direction'] ?? 0);
        if (isset($post['direction']) && $post['direction'] !== '') {
            $d = (int)$post['direction'];
            if (in_array($d, [-1, 1], true)) {
                $direction = $d;
            }
        }
        if ($direction === 0) {
            $direction = -1;
        }

        self::assertNotLocked($accountId, $dt, $existing);

        $paymentId = !empty($type['payment_enabled']) ? max(0, (int)($post['payment_id'] ?? 0)) : 0;
        $userPayment = !empty($type['user_payment_enabled']) ? max(0, (int)($post['user_payment'] ?? 0)) : 0;
        $supplierId = !empty($type['supplier_enabled']) ? max(0, (int)($post['supplier_id'] ?? 0)) : 0;
        $fundMovementId = 0;
        if (!empty($type['fund_enabled']) && Database::tableExists('base_fund_movements')) {
            $fundMovementId = max(0, (int)($post['fund_movement_id'] ?? 0));
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
            'linked_id' => (int)($existing['linked_id'] ?? 0),
            'account_to' => (int)($existing['account_to'] ?? 0),
            'deleted' => (int)($existing['deleted'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_account_moves
             (ID_ACCOUNT, ID_TYPE, DIRECTION, SUMM, DESCR, DT, DT_CREATE, ID_USER_CREATE,
              UNCONFIRMED, ID_PAYMENT, ID_SUPPLIER, ID_USER_PAYMENT, ID_FUND_MOVEMENT,
              ID_MOVEMENT_LINKED, ID_ACCOUNT_TO, DELETED)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $st->execute([
            $data['account_id'], $data['type_id'], $data['direction'], $data['summ'],
            $data['descr'], $data['dt'], $data['dt_create'], $data['user_create'],
            $data['unconfirmed'], $data['payment_id'], $data['supplier_id'],
            $data['user_payment'], $data['fund_movement_id'],
            $data['linked_id'], $data['account_to'],
        ]);
        $id = (int)Database::pdo()->lastInsertId();
        LedgerReconciliationsRepository::refreshTodaySystemBalance((int)$data['account_id']);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_account_moves SET
             ID_ACCOUNT = ?, ID_TYPE = ?, DIRECTION = ?, SUMM = ?, DESCR = ?, DT = ?,
             UNCONFIRMED = ?, ID_PAYMENT = ?, ID_SUPPLIER = ?, ID_USER_PAYMENT = ?,
             ID_FUND_MOVEMENT = ?, ID_USER_UPDATE = ?, DT_UPDATE = ?
             WHERE ID = ? AND DELETED = 0'
        );
        $st->execute([
            $data['account_id'], $data['type_id'], $data['direction'], $data['summ'],
            $data['descr'], $data['dt'], $data['unconfirmed'], $data['payment_id'],
            $data['supplier_id'], $data['user_payment'], $data['fund_movement_id'],
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
        self::assertNotLocked($accountFrom, $dt, null);
        self::assertNotLocked($accountTo, $dt, null);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $uid = Auth::userId();
            $now = time();
            $dirFrom = (int)($type['direction'] ?? -1);
            if ($dirFrom === 0) {
                $dirFrom = -1;
            }
            $dirTo = -$dirFrom;

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

        $typeName = self::fotTypeNameForPayment($account, (int)($payment['movement_linked'] ?? 0) > 0);
        $type = AccountMoveTypesRepository::findByName($typeName);
        if (!$type) {
            throw new RuntimeException('Move type not found: ' . $typeName);
        }
        $accountId = (int)($type['account_id'] ?? 0);
        if ($accountId < 1) {
            throw new RuntimeException('Type has no default account: ' . $typeName);
        }
        $dir = (int)($type['direction'] ?? -1);
        if ($dir === 0) {
            $dir = -1;
        }
        $dt = (int)($payment['dt'] ?? time());
        $descr = 'Авто-ДПС из платежа #' . $paymentId;
        if ((string)($payment['descr'] ?? '') !== '') {
            $descr .= ': ' . mb_strimwidth((string)$payment['descr'], 0, 120, '…');
        }

        return self::create([
            'account_id' => $accountId,
            'type_id' => (int)$type['id'],
            'direction' => $dir,
            'summ' => abs($sum),
            'descr' => $descr,
            'dt' => $dt,
            'dt_create' => time(),
            'user_create' => Auth::userId(),
            'unconfirmed' => 0,
            'payment_id' => $paymentId,
            'supplier_id' => 0,
            'user_payment' => (int)($payment['user_id'] ?? 0),
            'fund_movement_id' => 0,
            'linked_id' => 0,
            'account_to' => 0,
            'deleted' => 0,
        ]);
    }

    public static function fotTypeNameForPayment(int $account, bool $isTransfer): string
    {
        if ($account === 2 && $isTransfer) {
            return (string)Config::get('ledger', 'fot_cash_transfer_type', 'ФОТ РФ');
        }
        if ($account === 2) {
            return (string)Config::get('ledger', 'fot_cash_type', 'ФОТ Нал');
        }
        return (string)Config::get('ledger', 'fot_bank_type', 'ФОТ');
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
        foreach (self::sanitizeIds($ids) as $id) {
            $row = self::find($id);
            if (!$row || (int)$row['deleted'] > 0) {
                continue;
            }
            if (self::isReconLocked((int)$row['account_id'], (int)$row['dt'])) {
                $skipped[] = ['id' => $id, 'reason' => t('ledger.moves.err.recon_locked')];
                continue;
            }
            $pdo = Database::pdo();
            $pdo->prepare('UPDATE base_account_moves SET DELETED = 1, ID_USER_UPDATE = ?, DT_UPDATE = ? WHERE ID = ?')
                ->execute([Auth::userId(), time(), $id]);
            $linked = (int)$row['linked_id'];
            if ($linked > 0) {
                $pdo->prepare('UPDATE base_account_moves SET DELETED = 1, ID_USER_UPDATE = ?, DT_UPDATE = ? WHERE ID = ?')
                    ->execute([Auth::userId(), time(), $linked]);
                $twin = self::find($linked);
                if ($twin) {
                    LedgerReconciliationsRepository::refreshTodaySystemBalance((int)$twin['account_id']);
                }
            }
            LedgerReconciliationsRepository::refreshTodaySystemBalance((int)$row['account_id']);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    public static function directionLabel(int $dir): string
    {
        $key = 'ledger.dir.' . $dir;
        $label = t($key);
        return $label !== $key ? $label : (string)$dir;
    }

    /** @param array<string,mixed>|null $existing */
    private static function assertNotLocked(int $accountId, int $dt, ?array $existing): void
    {
        if (self::isReconLocked($accountId, $dt)) {
            throw new InvalidArgumentException(t('ledger.moves.err.recon_locked'));
        }
        if ($existing) {
            $oldAccount = (int)$existing['account_id'];
            $oldDt = (int)$existing['dt'];
            if ($oldAccount !== $accountId || $oldDt !== $dt) {
                if (self::isReconLocked($oldAccount, $oldDt)) {
                    throw new InvalidArgumentException(t('ledger.moves.err.recon_locked'));
                }
            }
        }
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
        $ts = strtotime($raw);
        return $ts !== false ? $ts : 0;
    }

    /** @param array<string,mixed> $row */
    private static function castRow(array $row): array
    {
        foreach ([
            'id', 'account_id', 'type_id', 'direction', 'dt', 'dt_create', 'user_create',
            'unconfirmed', 'deleted', 'payment_id', 'supplier_id', 'user_payment',
            'linked_id', 'account_to', 'fund_movement_id', 'user_update', 'dt_update',
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
