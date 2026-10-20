<?php
declare(strict_types=1);

/**
 * Daily payroll: day accruals + cash payout requests with PIN
 * (port of oper freelance_payout_lib, without likespa status hardcoding).
 *
 * Settings: cfg/modules/payments.ini → day_pay_* keys.
 * Role flag: base_admin_statuses.PAY_DAILY.
 */
final class DayPayroll
{
    private const ACCRUAL_PREFIX = 'Начисление ЗП ФЛ ';
    private const PAYOUT_PREFIX = 'Выдача ФЛ на ';

    /** @return array{min_pay:int,account_id:int,issuer_status2:int} */
    public static function settings(): array
    {
        return [
            'min_pay' => max(0, (int)Config::get('payments', 'day_pay_min_pay', '3000')),
            'account_id' => max(0, (int)Config::get('payments', 'day_pay_payout_account_id', '0')),
            'issuer_status2' => max(0, (int)Config::get('payments', 'day_pay_issuer_status2', '17')),
        ];
    }

    public static function isDailyPayStaff(int $userId): bool
    {
        if ($userId < 1 || !Database::columnExists('base_admin_statuses', 'PAY_DAILY')) {
            return false;
        }
        $u = UsersRepository::find($userId);
        if ($u === null || (string)($u['enabled'] ?? '') !== '1') {
            return false;
        }
        $statusId = (int)($u['ID_STATUS'] ?? $u['status_id'] ?? 0);
        if ($statusId < 1) {
            return false;
        }
        $st = StatusesRepository::find($statusId);
        return $st !== null && (int)($st['PAY_DAILY'] ?? 0) > 0;
    }

    public static function isCashIssuer(int $userId): bool
    {
        $status2 = (int)self::settings()['issuer_status2'];
        if ($status2 < 1 || $userId < 1) {
            return false;
        }
        $u = UsersRepository::find($userId);
        if ($u === null || (string)($u['enabled'] ?? '') !== '1') {
            return false;
        }
        return (int)($u['ID_STATUS2'] ?? $u['status2_id'] ?? 0) === $status2;
    }

    /** @return list<int> */
    public static function cashIssuerIds(): array
    {
        $status2 = (int)self::settings()['issuer_status2'];
        if ($status2 < 1 || !Database::tableExists('base_users')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT id FROM base_users WHERE ID_STATUS2 = ? AND enabled = '1'"
        );
        $st->execute([$status2]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    /**
     * @return list<array{ts:int,label:string,admin_ids:list<int>}>
     */
    public static function availableDates(int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        $adminIds = self::cashIssuerIds();
        if ($adminIds === [] || !Database::tableExists('base_workweeks')) {
            return [];
        }
        $minDay = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') + 2, (int)date('Y'));
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $params = $adminIds;
        $params[] = $minDay;
        $st = Database::pdo()->prepare(
            "SELECT DT AS dt, ID_OPERATOR AS id_operator FROM base_workweeks
             WHERE ID_OPERATOR IN ($ph) AND DT >= ?
             ORDER BY DT ASC"
        );
        $st->execute($params);
        $byDay = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $start = self::dayStart((int)$row['dt']);
            if ($start < $minDay) {
                continue;
            }
            if (!isset($byDay[$start])) {
                $byDay[$start] = [
                    'ts' => $start,
                    'label' => date('j.m.Y', $start),
                    'admin_ids' => [],
                ];
            }
            $aid = (int)$row['id_operator'];
            if (!in_array($aid, $byDay[$start]['admin_ids'], true)) {
                $byDay[$start]['admin_ids'][] = $aid;
            }
        }
        ksort($byDay, SORT_NUMERIC);
        return array_slice(array_values($byDay), 0, $limit);
    }

    /** @return array{ok:bool,reason:string} */
    public static function requestAvailable(): array
    {
        if ((int)self::settings()['issuer_status2'] < 1) {
            return ['ok' => false, 'reason' => t('payments.bot.day_pay.unavailable')];
        }
        if (self::cashIssuerIds() === []) {
            return ['ok' => false, 'reason' => t('payments.bot.day_pay.unavailable')];
        }
        if (self::availableDates(1) === []) {
            return ['ok' => false, 'reason' => t('payments.bot.day_pay.unavailable')];
        }
        return ['ok' => true, 'reason' => ''];
    }

    public static function blockedUntilAfterShift(int $userId): bool
    {
        if (!class_exists('WorkweeksRepository', false) || !WorkweeksRepository::onShiftToday($userId)) {
            return false;
        }
        return (int)date('G') < 22;
    }

    public static function balance(int $userId): float
    {
        if ($userId < 1 || !Database::tableExists('base_payments')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT DIRECTION, SUM FROM base_payments WHERE ID_USER = ? ORDER BY DT ASC, ID ASC'
        );
        $st->execute([$userId]);
        $total = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $total += ((float)$row['DIRECTION']) * round((float)$row['SUM'], 2);
        }
        return round($total, 2);
    }

    public static function maxRequestSum(int $userId): int
    {
        $bal = self::balance($userId);
        if ($bal < 100) {
            return 0;
        }
        return (int)(floor($bal / 100) * 100);
    }

    public static function calcDaySalary(int $userId, int $dayTs, bool $applyMin = true): float
    {
        if ($userId < 1 || $dayTs < 1) {
            return 0.0;
        }
        if (!ModuleManager::isEnabled('procents') || !class_exists('ProcentsRepository', false)) {
            return 0.0;
        }
        if (!ModuleManager::isEnabled('procedures') || !class_exists('ProceduresRepository', false)) {
            return 0.0;
        }
        $dayStart = self::dayStart($dayTs);
        $dayEnd = $dayStart + 86400;
        $stats = ProceduresRepository::dayColumnStats($dayStart, $dayEnd, $userId);
        $operators = is_array($stats['operators'] ?? null) ? $stats['operators'] : [];
        // Still compute shift-fix / audio for staff with no procedures that day.
        if (!isset($operators[$userId]) && class_exists(ProceduresDayLoad::class, false)) {
            $operators[$userId] = ProceduresDayLoad::operatorStub($userId);
        } elseif (!isset($operators[$userId])) {
            $operators[$userId] = ['sums_by_type' => []];
        }
        $pay = ProcentsRepository::dayStaffPay($dayStart, $dayEnd, $operators);
        $total = floor((float)($pay[$userId]['total'] ?? 0));
        if ($applyMin && $total > 0) {
            $min = (int)self::settings()['min_pay'];
            if ($min > 0 && $total < $min) {
                $total = $min;
            }
        }
        return (float)$total;
    }

    /**
     * Upsert day accruals for PAY_DAILY staff (oper sync_operator).
     *
     * @return list<string> log lines (empty when skipped)
     */
    public static function syncOperator(int $userId, int $actorId = 0): array
    {
        $log = [];
        if ($userId < 1 || !self::isDailyPayStaff($userId) || !Database::tableExists('base_payments')) {
            return $log;
        }
        if (!ModuleManager::isEnabled('procents') || !class_exists('ProcentsRepository', false)) {
            return $log;
        }

        $locked = self::withUserLock('sync', $userId, 15, static function () use ($userId, $actorId): array {
            return self::syncOperatorUnlocked($userId, $actorId);
        });
        return $locked ?? $log;
    }

    /** Caller must already hold a per-user payroll lock (or accept races). */
    private static function syncOperatorUnlocked(int $userId, int $actorId = 0): array
    {
        $log = [];
        $actorId = $actorId > 0 ? $actorId : ($userId > 0 ? $userId : Auth::userId());
        $monthStart = (int)mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
        $todayEnd = (int)mktime(23, 59, 59, (int)date('n'), (int)date('j'), (int)date('Y'));
        $syncFrom = self::dayStart(time()) - 180 * 86400;
        foreach (self::staffShiftDays($userId) as $dayTs) {
            if ($dayTs < $syncFrom || $dayTs > $todayEnd) {
                continue;
            }
            $sum = self::calcDaySalary($userId, $dayTs, true);
            $existing = self::findAccrual($userId, $dayTs);
            $inCurrentMonth = $dayTs >= $monthStart;
            $hasWriteoff = self::hasDayWriteoff($userId, $dayTs);

            // Correct zero: clear previous positive accrual (do not skip before lookup).
            if ($sum <= 0) {
                if ($existing !== null && $inCurrentMonth && !$hasWriteoff) {
                    $oldSum = round((float)$existing['sum'], 2);
                    if ($oldSum >= 0.01) {
                        self::forceDeletePayment((int)$existing['id']);
                        $log[] = date('j.m.Y', $dayTs) . ': ' . $oldSum . ' → 0';
                    }
                }
                continue;
            }

            if ($existing !== null) {
                if ($inCurrentMonth && !$hasWriteoff) {
                    $oldSum = round((float)$existing['sum'], 2);
                    if (abs($oldSum - $sum) >= 0.01) {
                        PaymentsRepository::update((int)$existing['id'], [
                            'user_id' => $userId,
                            'dt' => (int)$existing['dt'],
                            'descr' => (string)$existing['descr'],
                            'direction' => 1,
                            'sum' => $sum,
                            'account' => 0,
                            'comment_id' => (int)($existing['comment_id'] ?? 0),
                            'is_partner' => (int)($existing['is_partner'] ?? 0),
                            'admin_inworked' => (int)($existing['admin_inworked'] ?? 0),
                            'dt_admin_inworked' => (int)($existing['dt_admin_inworked'] ?? 0),
                            'admin_signed' => (int)($existing['admin_signed'] ?? 0),
                            'dt_admin_signed' => (int)($existing['dt_admin_signed'] ?? 0),
                            'accept_user' => (int)($existing['accept_user'] ?? 0),
                            'dt_accept' => (int)($existing['dt_accept'] ?? 0),
                        ]);
                        $log[] = date('j.m.Y', $dayTs) . ': ' . $oldSum . ' → ' . $sum;
                    }
                }
                continue;
            }

            $now = time();
            try {
                PaymentsRepository::create([
                    'user_id' => $userId,
                    'dt' => $dayTs + 23 * 3600,
                    'descr' => self::accrualDescr($dayTs),
                    'direction' => 1,
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
                    'accrual_key' => self::dayAccrualKey($dayTs),
                ]);
                $log[] = date('j.m.Y', $dayTs) . ': ' . $sum;
            } catch (Throwable $e) {
                // Unique accrual_key race: another request inserted first — treat as synced.
                if (!self::isDuplicateKey($e)) {
                    throw $e;
                }
            }
        }
        return $log;
    }
    /** @return list<array<string,mixed>> */
    public static function pendingRequests(int $operatorId = 0): array
    {
        if (!Database::tableExists('base_payments')) {
            return [];
        }
        $sql = "SELECT ID AS id, ID_USER AS user_id, DT AS dt, DESCR AS descr,
                       DIRECTION AS direction, SUM AS sum, ACCOUNT AS account,
                       ID_ADMIN_SIGNED AS admin_signed, DT_ADMIN_SIGNED AS dt_admin_signed
                FROM base_payments
                WHERE DIRECTION < 0 AND ACCOUNT = 2
                  AND (DT_ADMIN_SIGNED IS NULL OR DT_ADMIN_SIGNED = 0 OR DT_ADMIN_SIGNED = '')
                  AND DESCR LIKE '" . self::PAYOUT_PREFIX . "%'";
        $params = [];
        if ($operatorId > 0) {
            $sql .= ' AND ID_USER = ?';
            $params[] = $operatorId;
        }
        $sql .= ' ORDER BY DT ASC, ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function extractPin(string $descr): string
    {
        return preg_match('/\[\[PIN:(\d{4})\]\]/', $descr, $m) ? $m[1] : '';
    }

    public static function extractAdminId(string $descr): int
    {
        return preg_match('/\[\[ADMIN:(\d+)\]\]/', $descr, $m) ? (int)$m[1] : 0;
    }

    public static function isPayoutDescr(string $descr): bool
    {
        return strpos($descr, self::PAYOUT_PREFIX) === 0
            || (strpos($descr, '[[PIN:') !== false && strpos($descr, 'Выдача ФЛ') !== false);
    }

    public static function isAccrualDescr(string $descr): bool
    {
        return strpos($descr, self::ACCRUAL_PREFIX) === 0;
    }

    public static function payoutDescr(int $dayTs, string $pin, int $adminId = 0): string
    {
        $d = self::PAYOUT_PREFIX . date('j.m.Y', $dayTs) . ' [[PIN:' . $pin . ']]';
        if ($adminId > 0) {
            $d .= ' [[ADMIN:' . $adminId . ']]';
        }
        return $d;
    }

    public static function accrualDescr(int $dayTs): string
    {
        return self::ACCRUAL_PREFIX . date('j.m.Y', $dayTs);
    }

    public static function maskDescr(string $descr, bool $showPin = false): string
    {
        if (!$showPin) {
            $descr = (string)preg_replace('/\s*\[\[PIN:\d{4}\]\]/', '', $descr);
        }
        return (string)preg_replace('/\s*\[\[ADMIN:\d+\]\]/', '', $descr);
    }

    /**
     * @return array{ok:bool,msg:string,payment_id?:int,pin?:string,sum?:int,issuer_line?:string}
     */
    public static function createRequest(int $operatorId, int $payoutDayTs, int $actorId = 0, int $issuerId = 0): array
    {
        $actorId = $actorId > 0 ? $actorId : $operatorId;
        $gate = self::requestAvailable();
        if (!$gate['ok']) {
            return ['ok' => false, 'msg' => $gate['reason']];
        }
        if (!self::isDailyPayStaff($operatorId)) {
            return ['ok' => false, 'msg' => t('payments.bot.day_pay.only_daily')];
        }
        if (self::blockedUntilAfterShift($operatorId)) {
            return ['ok' => false, 'msg' => t('payments.bot.day_pay.after_shift')];
        }

        $locked = self::withUserLock('req', $operatorId, 10, static function () use ($operatorId, $payoutDayTs, $actorId, $issuerId): array {
            // Re-check under lock — prevents double payout on parallel requests.
            if (self::pendingRequests($operatorId) !== []) {
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.pending_exists')];
            }

            // Already under pay_req_* lock — do not nest GET_LOCK (MySQL <5.7.5 releases prior lock).
            self::syncOperatorUnlocked($operatorId, $actorId);

            $sum = self::maxRequestSum($operatorId);
            if ($sum < 100) {
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.low_balance')];
            }

            $wantStart = self::dayStart($payoutDayTs);
            $adminIdsOnDay = [];
            $okDate = false;
            foreach (self::availableDates(5) as $d) {
                if ((int)$d['ts'] === $wantStart) {
                    $okDate = true;
                    $adminIdsOnDay = $d['admin_ids'];
                    break;
                }
            }
            if (!$okDate) {
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.bad_date')];
            }
            if ($issuerId > 0) {
                if (!in_array($issuerId, $adminIdsOnDay, true)) {
                    return ['ok' => false, 'msg' => t('payments.bot.day_pay.bad_issuer')];
                }
            } else {
                if (count($adminIdsOnDay) !== 1) {
                    return ['ok' => false, 'msg' => t('payments.bot.day_pay.pick_issuer')];
                }
                $issuerId = (int)$adminIdsOnDay[0];
            }

            $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $descr = self::payoutDescr($wantStart, $pin, $issuerId);
            $now = time();
            $paymentId = PaymentsRepository::create([
                'user_id' => $operatorId,
                'dt' => $wantStart + 12 * 3600,
                'descr' => $descr,
                'direction' => -1,
                'sum' => $sum,
                'account' => 2,
                'dt_create' => $now,
                'user_signed' => $actorId,
                'comment_id' => 0,
                'is_partner' => 0,
                'admin_inworked' => $actorId,
                'dt_admin_inworked' => $now,
                'admin_signed' => 0,
                'dt_admin_signed' => 0,
                'accept_user' => 0,
                'dt_accept' => 0,
                'movement_linked' => 0,
            ]);

            if (PaymentsRepository::ledgerEnabled()) {
                $accountOverride = (int)self::settings()['account_id'];
                $dps = PaymentsRepository::createDpsByIds([$paymentId], $accountOverride);
                if ((int)($dps['done'] ?? 0) < 1) {
                    self::forceDeletePayment($paymentId);
                    $reason = $dps['skipped'][0]['reason'] ?? t('payments.bot.day_pay.dps_fail');
                    return ['ok' => false, 'msg' => (string)$reason];
                }
            }

            self::notifyIssuersOnDate(
                $wantStart,
                t('payments.bot.day_pay.notify_req', [
                    'name' => self::userName($operatorId),
                    'sum' => $sum,
                    'date' => date('d.m.Y', $wantStart),
                ])
            );

            $issuerLine = $issuerId > 0 ? self::contactLine($issuerId) : t('payments.bot.day_pay.issuer_none');
            return [
                'ok' => true,
                'msg' => t('payments.bot.day_pay.created', [
                    'pin' => $pin,
                    'sum' => $sum,
                    'date' => date('d.m.Y', $wantStart),
                    'issuer' => $issuerLine,
                ]),
                'payment_id' => $paymentId,
                'pin' => $pin,
                'sum' => $sum,
                'issuer_line' => $issuerLine,
            ];
        });

        return $locked ?? ['ok' => false, 'msg' => t('payments.bot.day_pay.busy')];
    }

    /** @return array{ok:bool,msg:string} */
    public static function cancelRequest(int $paymentId, int $operatorId): array
    {
        $pdo = Database::pdo();
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        try {
            try {
                $row = PaymentsRepository::findForUpdate($paymentId);
            } catch (Throwable $e) {
                // MyISAM / engines without row locks.
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                    $started = false;
                }
                $row = PaymentsRepository::find($paymentId);
            }
            if ($row === null || (int)$row['user_id'] !== $operatorId) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.not_found')];
            }
            if (!self::isPayoutDescr((string)$row['descr'])) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.not_payout')];
            }
            if ((int)($row['dt_admin_signed'] ?? 0) > 0 || (int)($row['admin_signed'] ?? 0) > 0) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.already_paid')];
            }
            // Re-read signed flags immediately before delete (covers unlocked fallback path).
            $fresh = PaymentsRepository::find($paymentId);
            if ($fresh === null
                || (int)($fresh['dt_admin_signed'] ?? 0) > 0
                || (int)($fresh['admin_signed'] ?? 0) > 0
            ) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.already_paid')];
            }
            self::forceDeletePayment($paymentId);
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        self::notifyIssuersOnDate(
            (int)$row['dt'],
            t('payments.bot.day_pay.notify_cancel', [
                'name' => self::userName($operatorId),
                'sum' => (float)$row['sum'],
                'date' => date('d.m.Y', (int)$row['dt']),
            ])
        );
        return ['ok' => true, 'msg' => t('payments.bot.day_pay.cancelled')];
    }

    /** @return array{ok:bool,msg:string} */
    public static function verifyPin(int $paymentId, string $pin, int $adminId): array
    {
        if (!self::isCashIssuer($adminId)) {
            return ['ok' => false, 'msg' => t('payments.bot.day_pay.unavailable')];
        }
        $pin = preg_replace('/\D/', '', $pin) ?? '';
        $pdo = Database::pdo();
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        try {
            try {
                $row = PaymentsRepository::findForUpdate($paymentId);
            } catch (Throwable $e) {
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                    $started = false;
                }
                $row = PaymentsRepository::find($paymentId);
            }
            if ($row === null) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.not_found')];
            }
            if (!self::isPayoutDescr((string)$row['descr'])) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.not_payout')];
            }
            if ((int)($row['dt_admin_signed'] ?? 0) > 0 || (int)($row['admin_signed'] ?? 0) > 0) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.already_confirmed')];
            }
            $real = self::extractPin((string)$row['descr']);
            if ($real === '' || $real !== $pin) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.bad_pin')];
            }
            // Conditional update: only confirm still-unsigned rows (atomic vs cancel).
            $now = time();
            $st = Database::pdo()->prepare(
                'UPDATE base_payments SET ID_ADMIN_SIGNED = ?, DT_ADMIN_SIGNED = ?
                 WHERE ID = ?
                   AND (DT_ADMIN_SIGNED IS NULL OR DT_ADMIN_SIGNED = 0 OR DT_ADMIN_SIGNED = \'\')
                   AND (ID_ADMIN_SIGNED IS NULL OR ID_ADMIN_SIGNED = 0)'
            );
            $st->execute([$adminId, $now, $paymentId]);
            if ($st->rowCount() < 1) {
                if ($started) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'msg' => t('payments.bot.day_pay.already_confirmed')];
            }
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'msg' => t('payments.bot.day_pay.confirmed')];
    }
    public static function userName(int $userId): string
    {
        $u = UsersRepository::find($userId);
        if ($u === null) {
            return 'ID ' . $userId;
        }
        $name = trim((string)($u['name'] ?? ''));
        return $name !== '' ? $name : (string)($u['login'] ?? ('ID ' . $userId));
    }

    public static function contactLine(int $userId): string
    {
        $u = UsersRepository::find($userId);
        $name = self::userName($userId);
        $tel = $u !== null ? trim((string)($u['tel'] ?? '')) : '';
        if ($tel !== '') {
            return $name . ', ' . t('payments.bot.day_pay.tel') . ' ' . $tel;
        }
        return $name;
    }

    /**
     * UI/JSON state for the cash-request panel on /payments (oper show_payments parity).
     *
     * @return array<string,mixed>|null
     */
    public static function panelForList(int $viewerId, int $filterUserId, bool $canSeeOthers, string $message = '', string $error = ''): ?array
    {
        if ($viewerId < 1) {
            return null;
        }
        $showRequest = self::isDailyPayStaff($viewerId) && (!$canSeeOthers || $filterUserId === $viewerId);
        $showIssuer = self::isCashIssuer($viewerId);
        $showDirectorNote = $canSeeOthers
            && $filterUserId > 0
            && $filterUserId !== $viewerId
            && self::isDailyPayStaff($filterUserId);
        if (!$showRequest && !$showIssuer && !$showDirectorNote) {
            return null;
        }
        $pendingMine = $showRequest ? self::pendingRequests($viewerId) : [];
        $pendingAll = $showIssuer ? self::pendingRequests(0) : [];
        $requestOptions = [];
        if ($showRequest && $pendingMine === []) {
            foreach (self::availableDates(5) as $d) {
                foreach ($d['admin_ids'] as $aid) {
                    $aid = (int)$aid;
                    $requestOptions[] = [
                        'value' => (int)$d['ts'] . '_' . $aid,
                        'label' => (string)$d['label'] . ' — ' . self::contactLine($aid),
                    ];
                }
            }
        }
        $pendingMineOut = [];
        foreach ($pendingMine as $p) {
            $issuerId = self::extractAdminId((string)$p['descr']);
            $pendingMineOut[] = [
                'id' => (int)$p['id'],
                'sum' => (float)$p['sum'],
                'dt' => (int)$p['dt'],
                'pin' => self::extractPin((string)$p['descr']),
                'issuer_line' => $issuerId > 0 ? self::contactLine($issuerId) : '',
            ];
        }
        $pendingAllOut = [];
        foreach ($pendingAll as $p) {
            $uid = (int)$p['user_id'];
            $issuerId = self::extractAdminId((string)$p['descr']);
            $pendingAllOut[] = [
                'id' => (int)$p['id'],
                'sum' => (float)$p['sum'],
                'dt' => (int)$p['dt'],
                'name' => self::contactLine($uid),
                'issuer_line' => $issuerId > 0 ? self::contactLine($issuerId) : '—',
            ];
        }
        return [
            'show_request' => $showRequest,
            'show_issuer' => $showIssuer,
            'show_director_note' => $showDirectorNote,
            'balance' => $showRequest ? self::balance($viewerId) : 0.0,
            'max_sum' => $showRequest ? self::maxRequestSum($viewerId) : 0,
            'gate' => $showRequest ? self::requestAvailable() : ['ok' => false, 'reason' => ''],
            'blocked' => $showRequest && self::blockedUntilAfterShift($viewerId),
            'pending_mine' => $pendingMineOut,
            'pending_all' => $pendingAllOut,
            'request_options' => $requestOptions,
            'message' => $message,
            'error' => $error,
        ];
    }

    private static function dayStart(int $ts): int
    {
        return (int)mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
    }

    /** @return list<int> */
    private static function staffShiftDays(int $userId): array
    {
        if ($userId < 1 || !Database::tableExists('base_workweeks')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT DT AS dt FROM base_workweeks WHERE ID_OPERATOR = ? ORDER BY DT ASC'
        );
        $st->execute([$userId]);
        $days = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $start = self::dayStart((int)$row['dt']);
            $days[$start] = $start;
        }
        return array_values($days);
    }

    /** @return array<string,mixed>|null */
    private static function findAccrual(int $userId, int $dayTs): ?array
    {
        $dayStart = self::dayStart($dayTs);
        $dayEnd = $dayStart + 86400 - 1;
        $cols = 'ID AS id, ID_USER AS user_id, DT AS dt, DESCR AS descr,
                 DIRECTION AS direction, SUM AS sum, ACCOUNT AS account,
                 ID_COMMENT AS comment_id, IS_PARTNER_PAYMENT AS is_partner,
                 ID_ADMIN_INWORKED AS admin_inworked, DT_ADMIN_INWORKED AS dt_admin_inworked,
                 ID_ADMIN_SIGNED AS admin_signed, DT_ADMIN_SIGNED AS dt_admin_signed,
                 ID_ACCEPT_USER_SIGNED AS accept_user, DT_ACCEPT_USER_SIGNED AS dt_accept';
        if (Database::columnExists('base_payments', 'ACCRUAL_KEY')) {
            $st = Database::pdo()->prepare(
                "SELECT $cols FROM base_payments
                 WHERE ID_USER = ? AND ACCRUAL_KEY = ?
                 ORDER BY ID DESC LIMIT 1"
            );
            $st->execute([$userId, self::dayAccrualKey($dayStart)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        $descr = self::accrualDescr($dayStart);
        $st = Database::pdo()->prepare(
            "SELECT $cols FROM base_payments
             WHERE ID_USER = ? AND CAST(DIRECTION AS SIGNED) > 0
               AND DT >= ? AND DT <= ? AND DESCR LIKE ?
             ORDER BY ID DESC LIMIT 1"
        );
        $st->execute([$userId, $dayStart, $dayEnd, $descr . '%']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function dayAccrualKey(int $dayTs): string
    {
        return 'day:' . date('Y-m-d', self::dayStart($dayTs));
    }

    /**
     * Named MySQL lock for payroll mutations on one staff member.
     *
     * @template T
     * @param callable():T $fn
     * @return T|null null when lock not acquired
     */
    public static function withUserLock(string $kind, int $userId, int $timeoutSec, callable $fn)
    {
        if ($userId < 1) {
            return $fn();
        }
        $name = 'pay_' . preg_replace('/[^a-z0-9_]/i', '', $kind) . '_' . $userId;
        if (strlen($name) > 64) {
            $name = substr($name, 0, 64);
        }
        $pdo = Database::pdo();
        $got = false;
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $st->execute([$name, max(0, $timeoutSec)]);
            $got = ((int)$st->fetchColumn() === 1);
            if (!$got) {
                return null;
            }
            return $fn();
        } finally {
            if ($got) {
                try {
                    $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $rel->execute([$name]);
                } catch (Throwable $e) {
                    // ignore unlock errors
                }
            }
        }
    }

    private static function isDuplicateKey(Throwable $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'Duplicate') !== false
            || stripos($msg, '1062') !== false
            || (method_exists($e, 'getCode') && (string)$e->getCode() === '23000');
    }

    private static function hasDayWriteoff(int $userId, int $dayTs): bool
    {
        $dayStart = self::dayStart($dayTs);
        $dayEnd = $dayStart + 86400 - 1;
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_payments
             WHERE ID_USER = ? AND CAST(DIRECTION AS SIGNED) < 0
               AND DT >= ? AND DT <= ?
             LIMIT 1'
        );
        $st->execute([$userId, $dayStart, $dayEnd]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function forceDeletePayment(int $paymentId): void
    {
        if ($paymentId < 1) {
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
        $pdo->prepare('DELETE FROM base_payments WHERE ID = ?')->execute([$paymentId]);
    }

    private static function notifyIssuersOnDate(int $dayTs, string $text): void
    {
        if (!class_exists('BotSend', false)) {
            return;
        }
        $dayStart = self::dayStart($dayTs);
        $dayEnd = $dayStart + 86400;
        $admins = self::cashIssuerIds();
        if ($admins === []) {
            return;
        }
        $targets = [];
        if (Database::tableExists('base_workweeks')) {
            $ph = implode(',', array_fill(0, count($admins), '?'));
            $params = $admins;
            $params[] = $dayStart;
            $params[] = $dayEnd;
            $st = Database::pdo()->prepare(
                "SELECT DISTINCT ID_OPERATOR AS id FROM base_workweeks
                 WHERE ID_OPERATOR IN ($ph) AND DT >= ? AND DT < ?"
            );
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $targets[] = (int)$row['id'];
            }
        }
        if ($targets === []) {
            $targets = $admins;
        }
        foreach ($targets as $aid) {
            try {
                BotSend::enqueue([
                    'audience' => 'staff',
                    'user_id' => $aid,
                    'text' => $text,
                    'name' => 'day_pay_' . $aid . '_' . $dayStart,
                    'type' => 12,
                ]);
            } catch (Throwable $e) {
                // soft
            }
        }
    }
}
