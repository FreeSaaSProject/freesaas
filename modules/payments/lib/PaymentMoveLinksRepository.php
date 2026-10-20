<?php
declare(strict_types=1);

/** Multi-allocation: many staff payments ↔ one ledger move. */
final class PaymentMoveLinksRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_payment_move_links');
    }

    public static function moveIdForPayment(int $paymentId): int
    {
        if ($paymentId < 1 || !self::ready()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_MOVE FROM base_payment_move_links WHERE ID_PAYMENT = ? LIMIT 1'
        );
        $st->execute([$paymentId]);
        return (int)$st->fetchColumn();
    }

    public static function isPaymentLinked(int $paymentId): bool
    {
        if ($paymentId < 1) {
            return false;
        }
        if (self::moveIdForPayment($paymentId) > 0) {
            return true;
        }
        return class_exists('LedgerMovesRepository', false)
            && LedgerMovesRepository::hasMoveForPayment($paymentId);
    }

    /**
     * @return list<array{id:int,payment_id:int,move_id:int,summ:float,dt_create:int}>
     */
    public static function forMove(int $moveId): array
    {
        if ($moveId < 1 || !self::ready()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_PAYMENT AS payment_id, ID_MOVE AS move_id,
                    SUMM AS summ, DT_CREATE AS dt_create
             FROM base_payment_move_links
             WHERE ID_MOVE = ?
             ORDER BY ID ASC'
        );
        $st->execute([$moveId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'payment_id' => (int)$r['payment_id'],
                'move_id' => (int)$r['move_id'],
                'summ' => (float)$r['summ'],
                'dt_create' => (int)$r['dt_create'],
            ];
        }
        return $out;
    }

    /**
     * Staff user ids linked to moves via payment allocations (for list acronyms).
     *
     * @param list<int> $moveIds
     * @return array<int, list<int>> move_id => unique user ids
     */
    public static function staffUserIdsByMoveIds(array $moveIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $moveIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !self::ready() || !Database::tableExists('base_payments')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT l.ID_MOVE AS move_id, p.ID_USER AS user_id
             FROM base_payment_move_links l
             INNER JOIN base_payments p ON p.ID = l.ID_PAYMENT
             INNER JOIN base_account_moves m ON m.ID = l.ID_MOVE AND m.DELETED = 0
             WHERE l.ID_MOVE IN ($ph)
             ORDER BY l.ID ASC"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $mid = (int)$row['move_id'];
            $uid = (int)$row['user_id'];
            if ($mid < 1 || $uid < 1) {
                continue;
            }
            if (!isset($out[$mid])) {
                $out[$mid] = [];
            }
            if (!in_array($uid, $out[$mid], true)) {
                $out[$mid][] = $uid;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $moveIds
     * @return array<int,string> move_id => HTML of staff acronym badges
     */
    public static function staffBadgesHtmlByMoveIds(array $moveIds): array
    {
        $byMove = self::staffUserIdsByMoveIds($moveIds);
        $out = [];
        foreach ($byMove as $mid => $userIds) {
            $parts = [];
            foreach ($userIds as $uid) {
                $parts[] = UsersRepository::badgeHtml((int)$uid, true, true);
            }
            if ($parts !== []) {
                $out[(int)$mid] = '<span class="payments-move-staff" title="'
                    . e(t('payments.alloc.linked_staff')) . '">'
                    . implode(' ', $parts) . '</span>';
            }
        }
        return $out;
    }

    /**
     * Remove allocations pointing at missing or soft-deleted ledger moves.
     *
     * @param list<int> $paymentIds empty = all orphan links
     * @return int deleted link rows
     */
    public static function pruneOrphanLinks(array $paymentIds = []): int
    {
        if (!self::ready() || !Database::tableExists('base_account_moves')) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds), static function (int $id): bool {
            return $id > 0;
        })));
        $sql = 'DELETE l FROM base_payment_move_links l
                LEFT JOIN base_account_moves m ON m.ID = l.ID_MOVE AND m.DELETED = 0
                WHERE m.ID IS NULL';
        $params = [];
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND l.ID_PAYMENT IN ($ph)";
            $params = $ids;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * @param list<int> $paymentIds
     * @return array<int, list<int>> payment_id => move ids
     */
    public static function moveIdsByPaymentIds(array $paymentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds), static function (int $id): bool {
            return $id > 0;
        })));
        $out = [];
        if ($ids !== [] && self::ready()) {
            self::pruneOrphanLinks($ids);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = Database::pdo()->prepare(
                "SELECT l.ID_PAYMENT AS payment_id, l.ID_MOVE AS move_id
                 FROM base_payment_move_links l
                 INNER JOIN base_account_moves m ON m.ID = l.ID_MOVE AND m.DELETED = 0
                 WHERE l.ID_PAYMENT IN ($ph)
                 ORDER BY l.ID ASC"
            );
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pid = (int)$row['payment_id'];
                $mid = (int)$row['move_id'];
                if ($mid > 0) {
                    $out[$pid][] = $mid;
                }
            }
        }
        if (class_exists('LedgerMovesRepository', false)) {
            foreach (LedgerMovesRepository::moveIdsByPaymentIds($ids) as $pid => $mids) {
                foreach ($mids as $mid) {
                    if (!isset($out[$pid]) || !in_array((int)$mid, $out[$pid], true)) {
                        $out[$pid][] = (int)$mid;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Outgoing settlements without a ledger link yet.
     * $account: 1 = bank (FOT_KIND=1), 2 = cash (FOT_KIND=2).
     *
     * @return list<array<string,mixed>>
     */
    public static function hangingPayments(int $limit = 150, int $account = 1): array
    {
        if (!Database::tableExists('base_payments')) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        if (!in_array($account, [1, 2], true)) {
            $account = 1;
        }
        $where = ['CAST(p.DIRECTION AS SIGNED) < 0', 'p.ACCOUNT = ?'];
        $params = [$account];
        if (self::ready()) {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM base_payment_move_links l WHERE l.ID_PAYMENT = p.ID
            )';
        }
        if (Database::tableExists('base_account_moves')
            && Database::columnExists('base_account_moves', 'ID_PAYMENT')) {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM base_account_moves m
                WHERE m.ID_PAYMENT = p.ID AND m.DELETED = 0
            )';
        }
        $sql = 'SELECT p.ID AS id, p.ID_USER AS user_id, p.DT AS dt, p.DESCR AS descr,
                       p.DIRECTION AS direction, p.SUM AS sum, p.ACCOUNT AS account
                FROM base_payments p
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.DT DESC, p.ID DESC
                LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'dt' => (int)$row['dt'],
                'descr' => (string)($row['descr'] ?? ''),
                'direction' => (int)$row['direction'],
                'sum' => (float)$row['sum'],
                'account' => (int)$row['account'],
            ];
        }
        return $out;
    }

    public static function link(int $paymentId, int $moveId, ?float $summ = null): void
    {
        if ($paymentId < 1 || $moveId < 1 || !self::ready()) {
            throw new InvalidArgumentException(t('payments.alloc.err.bad'));
        }
        $pay = PaymentsRepository::find($paymentId);
        if (!$pay) {
            throw new InvalidArgumentException(t('payments.alloc.err.payment'));
        }
        $existing = self::moveIdForPayment($paymentId);
        if ($existing > 0 && $existing !== $moveId) {
            throw new InvalidArgumentException(t('payments.alloc.err.already', ['id' => $paymentId, 'move' => $existing]));
        }
        $amount = $summ !== null ? abs($summ) : abs((float)$pay['sum']);
        Database::pdo()->prepare(
            'INSERT INTO base_payment_move_links (ID_PAYMENT, ID_MOVE, SUMM, DT_CREATE)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ID_MOVE = VALUES(ID_MOVE), SUMM = VALUES(SUMM)'
        )->execute([$paymentId, $moveId, $amount, time()]);

        self::syncLegacyMovePaymentId($moveId);
    }

    public static function unlink(int $paymentId, int $moveId): void
    {
        if ($paymentId < 1 || $moveId < 1 || !self::ready()) {
            return;
        }
        Database::pdo()->prepare(
            'DELETE FROM base_payment_move_links WHERE ID_PAYMENT = ? AND ID_MOVE = ?'
        )->execute([$paymentId, $moveId]);
        self::syncLegacyMovePaymentId($moveId);
    }

    /**
     * Drop all allocations for soft-deleted (or removed) ledger moves.
     *
     * @param list<int> $moveIds
     */
    public static function unlinkByMoveIds(array $moveIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $moveIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !self::ready()) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "DELETE FROM base_payment_move_links WHERE ID_MOVE IN ($ph)"
        );
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * @param list<int> $paymentIds
     * @return array{linked:int,skipped:list<string>}
     */
    public static function linkMany(int $moveId, array $paymentIds): array
    {
        $linked = 0;
        $skipped = [];
        foreach (array_values(array_unique(array_map('intval', $paymentIds))) as $pid) {
            if ($pid < 1) {
                continue;
            }
            try {
                self::link($pid, $moveId);
                $linked++;
            } catch (Throwable $e) {
                $skipped[] = '#' . $pid . ': ' . $e->getMessage();
            }
        }
        return ['linked' => $linked, 'skipped' => $skipped];
    }

    /** Keep moves.ID_PAYMENT = first linked payment (0 if none) for legacy filters. */
    private static function syncLegacyMovePaymentId(int $moveId): void
    {
        if ($moveId < 1
            || !Database::tableExists('base_account_moves')
            || !Database::columnExists('base_account_moves', 'ID_PAYMENT')) {
            return;
        }
        $links = self::forMove($moveId);
        $primary = $links !== [] ? (int)$links[0]['payment_id'] : 0;
        Database::pdo()->prepare(
            'UPDATE base_account_moves SET ID_PAYMENT = ? WHERE ID = ? AND DELETED = 0'
        )->execute([$primary, $moveId]);
    }
}
