<?php
declare(strict_types=1);

final class PaymentsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'p.ID',
            'user' => 'p.ID_USER',
            'dt' => 'p.DT',
            'sum' => 'p.SUM',
            'account' => 'p.ACCOUNT',
        ];
    }

    public static function canSeeOthers(): bool
    {
        return Auth::capability('CAN_SEE_SUM_OTHERS');
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
        if ($ids === [] || self::canSeeOthers() || !Database::tableExists('base_payments')) {
            return $ids;
        }
        $uid = Auth::userId();
        if ($uid < 1) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_payments WHERE ID IN ($ph) AND ID_USER = ?"
        );
        $st->execute(array_merge($ids, [$uid]));
        $allowed = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $allowed[] = (int)$id;
        }
        return $allowed;
    }

    public static function ledgerEnabled(): bool
    {
        return ModuleManager::isEnabled('ledger');
    }

    /**
     * @param array{q?:string,user_id?:int,account?:int|null,direction?:int|null,scope_user_id?:int,dt_from?:int|null,dt_to?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_payments')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        [$sqlWhere, $params] = self::buildWhere($opts);

        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_payments p WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'p.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $userToCol = Database::columnExists('base_payments', 'ID_USER_TO')
            ? ', p.ID_USER_TO AS user_to'
            : ', 0 AS user_to';
        $sql = "SELECT p.ID AS id, p.ID_USER AS user_id, p.DT AS dt, p.DESCR AS descr,
                       p.DIRECTION AS direction, p.SUM AS sum,
                       p.ACCOUNT AS account, p.DT_CREATE AS dt_create,
                       p.ID_USER_SIGNED AS user_signed, p.ID_COMMENT AS comment_id,
                       p.IS_PARTNER_PAYMENT AS is_partner,
                       p.ID_ADMIN_INWORKED AS admin_inworked, p.DT_ADMIN_INWORKED AS dt_admin_inworked,
                       p.ID_ADMIN_SIGNED AS admin_signed, p.DT_ADMIN_SIGNED AS dt_admin_signed,
                       p.ID_ACCEPT_USER_SIGNED AS accept_user, p.DT_ACCEPT_USER_SIGNED AS dt_accept,
                       p.ID_MOVEMENT_LINKED AS movement_linked"
            . $userToCol
            . " FROM base_payments p
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

    /**
     * Balance before period start: SUM(DIRECTION * SUM) for rows matching filters with DT < dt_from.
     *
     * @param array{q?:string,user_id?:int,account?:int|null,direction?:int|null,scope_user_id?:int,dt_from?:int} $opts
     */
    public static function sumBalanceBefore(array $opts): float
    {
        if (!Database::tableExists('base_payments')) {
            return 0.0;
        }
        $dtFrom = (int)($opts['dt_from'] ?? 0);
        if ($dtFrom < 1) {
            return 0.0;
        }
        $beforeOpts = $opts;
        unset($beforeOpts['dt_from'], $beforeOpts['dt_to']);
        [$sqlWhere, $params] = self::buildWhere($beforeOpts);
        $sqlWhere .= ' AND p.DT < ?';
        $params[] = $dtFrom;
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(CAST(p.DIRECTION AS SIGNED) * p.SUM), 0) FROM base_payments p WHERE $sqlWhere"
        );
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    /**
     * Running balance after each payment (oper «Баланс после применения»).
     * Chronological ASC over the same filters as the list; opening = sum before dt_from when set.
     * Positive = company owes staff; negative = staff owes company (same sign as monthly debt).
     *
     * @param array{q?:string,user_id?:int,account?:int|null,direction?:int|null,scope_user_id?:int,dt_from?:int|null,dt_to?:int|null} $opts
     * @return array<int,float> payment id => balance after that row
     */
    public static function runningBalances(array $opts = []): array
    {
        if (!Database::tableExists('base_payments')) {
            return [];
        }
        $opening = 0.0;
        $dtFrom = isset($opts['dt_from']) ? (int)$opts['dt_from'] : 0;
        if ($dtFrom > 0) {
            $opening = self::sumBalanceBefore($opts);
        }
        [$sqlWhere, $params] = self::buildWhere($opts);
        $st = Database::pdo()->prepare(
            "SELECT p.ID AS id, p.DIRECTION AS direction, p.SUM AS sum
             FROM base_payments p
             WHERE $sqlWhere
             ORDER BY p.DT ASC, p.ID ASC"
        );
        $st->execute($params);
        $running = round($opening, 2);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $running = round($running + ((int)$row['direction'] * (float)$row['sum']), 2);
            $out[(int)$row['id']] = $running;
        }
        return $out;
    }

    /**
     * Group payment rows into monthly blocks (oper mode=2 parity).
     *
     * @param list<array<string,mixed>> $items Must be sorted ASC by DT for correct debt_start/debt_end
     * @return list<array{key:string,accrued:list,issued:list,total_in:float,total_out:float,internal_out:float,balanced:bool,debt_start:float,debt_end:float}>
     */
    public static function groupByMonth(array $items, float $openingDebt): array
    {
        $months = [];
        $debt = round($openingDebt, 2);
        $currentKey = '';
        $accrued = [];
        $issued = [];
        $totalIn = 0.0;
        $totalOut = 0.0;
        $internalOut = 0.0;

        $flush = static function () use (
            &$months,
            &$debt,
            &$currentKey,
            &$accrued,
            &$issued,
            &$totalIn,
            &$totalOut,
            &$internalOut
        ): void {
            if ($currentKey === '') {
                return;
            }
            $totalIn = round($totalIn, 2);
            $totalOut = round($totalOut, 2);
            $internalOut = round($internalOut, 2);
            $debtEnd = round($debt + $totalIn - $totalOut, 2);
            $months[] = [
                'key' => $currentKey,
                'accrued' => $accrued,
                'issued' => $issued,
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'internal_out' => $internalOut,
                'balanced' => abs($debtEnd) < 0.01 && ($totalIn >= 0.01 || $totalOut >= 0.01),
                'debt_start' => $debt,
                'debt_end' => $debtEnd,
            ];
            $debt = $debtEnd;
            $accrued = [];
            $issued = [];
            $totalIn = 0.0;
            $totalOut = 0.0;
            $internalOut = 0.0;
        };

        foreach ($items as $row) {
            $dt = (int)($row['dt'] ?? 0);
            $key = $dt > 0 ? date('Y.m', $dt) : '0000.00';
            if ($currentKey !== '' && $key !== $currentKey) {
                $flush();
                $currentKey = $key;
            } elseif ($currentKey === '') {
                $currentKey = $key;
            }
            $dir = (int)($row['direction'] ?? 0);
            $sum = round((float)($row['sum'] ?? 0), 2);
            if ($dir > 0) {
                $accrued[] = $row;
                $totalIn += $sum;
            } elseif ($dir < 0) {
                $issued[] = $row;
                $totalOut += $sum;
                if ((int)($row['account'] ?? 0) < 1) {
                    $internalOut += $sum;
                }
            }
        }
        $flush();

        return $months;
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{0:string,1:list<mixed>}
     */
    private static function buildWhere(array $opts): array
    {
        $where = ['1=1'];
        $params = [];

        $scope = (int)($opts['scope_user_id'] ?? 0);
        if ($scope > 0) {
            $where[] = 'p.ID_USER = ?';
            $params[] = $scope;
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.DESCR LIKE ? OR CAST(p.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $userId = (int)($opts['user_id'] ?? 0);
        if ($userId > 0) {
            $where[] = 'p.ID_USER = ?';
            $params[] = $userId;
        }
        if (array_key_exists('account', $opts) && $opts['account'] !== null && $opts['account'] !== '') {
            $where[] = 'p.ACCOUNT = ?';
            $params[] = (int)$opts['account'];
        }
        if (array_key_exists('direction', $opts) && $opts['direction'] !== null && $opts['direction'] !== '') {
            $where[] = 'p.DIRECTION = ?';
            $params[] = (string)(int)$opts['direction'];
        }
        $dtFrom = isset($opts['dt_from']) ? (int)$opts['dt_from'] : 0;
        if ($dtFrom > 0) {
            $where[] = 'p.DT >= ?';
            $params[] = $dtFrom;
        }
        $dtTo = isset($opts['dt_to']) ? (int)$opts['dt_to'] : 0;
        if ($dtTo > 0) {
            $where[] = 'p.DT < ?';
            $params[] = $dtTo;
        }

        return [implode(' AND ', $where), $params];
    }

    public static function find(int $id): ?array
    {
        return self::findRow($id, false);
    }

    /** Row lock; caller must own an open transaction. */
    public static function findForUpdate(int $id): ?array
    {
        return self::findRow($id, true);
    }

    private static function findRow(int $id, bool $forUpdate): ?array
    {
        if ($id < 1 || !Database::tableExists('base_payments')) {
            return null;
        }
        $accrualCol = Database::columnExists('base_payments', 'ACCRUAL_KEY')
            ? ', ACCRUAL_KEY AS accrual_key'
            : '';
        $userToCol = Database::columnExists('base_payments', 'ID_USER_TO')
            ? ', ID_USER_TO AS user_to'
            : ', 0 AS user_to';
        $sql = 'SELECT ID AS id, ID_USER AS user_id, DT AS dt, DESCR AS descr,
                       DIRECTION AS direction, SUM AS sum,
                       ACCOUNT AS account, DT_CREATE AS dt_create,
                       ID_USER_SIGNED AS user_signed, ID_COMMENT AS comment_id,
                       IS_PARTNER_PAYMENT AS is_partner,
                       ID_ADMIN_INWORKED AS admin_inworked, DT_ADMIN_INWORKED AS dt_admin_inworked,
                       ID_ADMIN_SIGNED AS admin_signed, DT_ADMIN_SIGNED AS dt_admin_signed,
                       ID_ACCEPT_USER_SIGNED AS accept_user, DT_ACCEPT_USER_SIGNED AS dt_accept,
                       ID_MOVEMENT_LINKED AS movement_linked'
            . $userToCol
            . $accrualCol
            . ' FROM base_payments WHERE ID = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::castRow($row) : null;
    }

    public static function hasLinkedMoves(int $paymentId): bool
    {
        if ($paymentId < 1) {
            return false;
        }
        if (class_exists('PaymentMoveLinksRepository', false)
            && PaymentMoveLinksRepository::isPaymentLinked($paymentId)) {
            return true;
        }
        // Soft-deleted DPS keep ID_PAYMENT for audit — only live moves block delete.
        if (!Database::tableExists('base_account_moves')
            || !Database::columnExists('base_account_moves', 'ID_PAYMENT')) {
            return false;
        }
        $sql = 'SELECT 1 FROM base_account_moves WHERE ID_PAYMENT = ?';
        if (Database::columnExists('base_account_moves', 'DELETED')) {
            $sql .= ' AND DELETED = 0';
        }
        $sql .= ' LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$paymentId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Prefill from contract offer comment (oper edit_payment id_comment).
     * Applies deadline overrun penalty to SUM like legacy.
     * @return array<string,mixed>|null
     */
    public static function prefillFromComment(int $commentId): ?array
    {
        if ($commentId < 1 || !Database::tableExists('base_comments')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_USER, ID_OBJECT, PRICE_OFFER, DT_OFFER_ACCEPT, DT_DEADLINE, DT_WORK_ACCEPT, ID_PAYMENT
             FROM base_comments WHERE ID = ? LIMIT 1'
        );
        $st->execute([$commentId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || (int)($c['DT_WORK_ACCEPT'] ?? 0) < 1) {
            return null;
        }
        $price = (float)($c['PRICE_OFFER'] ?? 0);
        $calc = self::contractPayoutFromComment($c);
        $sum = (float)$calc['sum'];
        $sumDeadLine = (float)$calc['deadline_cut'];
        $overTimeText = (string)$calc['over_text'];
        $descr = 'Конт-ID: ' . (int)($c['ID_OBJECT'] ?? 0)
            . ' Оффер-ID: ' . $commentId
            . ' Исх.Сумма: ' . $price
            . ' Расх. на overDL: ' . $sumDeadLine
            . $overTimeText;
        return [
            'user_id' => (int)$c['ID_USER'],
            'direction' => 1,
            'account' => 0,
            'sum' => $sum,
            'descr' => $descr,
            'comment_id' => $commentId,
            'is_partner' => 0,
        ];
    }

    /**
     * Oper deadline-penalty payout for an accepted offer comment.
     * Cut applied only when contracts.deadline_penalty is on (default).
     * @param array<string,mixed> $c base_comments row
     * @return array{sum:float,deadline_cut:float,over_text:string}
     */
    public static function contractPayoutFromComment(array $c): array
    {
        $sumCalculation = (float)($c['PRICE_OFFER'] ?? 0);
        $sumDeadLine = 0.0;
        $procentOfPaymentDeadLine = 1.0;
        $overTimeText = '';
        $penaltyOn = class_exists('ContractsRepository', false)
            ? ContractsRepository::deadlinePenaltyEnabled()
            : ((int)Config::get('contracts', 'deadline_penalty', 1) === 1);
        $offerAt = (int)($c['DT_OFFER_ACCEPT'] ?? 0);
        $workAt = (int)($c['DT_WORK_ACCEPT'] ?? 0);
        $dl = (int)($c['DT_DEADLINE'] ?? 0);
        if ($offerAt > 0 && $dl > 0) {
            $dayEnd = static function (int $ts): int {
                return (int)mktime(23, 59, 59, (int)date('m', $ts), (int)date('d', $ts), (int)date('Y', $ts));
            };
            $offerEnd = $dayEnd($offerAt);
            $dlEnd = $dayEnd($dl);
            if ($workAt > 0) {
                $timeUsed = $dayEnd($workAt) - $offerEnd;
                $timeRemain = $dlEnd - $dayEnd($workAt);
            } else {
                $timeUsed = time() - $offerEnd;
                $timeRemain = $dlEnd - time();
            }
            $timeTotal = $dlEnd - $offerEnd;
            $procentUsed = $timeTotal > 0 ? (int)round($timeUsed * 100 / $timeTotal) : 0;
            $procentLabel = (string)$procentUsed . '%';
            if ($penaltyOn) {
                if ($procentUsed > 200) {
                    // show_contracts: full cut; edit_payment wrongly left factor=1
                    $procentLabel .= ' /сокр. возн.:100';
                    $procentOfPaymentDeadLine = 0.0;
                } elseif ($procentUsed > 100) {
                    $procentOfPaymentDeadLine = 1.0 - round(($procentUsed - 100) / 100, 2);
                    $procentLabel .= ' /сокр. возн.:' . ($procentUsed - 100);
                }
            }
            $overTimeText = '[Ост.:' . round($timeRemain / 86400, 1)
                . ' д] [Использовано: ' . round($timeUsed / 86400, 1)
                . '/' . round($timeTotal / 86400, 1)
                . ' д: ' . $procentLabel . ']';
            if ($penaltyOn) {
                $sumDeadLine = (float)round($sumCalculation * (1.0 - $procentOfPaymentDeadLine));
                if ($sumDeadLine > 0) {
                    $sumCalculation -= $sumDeadLine;
                }
            }
        }
        return [
            'sum' => $sumCalculation,
            'deadline_cut' => $sumDeadLine,
            'over_text' => $overTimeText,
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, ?array $existing = null): array
    {
        $userId = (int)($post['user_id'] ?? 0);
        if ($userId < 1) {
            throw new InvalidArgumentException(t('payments.err.user_required'));
        }
        $sumRaw = trim((string)($post['sum'] ?? ''));
        if ($sumRaw === '') {
            throw new InvalidArgumentException(t('payments.err.sum_required'));
        }
        $sum = money_parse($sumRaw);
        // Amount must be strictly positive; sign lives only in DIRECTION.
        // (direction=-1 with a negative SUM would inflate balance as a hidden accrual.)
        if ($sum <= 0) {
            throw new InvalidArgumentException(t('payments.err.sum_positive'));
        }
        $directionRaw = $post['direction'] ?? '';
        if ($directionRaw === '' || $directionRaw === null) {
            throw new InvalidArgumentException(t('payments.err.direction_required'));
        }
        $direction = (int)$directionRaw;
        if (!in_array($direction, [-1, 1], true)) {
            throw new InvalidArgumentException(t('payments.err.direction_required'));
        }
        // Without CAN_SEE_SUM_OTHERS staff may only post write-offs / payout requests for self,
        // not open accruals that raise DayPayroll balance.
        if ($direction === 1 && !self::canSeeOthers()) {
            throw new InvalidArgumentException(t('payments.err.accrual_forbidden'));
        }
        $account = (int)($post['account'] ?? 0);
        if (!in_array($account, [0, 1, 2], true)) {
            $account = 0;
        }
        if ($account !== 0 && !self::ledgerEnabled()) {
            // Wave 1: force no-DPS path until ledger exists
            $account = 0;
        }
        $dt = self::parseDt((string)($post['dt'] ?? ''));
        if ($dt < 1) {
            $dt = time();
        }
        $descr = trim((string)($post['descr'] ?? ''));
        if ($existing !== null
            && class_exists('DayPayroll', false)
            && DayPayroll::isPayoutDescr((string)($existing['descr'] ?? ''))
        ) {
            // Keep PIN/ADMIN machine tags; UI shows a masked value.
            $descr = (string)$existing['descr'];
        }
        if ($descr === '') {
            throw new InvalidArgumentException(t('payments.err.descr_required'));
        }
        $commentId = (int)($post['comment_id'] ?? ($existing['comment_id'] ?? 0));
        // Partner payout UI removed; keep column at 0 on save.
        $isPartner = 0;

        $adminInworked = (int)($existing['admin_inworked'] ?? 0);
        $dtAdminInworked = (int)($existing['dt_admin_inworked'] ?? 0);
        $adminSigned = (int)($existing['admin_signed'] ?? 0);
        $dtAdminSigned = (int)($existing['dt_admin_signed'] ?? 0);
        $acceptUser = (int)($existing['accept_user'] ?? 0);
        $dtAccept = (int)($existing['dt_accept'] ?? 0);

        $uid = Auth::userId();
        if (!empty($post['mark_inwork']) && $adminInworked < 1 && $account === 2 && $direction < 0) {
            $adminInworked = $uid;
            $dtAdminInworked = time();
        }
        if (!empty($post['mark_admin_sign']) && $adminInworked > 0 && $adminSigned < 1 && $account === 2 && $direction < 0) {
            $adminSigned = $uid;
            $dtAdminSigned = time();
            $acceptUser = $adminSigned;
            $dtAccept = $dtAdminSigned;
        }

        // Auto-accept for bank accruals (oper: ACCOUNT=1, DIRECTION>=0)
        if ($account === 1 && $direction >= 0 && $adminSigned < 1) {
            $adminSigned = $uid;
            $dtAdminSigned = time();
            $acceptUser = $adminSigned;
            $dtAccept = $dtAdminSigned;
        }

        return [
            'user_id' => $userId,
            'dt' => $dt,
            'descr' => $descr,
            'direction' => $direction,
            'sum' => $sum,
            'account' => $account,
            'dt_create' => $existing ? (int)$existing['dt_create'] : time(),
            'user_signed' => $existing ? (int)$existing['user_signed'] : $uid,
            'comment_id' => $commentId,
            'is_partner' => $isPartner,
            'admin_inworked' => $adminInworked,
            'dt_admin_inworked' => $dtAdminInworked,
            'admin_signed' => $adminSigned,
            'dt_admin_signed' => $dtAdminSigned,
            'accept_user' => $acceptUser,
            'dt_accept' => $dtAccept,
            'movement_linked' => (int)($existing['movement_linked'] ?? 0),
        ];
    }

    private const TRANSFER_CREDIT_PREFIX = 'Парное движение от перемещения - #';
    private const TRANSFER_CREDIT_SUFFIX = '#';

    public static function transferCreditDescr(string $origDescr): string
    {
        return self::TRANSFER_CREDIT_PREFIX . $origDescr . self::TRANSFER_CREDIT_SUFFIX;
    }

    public static function isTransferCreditDescr(string $descr): bool
    {
        $descr = trim($descr);
        $prefix = self::TRANSFER_CREDIT_PREFIX;
        $suffix = self::TRANSFER_CREDIT_SUFFIX;
        return $descr !== ''
            && strpos($descr, $prefix) === 0
            && substr($descr, -strlen($suffix)) === $suffix
            && strlen($descr) > strlen($prefix) + strlen($suffix);
    }

    public static function transferOrigDescrFromCredit(string $creditDescr): string
    {
        if (!self::isTransferCreditDescr($creditDescr)) {
            return '';
        }
        $prefix = self::TRANSFER_CREDIT_PREFIX;
        $suffix = self::TRANSFER_CREDIT_SUFFIX;
        return substr($creditDescr, strlen($prefix), -strlen($suffix));
    }

    /**
     * Oper staff-to-staff «перемещение»: debit (ACCOUNT=0, ID_USER_TO) + credit pair.
     *
     * @return array{debit_id:int,credit_id:int}
     */
    public static function createTransfer(
        int $fromId,
        int $toId,
        float $sum,
        int $dt,
        string $descr,
        int $actorId = 0
    ): array {
        if ($fromId < 1 || $toId < 1) {
            throw new InvalidArgumentException(t('payments.transfer.err.staff'));
        }
        if ($fromId === $toId) {
            throw new InvalidArgumentException(t('payments.transfer.err.same_user'));
        }
        $sum = round($sum, 2);
        if ($sum < 0.01) {
            throw new InvalidArgumentException(t('payments.transfer.err.sum'));
        }
        $descr = trim($descr);
        if ($descr === '') {
            throw new InvalidArgumentException(t('payments.transfer.err.descr'));
        }
        if (!Database::tableExists('base_payments')
            || !Database::columnExists('base_payments', 'ID_USER_TO')) {
            throw new RuntimeException(t('payments.transfer.err.unavailable'));
        }
        if (UsersRepository::find($fromId) === null || UsersRepository::find($toId) === null) {
            throw new InvalidArgumentException(t('payments.transfer.err.staff'));
        }
        if ($dt < 1) {
            $dt = time();
        }
        $actorId = $actorId > 0 ? $actorId : Auth::userId();
        $now = time();
        $base = [
            'dt' => $dt,
            'sum' => $sum,
            'account' => 0,
            'dt_create' => $now,
            'user_signed' => $actorId,
            'comment_id' => 0,
            'is_partner' => 0,
            'admin_inworked' => $actorId,
            'dt_admin_inworked' => $now,
            'admin_signed' => $actorId,
            'dt_admin_signed' => $now,
            'accept_user' => $actorId,
            'dt_accept' => $now,
            'movement_linked' => 0,
        ];

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $debitId = self::create(array_merge($base, [
                'user_id' => $fromId,
                'descr' => $descr,
                'direction' => -1,
                'user_to' => $toId,
            ]));
            $creditId = self::create(array_merge($base, [
                'user_id' => $toId,
                'descr' => self::transferCreditDescr($descr),
                'direction' => 1,
                'user_to' => 0,
            ]));
            $pdo->commit();
            return ['debit_id' => $debitId, 'credit_id' => $creditId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{debit:array<string,mixed>,credit:array<string,mixed>}|null
     */
    public static function findTransferPair(int $paymentId): ?array
    {
        if ($paymentId < 1) {
            return null;
        }
        $row = self::find($paymentId);
        if ($row === null) {
            return null;
        }
        $dir = (int)$row['direction'];
        $sum = round((float)$row['sum'], 2);
        $dt = (int)$row['dt'];
        $descr = (string)$row['descr'];

        if ($dir < 0 && (int)($row['user_to'] ?? 0) > 0) {
            $toId = (int)$row['user_to'];
            $creditDescr = self::transferCreditDescr($descr);
            $credit = self::findTransferCounterpart(
                $toId,
                1,
                $sum,
                $dt,
                $creditDescr,
                $paymentId
            );
            if ($credit === null) {
                return null;
            }
            return ['debit' => $row, 'credit' => $credit];
        }

        if ($dir > 0 && self::isTransferCreditDescr($descr)) {
            $orig = self::transferOrigDescrFromCredit($descr);
            if ($orig === '') {
                return null;
            }
            $debit = self::findTransferDebitForCredit($row, $orig);
            if ($debit === null) {
                return null;
            }
            return ['debit' => $debit, 'credit' => $row];
        }

        return null;
    }

    public static function isTransferLeg(array $row): bool
    {
        if ((int)($row['direction'] ?? 0) < 0 && (int)($row['user_to'] ?? 0) > 0) {
            return true;
        }
        return (int)($row['direction'] ?? 0) > 0
            && self::isTransferCreditDescr((string)($row['descr'] ?? ''));
    }

    /**
     * Expand selection so deleting one transfer leg removes both.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function expandTransferDeleteIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $raw) {
            $id = (int)$raw;
            if ($id < 1) {
                continue;
            }
            $out[$id] = true;
            $pair = self::findTransferPair($id);
            if ($pair !== null) {
                $out[(int)$pair['debit']['id']] = true;
                $out[(int)$pair['credit']['id']] = true;
            }
        }
        return array_map('intval', array_keys($out));
    }

    /** @return array<string,mixed>|null */
    private static function findTransferCounterpart(
        int $userId,
        int $direction,
        float $sum,
        int $dt,
        string $descr,
        int $excludeId
    ): ?array {
        if ($userId < 1 || !Database::tableExists('base_payments')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_payments
             WHERE ID_USER = ? AND CAST(DIRECTION AS SIGNED) = ? AND SUM = ? AND DT = ? AND DESCR = ?
               AND ID <> ?
             ORDER BY ID ASC LIMIT 1'
        );
        $st->execute([$userId, $direction, $sum, $dt, $descr, $excludeId]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id) : null;
    }

    /**
     * @param array<string,mixed> $credit
     * @return array<string,mixed>|null
     */
    private static function findTransferDebitForCredit(array $credit, string $origDescr): ?array
    {
        $toId = (int)($credit['user_id'] ?? 0);
        $sum = round((float)($credit['sum'] ?? 0), 2);
        $dt = (int)($credit['dt'] ?? 0);
        $creditId = (int)($credit['id'] ?? 0);
        if ($toId < 1 || $dt < 1 || !Database::columnExists('base_payments', 'ID_USER_TO')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_payments
             WHERE ID_USER_TO = ? AND CAST(DIRECTION AS SIGNED) < 0 AND SUM = ? AND DT = ? AND DESCR = ?
               AND ID <> ?
             ORDER BY ID ASC LIMIT 1'
        );
        $st->execute([$toId, $sum, $dt, $origDescr, $creditId]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id) : null;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        // Live oper schemas often have NOT NULL columns without DEFAULT — always send zeros.
        $cols = [
            'ID_USER', 'DT', 'DESCR', 'DIRECTION', 'SUM', 'ACCOUNT', 'DT_CREATE',
            'ID_USER_SIGNED', 'ID_COMMENT', 'IS_PARTNER_PAYMENT',
            'ID_ADMIN_INWORKED', 'DT_ADMIN_INWORKED',
            'ID_ADMIN_SIGNED', 'DT_ADMIN_SIGNED',
            'ID_ACCEPT_USER_SIGNED', 'DT_ACCEPT_USER_SIGNED',
            'ID_MOVEMENT_LINKED',
        ];
        $params = [
            (int)$data['user_id'],
            (int)$data['dt'],
            (string)$data['descr'],
            (string)$data['direction'],
            $data['sum'],
            (int)$data['account'],
            (int)$data['dt_create'],
            (int)$data['user_signed'],
            (int)$data['comment_id'],
            (int)$data['is_partner'],
            (int)$data['admin_inworked'],
            (int)$data['dt_admin_inworked'],
            (int)$data['admin_signed'],
            (int)$data['dt_admin_signed'],
            (int)$data['accept_user'],
            (int)$data['dt_accept'],
            (int)$data['movement_linked'],
        ];
        $optional = [
            'ID_CONTROL_NOTICE' => (int)($data['control_notice'] ?? 0),
            'ID_USER_TO' => (int)($data['user_to'] ?? 0),
            'ID_RATE' => (int)($data['rate_id'] ?? 0),
        ];
        foreach ($optional as $col => $val) {
            if (Database::columnExists('base_payments', $col)) {
                $cols[] = $col;
                $params[] = $val;
            }
        }
        if (Database::columnExists('base_payments', 'ACCRUAL_KEY')) {
            $key = trim((string)($data['accrual_key'] ?? ''));
            $cols[] = 'ACCRUAL_KEY';
            $params[] = $key !== '' ? $key : null;
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_payments (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')'
        );
        $st->execute($params);
        $id = (int)Database::pdo()->lastInsertId();
        if ((int)$data['comment_id'] > 0) {
            self::bindCommentPayment((int)$data['comment_id'], $id);
        }
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid payment id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_payments SET
             ID_USER = ?, DT = ?, DESCR = ?, DIRECTION = ?, SUM = ?, ACCOUNT = ?,
             ID_COMMENT = ?, IS_PARTNER_PAYMENT = ?,
             ID_ADMIN_INWORKED = ?, DT_ADMIN_INWORKED = ?,
             ID_ADMIN_SIGNED = ?, DT_ADMIN_SIGNED = ?,
             ID_ACCEPT_USER_SIGNED = ?, DT_ACCEPT_USER_SIGNED = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['user_id'],
            $data['dt'],
            $data['descr'],
            (string)$data['direction'],
            $data['sum'],
            $data['account'],
            $data['comment_id'],
            $data['is_partner'],
            $data['admin_inworked'],
            $data['dt_admin_inworked'],
            $data['admin_signed'],
            $data['dt_admin_signed'],
            $data['accept_user'],
            $data['dt_accept'],
            $id,
        ]);
        if ($data['comment_id'] > 0) {
            self::bindCommentPayment((int)$data['comment_id'], $id);
        }
    }

    public static function bindCommentPayment(int $commentId, int $paymentId): void
    {
        if ($commentId < 1 || $paymentId < 1 || !Database::tableExists('base_comments')) {
            return;
        }
        if (!Database::columnExists('base_comments', 'ID_PAYMENT')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_comments SET ID_PAYMENT = ? WHERE ID = ? AND (ID_PAYMENT = 0 OR ID_PAYMENT IS NULL)'
        );
        $st->execute([$paymentId, $commentId]);
    }

    /**
     * Payment id + sum for offer comments (one query). Prefers ID_COMMENT; falls back to comment.ID_PAYMENT.
     *
     * @param list<int> $commentIds
     * @param array<int,int> $paymentIdByComment comment_id => ID_PAYMENT from comment row
     * @return array<int, array{payment_id:int, sum:float}>
     */
    public static function sumsByCommentIds(array $commentIds, array $paymentIdByComment = []): array
    {
        $out = [];
        if (!Database::tableExists('base_payments')) {
            return $out;
        }
        $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds))));
        $payIds = [];
        foreach ($paymentIdByComment as $cid => $pid) {
            $cid = (int)$cid;
            $pid = (int)$pid;
            if ($cid > 0 && $pid > 0) {
                $payIds[$pid] = $cid;
            }
        }
        if ($commentIds === [] && $payIds === []) {
            return $out;
        }
        $pdo = Database::pdo();
        if ($commentIds !== []) {
            $ph = implode(',', array_fill(0, count($commentIds), '?'));
            $st = $pdo->prepare(
                "SELECT ID AS id, ID_COMMENT AS comment_id, SUM AS sum
                 FROM base_payments WHERE ID_COMMENT IN ($ph)"
            );
            $st->execute($commentIds);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int)($row['comment_id'] ?? 0);
                if ($cid < 1) {
                    continue;
                }
                $out[$cid] = [
                    'payment_id' => (int)$row['id'],
                    'sum' => (float)$row['sum'],
                ];
            }
        }
        $missingPayIds = [];
        foreach ($payIds as $pid => $cid) {
            if (!isset($out[$cid])) {
                $missingPayIds[] = $pid;
            }
        }
        if ($missingPayIds !== []) {
            $ph = implode(',', array_fill(0, count($missingPayIds), '?'));
            $st = $pdo->prepare(
                "SELECT ID AS id, SUM AS sum FROM base_payments WHERE ID IN ($ph)"
            );
            $st->execute($missingPayIds);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)$row['id'];
                $cid = (int)($payIds[$pid] ?? 0);
                if ($cid < 1 || isset($out[$cid])) {
                    continue;
                }
                $out[$cid] = [
                    'payment_id' => $pid,
                    'sum' => (float)$row['sum'],
                ];
            }
        }

        return $out;
    }

    /**
     * Hard-delete settlements linked to a contract comment (reward reparse).
     * Unlinks DPS move links / soft-deletes moves.ID_PAYMENT, then DELETE payment.
     *
     * @return list<int> deleted payment ids
     */
    public static function forceDeleteLinkedToComment(int $commentId): array
    {
        $deleted = [];
        if ($commentId < 1 || !Database::tableExists('base_payments')) {
            return $deleted;
        }
        $ids = [];
        $st = Database::pdo()->prepare('SELECT ID FROM base_payments WHERE ID_COMMENT = ?');
        $st->execute([$commentId]);
        while ($id = $st->fetchColumn()) {
            $ids[(int)$id] = true;
        }
        if (Database::tableExists('base_comments') && Database::columnExists('base_comments', 'ID_PAYMENT')) {
            $st = Database::pdo()->prepare('SELECT ID_PAYMENT FROM base_comments WHERE ID = ? LIMIT 1');
            $st->execute([$commentId]);
            $pid = (int)$st->fetchColumn();
            if ($pid > 0) {
                $ids[$pid] = true;
            }
        }
        foreach (array_keys($ids) as $pid) {
            self::forceDelete((int)$pid);
            $deleted[] = (int)$pid;
        }
        if (Database::tableExists('base_comments') && Database::columnExists('base_comments', 'ID_PAYMENT')) {
            Database::pdo()->prepare(
                'UPDATE base_comments SET ID_PAYMENT = 0 WHERE ID = ?'
            )->execute([$commentId]);
        }

        return $deleted;
    }

    /** System delete (no ACL scope). Used by day-payroll replace and reward-contract reparse. */
    public static function forceDelete(int $paymentId): void
    {
        if ($paymentId < 1 || !Database::tableExists('base_payments')) {
            return;
        }
        $pdo = Database::pdo();
        if (Database::tableExists('base_payment_move_links')) {
            $pdo->prepare('DELETE FROM base_payment_move_links WHERE ID_PAYMENT = ?')->execute([$paymentId]);
        }
        if (Database::tableExists('base_account_moves') && Database::columnExists('base_account_moves', 'ID_PAYMENT')) {
            if (Database::columnExists('base_account_moves', 'DELETED')) {
                $pdo->prepare('UPDATE base_account_moves SET DELETED = 1 WHERE ID_PAYMENT = ?')->execute([$paymentId]);
            } else {
                $pdo->prepare('DELETE FROM base_account_moves WHERE ID_PAYMENT = ?')->execute([$paymentId]);
            }
        }
        if (Database::tableExists('base_comments') && Database::columnExists('base_comments', 'ID_PAYMENT')) {
            $pdo->prepare(
                'UPDATE base_comments SET ID_PAYMENT = 0 WHERE ID_PAYMENT = ?'
            )->execute([$paymentId]);
        }
        $pdo->prepare('DELETE FROM base_payments WHERE ID = ?')->execute([$paymentId]);
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function deleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        $ids = self::expandTransferDeleteIds($ids);
        foreach (self::scopeOwnedIds($ids) as $id) {
            if (self::hasLinkedMoves($id)) {
                $skipped[] = ['id' => $id, 'reason' => t('payments.err.delete_linked')];
                continue;
            }
            $st = Database::pdo()->prepare('DELETE FROM base_payments WHERE ID = ?');
            $st->execute([$id]);
            $done += $st->rowCount();
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * @param list<int> $ids
     * @return int
     */
    public static function setAccountByIds(array $ids, int $account): int
    {
        if (!in_array($account, [0, 1, 2], true)) {
            return 0;
        }
        if ($account !== 0 && !self::ledgerEnabled()) {
            return 0;
        }
        $ids = self::scopeOwnedIds($ids);
        $allowed = [];
        foreach ($ids as $id) {
            $row = self::find($id);
            if ($row === null || self::isTransferLeg($row)) {
                continue;
            }
            $allowed[] = $id;
        }
        if ($allowed === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($allowed), '?'));
        $params = array_merge([$account], $allowed);
        $st = Database::pdo()->prepare("UPDATE base_payments SET ACCOUNT = ? WHERE ID IN ($ph)");
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * @param list<int> $ids
     * @return int
     */
    public static function acceptByIds(array $ids): int
    {
        $ids = self::scopeOwnedIds($ids);
        if ($ids === []) {
            return 0;
        }
        $uid = Auth::userId();
        $now = time();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$uid, $now], $ids);
        $st = Database::pdo()->prepare(
            "UPDATE base_payments SET ID_ACCEPT_USER_SIGNED = ?, DT_ACCEPT_USER_SIGNED = ?
             WHERE ID IN ($ph) AND ID_ADMIN_SIGNED > 0 AND ID_ACCEPT_USER_SIGNED = 0"
        );
        $st->execute($params);
        return $st->rowCount();
    }

    public static function directionLabel(int $dir): string
    {
        $key = 'payments.dir.' . $dir;
        $label = t($key);
        return $label !== $key ? $label : (string)$dir;
    }

    public static function accountLabel(int $account): string
    {
        $key = 'payments.account.' . $account;
        $label = t($key);
        return $label !== $key ? $label : (string)$account;
    }

    /** @param array<string,mixed> $row */
    public static function statusLabel(array $row): string
    {
        if ((int)($row['accept_user'] ?? 0) > 0) {
            return t('payments.status.accepted');
        }
        if ((int)($row['admin_signed'] ?? 0) > 0) {
            return t('payments.status.signed');
        }
        if ((int)($row['admin_inworked'] ?? 0) > 0) {
            return t('payments.status.inwork');
        }
        return t('payments.status.open');
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
            'id', 'user_id', 'user_to', 'dt', 'account', 'dt_create', 'user_signed', 'comment_id',
            'is_partner', 'admin_inworked', 'dt_admin_inworked', 'admin_signed', 'dt_admin_signed',
            'accept_user', 'dt_accept', 'movement_linked',
        ] as $k) {
            $row[$k] = (int)($row[$k] ?? 0);
        }
        $row['direction'] = (int)($row['direction'] ?? 0);
        $row['sum'] = (float)($row['sum'] ?? 0);
        $row['descr'] = (string)($row['descr'] ?? '');
        return $row;
    }

    /**
     * Exact DESCR match (procedure restore settlement uses DESCR = procedure id string).
     */
    public static function existsByDescr(string $descr): bool
    {
        $descr = trim($descr);
        if ($descr === '' || !Database::tableExists('base_payments')) {
            return false;
        }
        $st = Database::pdo()->prepare('SELECT 1 FROM base_payments WHERE DESCR = ? LIMIT 1');
        $st->execute([$descr]);
        return (bool)$st->fetchColumn();
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function createDpsByIds(array $ids, int $accountOverride = 0): array
    {
        if (!self::ledgerEnabled() || !class_exists('LedgerMovesRepository')) {
            $skipped = [];
            foreach (self::sanitizeIds($ids) as $id) {
                $skipped[] = ['id' => $id, 'reason' => t('payments.flash.dps_stub')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        $done = 0;
        $skipped = [];
        foreach (self::scopeOwnedIds($ids) as $id) {
            $row = self::find($id);
            if (!$row) {
                continue;
            }
            try {
                if (self::isTransferLeg($row)) {
                    $skipped[] = ['id' => $id, 'reason' => t('payments.transfer.err.no_dps')];
                    continue;
                }
                if (class_exists('PaymentMoveLinksRepository', false)
                    && PaymentMoveLinksRepository::isPaymentLinked($id)) {
                    $skipped[] = ['id' => $id, 'reason' => t('payments.flash.dps_none')];
                    continue;
                }
                if ($accountOverride > 0) {
                    $row['ledger_account_override'] = $accountOverride;
                }
                $moveId = LedgerMovesRepository::createFromPayment($row);
                if ($moveId > 0) {
                    if (class_exists('PaymentMoveLinksRepository', false)
                        && PaymentMoveLinksRepository::ready()) {
                        PaymentMoveLinksRepository::link($id, $moveId, abs((float)$row['sum']));
                    }
                    $done++;
                } else {
                    $skipped[] = ['id' => $id, 'reason' => t('payments.flash.dps_none')];
                }
            } catch (Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return ['done' => $done, 'skipped' => $skipped];
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
