<?php
declare(strict_types=1);

/**
 * Freelance (ID_STATUS=18) cash payout requests with PIN (oper freelance_payout_lib).
 * Settings: cfg/modules/payments.ini → freelance_* keys.
 */
final class FreelancePayout
{
    public static function settings(): array
    {
        return [
            'min_pay' => max(0, (int)Config::get('payments', 'freelance_min_pay', '3000')),
            'account_id' => max(0, (int)Config::get('payments', 'freelance_payout_account_id', '0')),
            'senior_status2' => max(0, (int)Config::get('payments', 'freelance_senior_status2', '17')),
        ];
    }

    public static function isFreelancer(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $u = UsersRepository::find($userId);
        if ($u === null || (string)($u['enabled'] ?? '') !== '1') {
            return false;
        }
        return (int)($u['ID_STATUS'] ?? $u['status_id'] ?? 0) === 18;
    }

    public static function isSeniorAdmin(int $userId): bool
    {
        $status2 = (int)self::settings()['senior_status2'];
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
    public static function seniorAdminIds(): array
    {
        $status2 = (int)self::settings()['senior_status2'];
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
        $adminIds = self::seniorAdminIds();
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
            $start = (int)mktime(0, 0, 0, (int)date('n', (int)$row['dt']), (int)date('j', (int)$row['dt']), (int)date('Y', (int)$row['dt']));
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
        if ((int)self::settings()['senior_status2'] < 1) {
            return ['ok' => false, 'reason' => t('payments.bot.fl.unavailable')];
        }
        if (self::seniorAdminIds() === []) {
            return ['ok' => false, 'reason' => t('payments.bot.fl.unavailable')];
        }
        if (self::availableDates(1) === []) {
            return ['ok' => false, 'reason' => t('payments.bot.fl.unavailable')];
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
                  AND DESCR LIKE 'Выдача ФЛ на %'";
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
        return strpos($descr, 'Выдача ФЛ на ') === 0
            || (strpos($descr, '[[PIN:') !== false && strpos($descr, 'Выдача ФЛ') !== false);
    }

    public static function payoutDescr(int $dayTs, string $pin, int $adminId = 0): string
    {
        $d = 'Выдача ФЛ на ' . date('j.m.Y', $dayTs) . ' [[PIN:' . $pin . ']]';
        if ($adminId > 0) {
            $d .= ' [[ADMIN:' . $adminId . ']]';
        }
        return $d;
    }

    public static function maskDescr(string $descr, bool $showPin = false): string
    {
        if (!$showPin) {
            $descr = (string)preg_replace('/\s*\[\[PIN:\d{4}\]\]/', '', $descr);
        }
        return (string)preg_replace('/\s*\[\[ADMIN:\d+\]\]/', '', $descr);
    }

    /** @return array{ok:bool,msg:string,payment_id?:int,pin?:string,sum?:int,issuer_line?:string} */
    public static function createRequest(int $operatorId, int $payoutDayTs, int $actorId = 0): array
    {
        $actorId = $actorId > 0 ? $actorId : $operatorId;
        $gate = self::requestAvailable();
        if (!$gate['ok']) {
            return ['ok' => false, 'msg' => $gate['reason']];
        }
        if (!self::isFreelancer($operatorId)) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.only_fl')];
        }
        if (self::blockedUntilAfterShift($operatorId)) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.after_shift')];
        }
        if (self::pendingRequests($operatorId) !== []) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.pending_exists')];
        }
        $sum = self::maxRequestSum($operatorId);
        if ($sum < 100) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.low_balance')];
        }

        $wantStart = (int)mktime(0, 0, 0, (int)date('n', $payoutDayTs), (int)date('j', $payoutDayTs), (int)date('Y', $payoutDayTs));
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
            return ['ok' => false, 'msg' => t('payments.bot.fl.bad_date')];
        }

        $issuerId = $adminIdsOnDay !== [] ? (int)$adminIdsOnDay[0] : 0;
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $descr = self::payoutDescr($wantStart, $pin, $issuerId);
        $now = time();
        $paymentId = PaymentsRepository::create([
            'user_id' => $operatorId,
            'dt' => $wantStart + 12 * 3600,
            'descr' => $descr,
            'direction' => -1,
            'sum' => $sum,
            'type_id' => 1,
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
            $dps = PaymentsRepository::createDpsByIds([$paymentId]);
            if ((int)($dps['done'] ?? 0) < 1) {
                self::forceDeletePayment($paymentId);
                $reason = $dps['skipped'][0]['reason'] ?? t('payments.bot.fl.dps_fail');
                return ['ok' => false, 'msg' => (string)$reason];
            }
        }

        self::notifySeniorsOnDate(
            $wantStart,
            t('payments.bot.fl.notify_req', [
                'name' => self::userName($operatorId),
                'sum' => $sum,
                'date' => date('d.m.Y', $wantStart),
            ])
        );

        $issuerLine = $issuerId > 0 ? self::contactLine($issuerId) : t('payments.bot.fl.issuer_none');
        return [
            'ok' => true,
            'msg' => t('payments.bot.fl.created', [
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
    }

    /** @return array{ok:bool,msg:string} */
    public static function cancelRequest(int $paymentId, int $operatorId): array
    {
        $row = PaymentsRepository::find($paymentId);
        if ($row === null || (int)$row['user_id'] !== $operatorId) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.not_found')];
        }
        if (!self::isPayoutDescr((string)$row['descr'])) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.not_payout')];
        }
        if ((int)($row['dt_admin_signed'] ?? 0) > 0 || (int)($row['admin_signed'] ?? 0) > 0) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.already_paid')];
        }
        self::forceDeletePayment($paymentId);
        self::notifySeniorsOnDate(
            (int)$row['dt'],
            t('payments.bot.fl.notify_cancel', [
                'name' => self::userName($operatorId),
                'sum' => (float)$row['sum'],
                'date' => date('d.m.Y', (int)$row['dt']),
            ])
        );
        return ['ok' => true, 'msg' => t('payments.bot.fl.cancelled')];
    }

    /** @return array{ok:bool,msg:string} */
    public static function verifyPin(int $paymentId, string $pin, int $adminId): array
    {
        if (!self::isSeniorAdmin($adminId)) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.unavailable')];
        }
        $row = PaymentsRepository::find($paymentId);
        if ($row === null) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.not_found')];
        }
        if (!self::isPayoutDescr((string)$row['descr'])) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.not_payout')];
        }
        if ((int)($row['dt_admin_signed'] ?? 0) > 0 || (int)($row['admin_signed'] ?? 0) > 0) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.already_confirmed')];
        }
        $pin = preg_replace('/\D/', '', $pin) ?? '';
        $real = self::extractPin((string)$row['descr']);
        if ($real === '' || $real !== $pin) {
            return ['ok' => false, 'msg' => t('payments.bot.fl.bad_pin')];
        }
        $now = time();
        PaymentsRepository::update($paymentId, [
            'user_id' => (int)$row['user_id'],
            'dt' => (int)$row['dt'],
            'descr' => (string)$row['descr'],
            'direction' => (int)$row['direction'],
            'sum' => (float)$row['sum'],
            'type_id' => (int)$row['type_id'],
            'account' => (int)$row['account'],
            'comment_id' => (int)($row['comment_id'] ?? 0),
            'is_partner' => (int)($row['is_partner'] ?? 0),
            'admin_inworked' => (int)($row['admin_inworked'] ?? 0),
            'dt_admin_inworked' => (int)($row['dt_admin_inworked'] ?? 0),
            'admin_signed' => $adminId,
            'dt_admin_signed' => $now,
            'accept_user' => (int)($row['accept_user'] ?? 0),
            'dt_accept' => (int)($row['dt_accept'] ?? 0),
        ]);
        return ['ok' => true, 'msg' => t('payments.bot.fl.confirmed')];
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
            return $name . ', ' . t('payments.bot.fl.tel') . ' ' . $tel;
        }
        return $name;
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

    private static function notifySeniorsOnDate(int $dayTs, string $text): void
    {
        if (!class_exists('BotSend', false)) {
            return;
        }
        $dayStart = (int)mktime(0, 0, 0, (int)date('n', $dayTs), (int)date('j', $dayTs), (int)date('Y', $dayTs));
        $dayEnd = $dayStart + 86400;
        $admins = self::seniorAdminIds();
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
                    'name' => 'fl_payout_' . $aid . '_' . $dayStart,
                    'type' => 12,
                ]);
            } catch (Throwable $e) {
                // soft
            }
        }
    }
}
