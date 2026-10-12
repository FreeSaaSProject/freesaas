<?php
declare(strict_types=1);

/**
 * Full qr2.php state machine: activate → reprint → revision → movement → writeoff/cancel.
 * Mutations only via apply() (POST); inspect() is read-only for GET / agents.
 */
final class QrScanService
{
    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public static function inspect(int $userId, string $hash, array $req = []): array
    {
        return self::run($userId, $hash, $req, false);
    }

    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public static function apply(int $userId, string $hash, array $req): array
    {
        return self::run($userId, $hash, $req, true);
    }

    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    private static function run(int $userId, string $hash, array $req, bool $mutate): array
    {
        $out = self::baseOut($hash);
        $hash = strtolower(trim($hash));
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
            $out['error'] = t('stickers.qr.err.hash');
            return $out;
        }

        $token = trim((string)($req['token'] ?? ''));
        if ($token === '' || !QrScanToken::isValidFormat($token)) {
            $token = QrScanToken::mint();
        }
        $out['token'] = $token;
        if (QrScanToken::isUsed($token)) {
            $out['error'] = t('stickers.qr.err.token_used');
            $out['phase'] = 'token_used';
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.err.token_used')];
            return $out;
        }

        $row = StickerInsRepository::findByHash($hash);
        if ($row === null) {
            $out['error'] = t('stickers.qr.err.not_found');
            return $out;
        }
        $ctx = self::context($row, $userId);
        $out = array_merge($out, $ctx);
        $out['token'] = $token;
        $out['messages'][] = [
            'level' => 'ok',
            'text' => t('stickers.qr.welcome', ['name' => $ctx['user_name']]),
        ];

        // 1) Activate
        if ((int)$row['dt_print'] < 1) {
            return self::phaseActivate($out, $row, $userId, $token, $req, $mutate, $ctx);
        }
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.activated_ok')];

        // 2) Reprint
        if ((int)$row['dt_reprint'] > 0) {
            return self::phaseReprint($out, $row, $userId, $token, $mutate, $ctx);
        }
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.reprint_ok')];

        // 3) Revision
        if ((int)$row['dt_revision'] > 0) {
            return self::phaseRevision($out, $row, $userId, $token, $req, $mutate, $ctx);
        }
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.revision_idle')];

        // 4) Movement
        if ((int)$row['dt_movement'] > 0) {
            return self::phaseMovement($out, $row, $userId, $token, $req, $mutate, $ctx);
        }
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.movement_idle')];

        // 5) Writeoff / cancel
        return self::phaseWriteoff($out, $row, $userId, $token, $req, $mutate, $ctx);
    }

    /** @return array<string,mixed> */
    private static function baseOut(string $hash): array
    {
        return [
            'ok' => false,
            'phase' => 'error',
            'hash' => strtolower(trim($hash)),
            'token' => '',
            'error' => '',
            'done' => false,
            'messages' => [],
            'forms' => [],
            'actions' => [],
            'meta' => [],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function context(array $row, int $userId): array
    {
        $product = class_exists('ProductsRepository', false)
            ? ProductsRepository::find((int)$row['id_good'])
            : null;
        $productName = $product ? (string)$product['name'] : ('#' . (int)$row['id_good']);
        $unitUseId = $product ? (int)($product['unit_use_id'] ?? 0) : 0;
        $weightReq = false;
        $unitName = '';
        if ($unitUseId > 0 && class_exists('UnitsRepository', false)) {
            $unit = UnitsRepository::find($unitUseId);
            if ($unit) {
                $weightReq = !empty($unit['weight_req']);
                $unitName = (string)$unit['name'];
            }
        }
        $stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];
        $stockName = $stocks[(int)$row['id_stock']] ?? ('#' . (int)$row['id_stock']);
        $user = UsersRepository::find($userId);
        $userName = $user
            ? (string)(($user['name'] ?? '') !== '' ? $user['name'] : ($user['login'] ?? $userId))
            : (string)$userId;

        $lastWeight = null;
        $openOutId = 0;
        $openOutActId = 0;
        if ($weightReq) {
            $lastWeight = self::lastWeight($row);
            $open = self::openOut((int)$row['id']);
            $openOutId = (int)($open['id'] ?? 0);
            $openOutActId = (int)($open['id_act'] ?? 0);
        }
        $oldOut = self::lastOut((int)$row['id']);

        return [
            'ok' => true,
            'sticker_id' => (int)$row['id'],
            'product_name' => $productName,
            'stock_name' => $stockName,
            'stock_id' => (int)$row['id_stock'],
            'user_name' => $userName,
            'remaining' => StickerInsRepository::remaining($row),
            'qqr' => max(0.0001, (float)$row['qty_qtyu_rate']),
            'weight_req' => $weightReq,
            'unit_name' => $unitName,
            'last_weight' => $lastWeight,
            'open_out_id' => $openOutId,
            'open_out_act_id' => $openOutActId,
            'has_old_out' => $oldOut !== null,
            'old_out_today' => $oldOut !== null && self::isToday((int)$oldOut['dt_create']),
            'dt_expire' => (int)$row['dt_expire'],
            'can_revise' => QrScanGate::canRevise(),
            'can_cancel_any' => StickerWriteoff::canCancelAnyDay(),
            'events_my_only' => QrScanGate::eventsMyOnly(),
            'meta' => [
                'dt_print' => (int)$row['dt_print'],
                'dt_revision' => (int)$row['dt_revision'],
                'dt_movement' => (int)$row['dt_movement'],
                'dt_reprint' => (int)$row['dt_reprint'],
                'pin' => (string)$row['pin'],
            ],
        ];
    }

    /** @param array<string,mixed> $row */
    private static function lastWeight(array $row): float
    {
        $st = Database::pdo()->prepare(
            'SELECT WEIGHT_AFTER FROM base_good_sticker_outs
             WHERE ID_STICKER = ? AND WEIGHT_AFTER > 0
             ORDER BY DT_CREATE DESC LIMIT 1'
        );
        $st->execute([(int)$row['id']]);
        $w = $st->fetchColumn();
        if ($w !== false && $w !== null && (float)$w > 0) {
            return (float)$w;
        }
        return (float)$row['weight_start'];
    }

    /** @return array{id:int,id_act:int}|null */
    private static function openOut(int $stickerId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ACT AS id_act FROM base_good_sticker_outs
             WHERE ID_STICKER = ? AND WEIGHT_BEFORE > 0
               AND (WEIGHT_AFTER IS NULL OR WEIGHT_AFTER = 0)
             ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$stickerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @return array<string,mixed>|null */
    private static function lastOut(int $stickerId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, QTYU AS qtyu, ID_ACT AS id_act, DESCR AS descr
             FROM base_good_sticker_outs WHERE ID_STICKER = ? ORDER BY DT_CREATE DESC LIMIT 1'
        );
        $st->execute([$stickerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function isToday(int $ts): bool
    {
        if ($ts < 1) {
            return false;
        }
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00'));
        $dayEnd = (int)strtotime(date('Y-m-d 23:59:59'));
        return $ts >= $dayStart && $ts <= $dayEnd;
    }

    private static function appendLog(string $log, int $userId, string $msg): string
    {
        return StickerInsRepository::appendLog($log, $userId, $msg);
    }

    private static function logTag(int $userId): string
    {
        return '[' . $userId . '][' . date('d.m.Y H:i') . '] ';
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $req
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function phaseActivate(
        array $out,
        array $row,
        int $userId,
        string $token,
        array $req,
        bool $mutate,
        array $ctx
    ): array {
        $out['phase'] = 'activate';
        if (!$ctx['can_revise']) {
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.activate_need_revi')];
            $out['error'] = t('stickers.qr.activate_need_revi');
            return $out;
        }
        $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.activate_pending')];
        $weight = self::floatReq($req, 'weight');
        $needWeight = !empty($ctx['weight_req']) && (float)$row['weight_start'] <= 0;
        if ($needWeight && $weight <= 0) {
            $out['forms'][] = ['type' => 'weight', 'name' => 'weight', 'label' => t('stickers.qr.form.weight_start')];
            return $out;
        }
        if (!$mutate) {
            $out['actions'][] = ['action' => 'activate', 'label' => t('stickers.qr.action.activate')];
            if ($needWeight) {
                $out['forms'][] = ['type' => 'weight', 'name' => 'weight', 'label' => t('stickers.qr.form.weight_start')];
            }
            return $out;
        }
        $wStart = $needWeight ? $weight : (float)$row['weight_start'];
        $log = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.activated'));
        if ($needWeight) {
            $log .= ' ' . t('stickers.qr.log.weight', ['w' => $wStart]);
        }
        Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET LOG = ?, WEIGHT_START = ?, DT_PRINT = ?, TOKEN = ? WHERE ID = ?'
        )->execute([$log, $wStart, time(), $token, (int)$row['id']]);
        $out['done'] = true;
        $out['ok'] = true;
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.activate_done')];
        return $out;
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function phaseReprint(
        array $out,
        array $row,
        int $userId,
        string $token,
        bool $mutate,
        array $ctx
    ): array {
        $out['phase'] = 'reprint';
        if (!$ctx['can_revise']) {
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.reprint_need_revi')];
            $out['error'] = t('stickers.qr.reprint_need_revi');
            return $out;
        }
        $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.reprint_pending')];
        if (!$mutate) {
            $out['actions'][] = ['action' => 'reprint', 'label' => t('stickers.qr.action.reprint')];
            return $out;
        }
        $log = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.reactivated'));
        Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET LOG = ?, DT_REPRINT = 0, TOKEN = ? WHERE ID = ?'
        )->execute([$log, $token, (int)$row['id']]);
        $out['done'] = true;
        $out['ok'] = true;
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.reprint_done')];
        return $out;
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $req
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function phaseRevision(
        array $out,
        array $row,
        int $userId,
        string $token,
        array $req,
        bool $mutate,
        array $ctx
    ): array {
        $out['phase'] = 'revision';
        if (!$ctx['can_revise']) {
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.revision_blocked')];
            $out['error'] = t('stickers.qr.revision_blocked');
            return $out;
        }
        $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.revision_pending')];
        if (!empty($ctx['open_out_id'])) {
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.revision_open_out')];
            $out['error'] = t('stickers.qr.revision_open_out');
            return $out;
        }

        $do = (int)($req['do'] ?? 0);
        $confirm = !empty($req['confirm']) || $do === 5;

        if (!empty($ctx['weight_req'])) {
            $last = (float)$ctx['last_weight'];
            $weight = self::floatReq($req, 'weight');
            if ($do === 2) {
                $weight = $last - (float)$ctx['remaining'];
            }
            if ($weight <= 0 && $do !== 2 && $do !== 5) {
                $out['forms'][] = ['type' => 'weight', 'name' => 'weight', 'label' => t('stickers.qr.form.weight_revi')];
                if ($ctx['can_cancel_any']) {
                    $out['messages'][] = ['level' => 'info', 'text' => t('stickers.qr.last_weight', ['w' => $last])];
                }
                return $out;
            }
            if (abs($weight - $last) < 0.001) {
                if (!$mutate) {
                    $out['actions'][] = ['action' => 'revision_pass', 'label' => t('stickers.qr.action.revision_ok')];
                    return $out;
                }
                return self::clearRevision($out, $row, $userId, $token, t('stickers.qr.log.revision_pass_w', [
                    'w' => $weight,
                    'prev' => $last,
                ]));
            }
            if (!$confirm) {
                $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.revision_mismatch_w', [
                    'w' => $weight,
                    'prev' => $last,
                ])];
                $out['forms'][] = ['type' => 'weight', 'name' => 'weight', 'label' => t('stickers.qr.form.weight_revi'), 'value' => $weight];
                $out['actions'][] = [
                    'action' => 'revision_fix',
                    'label' => t('stickers.qr.action.revision_fix'),
                    'fields' => ['weight' => $weight, 'confirm' => 1, 'do' => 5],
                ];
                return $out;
            }
            if (!$mutate) {
                $out['actions'][] = [
                    'action' => 'revision_fix',
                    'label' => t('stickers.qr.action.revision_fix'),
                    'fields' => ['weight' => $weight, 'confirm' => 1, 'do' => 5],
                ];
                return $out;
            }
            self::revisionAdjust($row, $userId, $token, $last, $weight, true);
            $out['done'] = true;
            $out['ok'] = true;
            $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.revision_fixed')];
            return $out;
        }

        // Non-weight
        $qqr = (float)$ctx['qqr'];
        $lastAmt = (float)$ctx['remaining'];
        if ($qqr > 1 || $do === 2 || $do === 5) {
            $amountRaw = $req['amount'] ?? '';
            $amount = self::floatReq($req, 'amount');
            if ($do === 2) {
                $amount = 0.0;
            } elseif ($amountRaw === '' && $do !== 5) {
                $out['forms'][] = ['type' => 'amount', 'name' => 'amount', 'label' => t('stickers.qr.form.amount_revi')];
                return $out;
            }
            if (abs($amount - $lastAmt) < 0.001) {
                if (!$mutate) {
                    $out['actions'][] = ['action' => 'revision_pass', 'label' => t('stickers.qr.action.revision_ok')];
                    return $out;
                }
                return self::clearRevision($out, $row, $userId, $token, t('stickers.qr.log.revision_pass_a', [
                    'a' => $amount,
                    'prev' => $lastAmt,
                ]));
            }
            if (!$confirm) {
                $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.revision_mismatch_a', [
                    'a' => $amount,
                    'prev' => $lastAmt,
                ])];
                $out['forms'][] = ['type' => 'amount', 'name' => 'amount', 'label' => t('stickers.qr.form.amount_revi'), 'value' => $amount];
                $out['actions'][] = [
                    'action' => 'revision_fix',
                    'label' => t('stickers.qr.action.revision_fix'),
                    'fields' => ['amount' => $amount, 'confirm' => 1, 'do' => 5],
                ];
                return $out;
            }
            if (!$mutate) {
                return $out;
            }
            self::revisionAdjust($row, $userId, $token, $lastAmt, $amount, false);
            $out['done'] = true;
            $out['ok'] = true;
            $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.revision_fixed')];
            return $out;
        }

        // Piece goods: pass
        if (!$mutate) {
            $out['actions'][] = ['action' => 'revision_pass', 'label' => t('stickers.qr.action.revision_ok')];
            return $out;
        }
        return self::clearRevision($out, $row, $userId, $token, t('stickers.qr.log.revision_piece'));
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function clearRevision(
        array $out,
        array $row,
        int $userId,
        string $token,
        string $msg
    ): array {
        $log = self::appendLog((string)$row['log'], $userId, $msg);
        Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET LOG = ?, DT_REVISION = 0, TOKEN = ? WHERE ID = ?'
        )->execute([$log, $token, (int)$row['id']]);
        $out['done'] = true;
        $out['ok'] = true;
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.revision_cleared')];
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function revisionAdjust(
        array $row,
        int $userId,
        string $token,
        float $before,
        float $after,
        bool $weighted
    ): void {
        $delta = round($before - $after, 2);
        $descrKey = $weighted ? 'stickers.qr.act.revi_w' : 'stickers.qr.act.revi_nw';
        $actId = self::getOrCreateNamedAct(
            $userId,
            (int)$row['id_stock'],
            t($descrKey),
            true
        );
        $rate = max(0.0001, (float)$row['qty_qtyu_rate']);
        $sum = round(((float)$row['price'] * $delta) / $rate, 2);
        $line = $delta < 0
            ? t('stickers.qr.out.revi_surplus')
            : t('stickers.qr.out.revi_shortage');
        $pdo = Database::pdo();
        $sql = 'INSERT INTO base_good_sticker_outs
                (DT_CREATE, ID_USER, ID_USER_AFTER, ID_GOOD, ID_STOCK, ID_ACT, ID_STICKER,
                 QTYU, SUM, DESCR, WEIGHT_BEFORE, WEIGHT_AFTER, TOKEN)
                VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $pdo->prepare($sql)->execute([
            time(),
            $userId,
            (int)$row['id_good'],
            (int)$row['id_stock'],
            $actId,
            (int)$row['id'],
            $delta,
            $sum,
            $line,
            $weighted ? $before : 0,
            $weighted ? $after : 0,
            $token,
        ]);
        $log = self::appendLog((string)$row['log'], $userId, $line . ' ' . $delta);
        $pdo->prepare(
            'UPDATE base_good_sticker_ins SET QTYU_OUT = ?, DT_REVISION = 0, LOG = ?, TOKEN = ? WHERE ID = ?'
        )->execute([(float)$row['qtyu_out'] + $delta, $log, $token, (int)$row['id']]);
        self::recalcActSum($actId);
    }

    private static function getOrCreateNamedAct(int $userId, int $stockId, string $descr, bool $isRevi): int
    {
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00'));
        $dayEnd = (int)strtotime(date('Y-m-d 23:59:59'));
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_acts2_out
             WHERE ID_STOCK = ? AND ID_USER = ? AND DESCR = ?
               AND DT BETWEEN ? AND ? LIMIT 1'
        );
        $st->execute([$stockId, $userId, $descr, $dayStart, $dayEnd]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $ins = Database::pdo()->prepare(
            'INSERT INTO base_acts2_out
             (ID_EVENT, ID_PROC, ID_GOOD, ID_STOCK, ID_USER, DESCR, DT,
              DEFAULT_WARNING, ID_USER_SIGNED, DT_USER_SIGNED, SUMM_DEFAULT, SUMM_REAL,
              ID_COSM_CONNECTED, ID_COSM_SIGNED, DT_COSM_SIGNED, IS_REVI, THEREIS_INCOME, IS_STRANGE)
             VALUES (0, 0, 0, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, ?, 0, 0)'
        );
        $ins->execute([
            $stockId,
            $userId,
            $descr,
            time(),
            $isRevi ? t('stickers.qr.act.revi_warn') : '',
            $isRevi ? 1 : 0,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    private static function recalcActSum(int $actId): void
    {
        if ($actId < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(SUM),0) FROM base_good_sticker_outs WHERE ID_ACT = ?'
        );
        $st->execute([$actId]);
        $sum = round((float)$st->fetchColumn(), 2);
        Database::pdo()->prepare(
            'UPDATE base_acts2_out SET SUMM_REAL = ? WHERE ID = ?'
        )->execute([$sum, $actId]);
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $req
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function phaseMovement(
        array $out,
        array $row,
        int $userId,
        string $token,
        array $req,
        bool $mutate,
        array $ctx
    ): array {
        $out['phase'] = 'movement';
        if (!$ctx['can_revise']) {
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.movement_blocked')];
            $out['error'] = t('stickers.qr.movement_blocked');
            return $out;
        }
        $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.movement_pending')];
        $left = (float)$ctx['remaining'];
        if ($left <= 0) {
            if (!$mutate) {
                $out['actions'][] = ['action' => 'movement_clear', 'label' => t('stickers.qr.action.movement_clear')];
                return $out;
            }
            $log = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.movement_empty'));
            Database::pdo()->prepare(
                'UPDATE base_good_sticker_ins SET DT_MOVEMENT = 0, LOG = ? WHERE ID = ?'
            )->execute([$log, (int)$row['id']]);
            $out['done'] = true;
            $out['ok'] = true;
            $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.movement_cleared')];
            return $out;
        }
        $newStock = (int)($req['idStockNew'] ?? $req['id_stock_new'] ?? 0);
        $stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];
        unset($stocks[(int)$row['id_stock']]);
        if ($newStock < 1 || !isset($stocks[$newStock])) {
            $out['forms'][] = [
                'type' => 'stock',
                'name' => 'idStockNew',
                'label' => t('stickers.qr.form.stock_new'),
                'options' => $stocks,
            ];
            return $out;
        }
        if (!$mutate) {
            $out['actions'][] = [
                'action' => 'movement',
                'label' => t('stickers.qr.action.movement'),
                'fields' => ['idStockNew' => $newStock],
            ];
            $out['forms'][] = [
                'type' => 'stock',
                'name' => 'idStockNew',
                'label' => t('stickers.qr.form.stock_new'),
                'options' => $stocks,
                'value' => $newStock,
            ];
            return $out;
        }
        self::doMovement($row, $userId, $token, $newStock, (float)($ctx['last_weight'] ?? $row['weight_start']));
        $out['done'] = true;
        $out['ok'] = true;
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.movement_done', [
            'stock' => $stocks[$newStock] ?? (string)$newStock,
        ])];
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function doMovement(
        array $row,
        int $userId,
        string $token,
        int $newStockId,
        float $lastWeight
    ): void {
        $pdo = Database::pdo();
        $left = StickerInsRepository::remaining($row);
        $rate = max(0.0001, (float)$row['qty_qtyu_rate']);
        $sum = round(((float)$row['price'] * $left) / $rate, 2);
        $oldPin = (string)$row['pin'];
        $oldHash = (string)$row['hash'];

        $actInId = self::getOrCreateActIn($userId, $newStockId, (int)$row['id_supplier']);
        $actOutId = self::getOrCreateNamedAct(
            $userId,
            (int)$row['id_stock'],
            t('stickers.qr.act.movement_out'),
            false
        );

        $logOld = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.movement_old'));
        $pdo->prepare(
            'UPDATE base_good_sticker_ins SET QTYU_OUT = ?, PIN = ?, HASH = ?, DT_MOVEMENT = 0, LOG = ?
             WHERE ID = ?'
        )->execute([
            (float)$row['qtyu_in'],
            '0000',
            md5('0000' . (string)time()),
            $logOld,
            (int)$row['id'],
        ]);

        $logNew = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.movement_new'));
        $pdo->prepare(
            'INSERT INTO base_good_sticker_ins
             (ID_USER_CREATE, ID_ACT, ID_GOOD, ID_STOCK, ID_SUPPLIER, DT_CREATE, DT_EXPIRE,
              DT_REVISION, DT_MOVEMENT, DT_PRINT, DT_REPRINT, PIN, HASH, LOG, PRICE, QTY,
              QTYU_IN, QTYU_OUT, QTY_QTYU_RATE, DESCR_STOCK, DESCR, IS_STRANGE, WEIGHT_START, TOKEN)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, ?, ?, 1, ?, 0, ?, ?, ?, \'\', ?, ?)'
        )->execute([
            $userId,
            $actInId,
            (int)$row['id_good'],
            $newStockId,
            (int)$row['id_supplier'],
            time(),
            (int)$row['dt_expire'],
            (int)$row['dt_print'],
            $oldPin,
            $oldHash,
            $logNew,
            $sum,
            $left,
            $left,
            (string)$row['descr_stock'],
            t('stickers.qr.sticker.moved'),
            $lastWeight,
            $token,
        ]);

        $pdo->prepare(
            'INSERT INTO base_good_sticker_outs
             (DT_CREATE, ID_USER, ID_USER_AFTER, ID_GOOD, ID_STOCK, ID_ACT, ID_STICKER,
              QTYU, SUM, DESCR, WEIGHT_BEFORE, WEIGHT_AFTER, TOKEN)
             VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
        )->execute([
            time(),
            $userId,
            (int)$row['id_good'],
            (int)$row['id_stock'],
            $actOutId,
            (int)$row['id'],
            $left,
            $sum,
            t('stickers.qr.out.movement'),
            $token,
        ]);
        self::recalcActSum($actOutId);
        if (class_exists('Acts2InRepository', false)) {
            Acts2InRepository::recalcSumm($actInId);
        }
    }

    private static function getOrCreateActIn(int $userId, int $stockId, int $supplierId): int
    {
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00'));
        $dayEnd = (int)strtotime(date('Y-m-d 23:59:59'));
        $descr = t('stickers.qr.act.movement_in');
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_acts2_in
             WHERE ID_STOCK = ? AND ID_SUPPLIER = ? AND ID_USER_CREATE = ?
               AND DESCR = ? AND DT_CREATE BETWEEN ? AND ? LIMIT 1'
        );
        $st->execute([$stockId, $supplierId, $userId, $descr, $dayStart, $dayEnd]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        return Acts2InRepository::create([
            'id_supplier' => $supplierId,
            'id_stock' => $stockId,
            'descr' => $descr,
            'is_sticked' => 0,
            'is_signed' => 0,
        ], $userId);
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $req
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function phaseWriteoff(
        array $out,
        array $row,
        int $userId,
        string $token,
        array $req,
        bool $mutate,
        array $ctx
    ): array {
        $out['phase'] = 'writeoff';
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.writeoff_ready')];

        $expire = (int)$ctx['dt_expire'];
        if ($expire > 0) {
            if ($expire < time()) {
                $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.expired', [
                    'd' => date('d.m.Y', $expire),
                ])];
            } else {
                $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.expire_ok', [
                    'd' => date('d.m.Y', $expire),
                ])];
            }
        }

        $action = (string)($req['action'] ?? '');
        $doDismiss = !empty($req['doDismiss']) || $action === 'dismiss';
        $doCancel = !empty($req['doCancel']) || $action === 'cancel';
        $doForceRevi = !empty($req['doForceRevi']) || $action === 'force_revi';

        if ($doForceRevi && empty($ctx['open_out_id']) && !empty($ctx['has_old_out']) && $ctx['can_revise']) {
            if (empty($req['reallyDoForceRevi']) && $action !== 'force_revi_confirm') {
                $out['actions'][] = [
                    'action' => 'force_revi_confirm',
                    'label' => t('stickers.qr.action.force_revi_confirm'),
                    'fields' => ['doForceRevi' => 1, 'reallyDoForceRevi' => 1],
                ];
                $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.force_revi_ask')];
                return $out;
            }
            if ($mutate) {
                $log = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.force_revi'));
                Database::pdo()->prepare(
                    'UPDATE base_good_sticker_ins SET DT_REVISION = ?, LOG = ?, TOKEN = ? WHERE ID = ?'
                )->execute([time(), $log, $token, (int)$row['id']]);
                $out['done'] = true;
                $out['ok'] = true;
                $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.force_revi_done')];
                return $out;
            }
        }

        // Cancel path
        if ($doCancel || ($ctx['remaining'] <= 0 && empty($ctx['weight_req']) && (float)$ctx['qqr'] == 1.0)) {
            return self::phaseCancel($out, $row, $userId, $token, $req, $mutate, $ctx);
        }

        // Choice dismiss vs cancel for multi-unit
        if (!$doDismiss && empty($ctx['weight_req']) && (float)$ctx['qqr'] > 1
            && !empty($ctx['has_old_out'])
            && ($ctx['old_out_today'] || $ctx['can_cancel_any'])) {
            $out['actions'][] = ['action' => 'dismiss', 'label' => t('stickers.qr.action.dismiss')];
            $out['actions'][] = ['action' => 'cancel', 'label' => t('stickers.qr.action.cancel')];
            if ($ctx['has_old_out'] && empty($ctx['open_out_id']) && $ctx['can_revise']) {
                $out['actions'][] = ['action' => 'force_revi', 'label' => t('stickers.qr.action.force_revi')];
            }
            return $out;
        }

        if (!empty($ctx['weight_req']) && empty($ctx['open_out_id']) && !empty($ctx['has_old_out'])
            && empty($req['proc_event']) && empty($req['event_id']) && $ctx['can_revise']) {
            $out['actions'][] = ['action' => 'force_revi', 'label' => t('stickers.qr.action.force_revi')];
        }

        // Resolve act
        $actId = (int)($ctx['open_out_act_id'] ?? 0);
        if ($actId < 1) {
            $resolved = self::resolveActOut($out, $row, $userId, $req, $ctx);
            if ($resolved['need_form']) {
                return $resolved['out'];
            }
            $actId = (int)$resolved['act_id'];
            $out = $resolved['out'];
        }
        if ($actId < 1) {
            return $out;
        }

        return self::dismiss($out, $row, $userId, $token, $req, $mutate, $ctx, $actId);
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $req
     * @param array<string,mixed> $ctx
     * @return array{need_form:bool,act_id:int,out:array<string,mixed>}
     */
    private static function resolveActOut(
        array $out,
        array $row,
        int $userId,
        array $req,
        array $ctx
    ): array {
        $procEvent = trim((string)($req['proc_event'] ?? ''));
        $eventId = (int)($req['event_id'] ?? 0);
        $procId = (int)($req['proc_id'] ?? 0);
        if ($procEvent !== '') {
            $parts = explode('_', $procEvent);
            $eventId = (int)($parts[0] ?? 0);
            $procId = (int)($parts[1] ?? 0);
        }
        if ($eventId === 0 && $procId === 0 && $procEvent === '') {
            $events = self::todayEvents($userId, !empty($ctx['events_my_only']));
            $out['forms'][] = [
                'type' => 'proc_event',
                'name' => 'proc_event',
                'label' => t('stickers.qr.form.proc_event'),
                'events' => $events,
            ];
            return ['need_form' => true, 'act_id' => 0, 'out' => $out];
        }
        if ($eventId < 0 || $procId < 0) {
            return [
                'need_form' => false,
                'act_id' => Acts2OutRepository::getOrCreateFreeAct($userId, (int)$row['id_stock']),
                'out' => $out,
            ];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_acts2_out WHERE ID_EVENT = ? AND ID_GOOD = ? LIMIT 1'
        );
        $st->execute([$eventId, $procId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return ['need_form' => false, 'act_id' => $id, 'out' => $out];
        }
        $ins = Database::pdo()->prepare(
            'INSERT INTO base_acts2_out
             (ID_EVENT, ID_PROC, ID_GOOD, ID_STOCK, ID_USER, DESCR, DT,
              DEFAULT_WARNING, ID_USER_SIGNED, DT_USER_SIGNED, SUMM_DEFAULT, SUMM_REAL,
              ID_COSM_CONNECTED, ID_COSM_SIGNED, DT_COSM_SIGNED, IS_REVI, THEREIS_INCOME, IS_STRANGE)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'\', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)'
        );
        $ins->execute([
            $eventId,
            $eventId,
            $procId,
            (int)$row['id_stock'],
            $userId,
            t('stickers.writeoff.auto_act_descr'),
            time(),
        ]);
        return ['need_form' => false, 'act_id' => (int)Database::pdo()->lastInsertId(), 'out' => $out];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function todayEvents(int $userId, bool $myOnly): array
    {
        if (!class_exists('ProceduresRepository', false) || !ModuleManager::isEnabled('procedures')) {
            return [];
        }
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00'));
        $dayEnd = (int)strtotime(date('Y-m-d 23:59:59')) + 1;
        $staff = $myOnly ? $userId : null;
        $rows = ProceduresRepository::listForDayRange($dayStart, $dayEnd, $staff);
        $out = [];
        foreach ($rows as $r) {
            if ((int)($r['id_user'] ?? 0) < 1) {
                continue;
            }
            $out[] = [
                'id' => (int)$r['id'],
                'id_operator' => (int)$r['id_operator'],
                'id_good' => (int)$r['id_good'],
                'product_name' => (string)($r['product_name'] ?? ''),
                'client_name' => (string)($r['client_name'] ?? ''),
                'operator_name' => (string)($r['operator_name'] ?? ''),
                'dtstart' => (int)$r['dtstart'],
                'value' => (int)$r['id'] . '_' . (int)$r['id_good'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $req
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function dismiss(
        array $out,
        array $row,
        int $userId,
        string $token,
        array $req,
        bool $mutate,
        array $ctx,
        int $actId
    ): array {
        $pdo = Database::pdo();
        $rate = max(0.0001, (float)$row['qty_qtyu_rate']);
        $openOutId = (int)$ctx['open_out_id'];

        if (!empty($ctx['weight_req'])) {
            if ($openOutId > 0) {
                $weight2 = self::floatReq($req, 'weight2');
                if ($weight2 <= 0) {
                    $out['forms'][] = ['type' => 'weight', 'name' => 'weight2', 'label' => t('stickers.qr.form.weight_after')];
                    return $out;
                }
                if (!$mutate) {
                    $out['forms'][] = [
                        'type' => 'weight',
                        'name' => 'weight2',
                        'label' => t('stickers.qr.form.weight_after'),
                        'value' => $weight2,
                    ];
                    $out['actions'][] = [
                        'action' => 'dismiss',
                        'label' => t('stickers.qr.action.save_weight_after'),
                        'fields' => ['weight2' => $weight2, 'doDismiss' => 1],
                    ];
                    return $out;
                }
                $st = $pdo->prepare(
                    'SELECT WEIGHT_BEFORE AS weight_before, DESCR AS descr FROM base_good_sticker_outs WHERE ID = ?'
                );
                $st->execute([$openOutId]);
                $open = $st->fetch(PDO::FETCH_ASSOC);
                if (!$open) {
                    $out['error'] = t('stickers.qr.err.open_out');
                    return $out;
                }
                $qty = round((float)$open['weight_before'] - $weight2, 1);
                $strange = '';
                if ($qty == 0.0) {
                    $strange = t('stickers.qr.strange.zero');
                }
                if ($qty < 0) {
                    $qty = 0.0;
                    $strange = t('stickers.qr.strange.increase');
                }
                try {
                    self::commitWeightClose(
                        $row,
                        $userId,
                        $token,
                        $actId,
                        $openOutId,
                        $open,
                        $weight2,
                        $qty,
                        $rate,
                        $strange
                    );
                } catch (RuntimeException $e) {
                    $out['error'] = $e->getMessage();
                    $out['messages'][] = ['level' => 'err', 'text' => $e->getMessage()];
                    return $out;
                }
                $out['done'] = true;
                $out['ok'] = true;
                $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.writeoff.ok', ['qty' => $qty])];
                return $out;
            }

            // Open new weight out
            $weight = self::floatReq($req, 'weight');
            $last = (float)$ctx['last_weight'];
            $confirm = !empty($req['confirm']);
            if ($weight <= 0 || (abs($weight - $last) > 0.001 && !$confirm)) {
                if ($weight > 0 && abs($weight - $last) > 0.001) {
                    $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.weight_mismatch', [
                        'w' => $weight,
                        'prev' => $last,
                    ])];
                }
                $out['forms'][] = [
                    'type' => 'weight',
                    'name' => 'weight',
                    'label' => t('stickers.qr.form.weight_before'),
                    'value' => $weight > 0 ? $weight : '',
                    'confirm_needed' => $weight > 0 && abs($weight - $last) > 0.001,
                ];
                if ($ctx['can_cancel_any']) {
                    $out['messages'][] = ['level' => 'info', 'text' => t('stickers.qr.last_weight', ['w' => $last])];
                }
                return $out;
            }
            if (!$mutate) {
                $out['actions'][] = [
                    'action' => 'dismiss',
                    'label' => t('stickers.qr.action.save_weight_before'),
                    'fields' => ['weight' => $weight, 'confirm' => $confirm ? 1 : 0, 'doDismiss' => 1],
                ];
                return $out;
            }
            try {
                self::commitWeightOpen($row, $userId, $token, $actId, $weight, $last);
            } catch (RuntimeException $e) {
                $out['error'] = $e->getMessage();
                $out['messages'][] = ['level' => 'err', 'text' => $e->getMessage()];
                return $out;
            }
            $out['done'] = true;
            $out['ok'] = true;
            $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.qr.weight_open_done')];
            return $out;
        }

        // Non-weight amount
        $qqr = (float)$ctx['qqr'];
        $left = (float)$ctx['remaining'];
        $amount = $qqr == 1.0 ? 1.0 : self::floatReq($req, 'amount');
        $confirm = !empty($req['confirm']);
        if ($qqr != 1.0 && ($amount <= 0 || ($amount > $left && !$confirm))) {
            if ($amount > $left) {
                $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.amount_over', [
                    'a' => $amount,
                    'left' => $left,
                ])];
            }
            $out['forms'][] = [
                'type' => 'amount',
                'name' => 'amount',
                'label' => t('stickers.qr.form.amount'),
                'value' => $amount > 0 ? $amount : '',
                'confirm_needed' => $amount > $left,
            ];
            return $out;
        }
        if ($left <= 0) {
            $out['error'] = t('stickers.writeoff.err.empty');
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.writeoff.err.empty')];
            return $out;
        }
        if (!$mutate) {
            $out['actions'][] = [
                'action' => 'dismiss',
                'label' => t('stickers.qr.action.dismiss'),
                'fields' => [
                    'amount' => $amount,
                    'confirm' => $confirm ? 1 : 0,
                    'doDismiss' => 1,
                    'proc_event' => (string)($req['proc_event'] ?? ''),
                ],
            ];
            return $out;
        }
        try {
            self::commitAmountWriteoff($row, $userId, $token, $actId, $amount, $rate);
        } catch (RuntimeException $e) {
            $out['error'] = $e->getMessage();
            $out['messages'][] = ['level' => 'err', 'text' => $e->getMessage()];
            return $out;
        }
        $out['done'] = true;
        $out['ok'] = true;
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.writeoff.ok', ['qty' => $amount])];
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function commitWeightOpen(
        array $row,
        int $userId,
        string $token,
        int $actId,
        float $weight,
        float $last
    ): void {
        $pdo = Database::pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $st = $pdo->prepare(
                'SELECT ID, ID_GOOD, ID_STOCK FROM base_good_sticker_ins WHERE ID = ? FOR UPDATE'
            );
            $st->execute([(int)$row['id']]);
            $locked = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                throw new RuntimeException(t('stickers.qr.err.not_found'));
            }
            if (!QrScanToken::claim($token, (int)$locked['ID'])) {
                throw new RuntimeException(t('stickers.qr.err.token_used'));
            }
            $msg = t('stickers.qr.log.weight_open', ['w' => $weight, 'prev' => $last]);
            $pdo->prepare(
                'INSERT INTO base_good_sticker_outs
                 (DT_CREATE, ID_USER, ID_USER_AFTER, ID_GOOD, ID_STOCK, ID_ACT, ID_STICKER,
                  QTYU, SUM, DESCR, WEIGHT_BEFORE, WEIGHT_AFTER, TOKEN)
                 VALUES (?, ?, 0, ?, ?, ?, ?, 0, 0, ?, ?, 0, ?)'
            )->execute([
                time(),
                $userId,
                (int)$locked['ID_GOOD'],
                (int)$locked['ID_STOCK'],
                $actId,
                (int)$locked['ID'],
                self::logTag($userId) . $msg,
                $weight,
                $token,
            ]);
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $open
     */
    private static function commitWeightClose(
        array $row,
        int $userId,
        string $token,
        int $actId,
        int $openOutId,
        array $open,
        float $weight2,
        float $qty,
        float $rate,
        string $strange
    ): void {
        $pdo = Database::pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $st = $pdo->prepare(
                'SELECT ID, PRICE, QTYU_OUT, LOG FROM base_good_sticker_ins WHERE ID = ? FOR UPDATE'
            );
            $st->execute([(int)$row['id']]);
            $locked = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                throw new RuntimeException(t('stickers.qr.err.not_found'));
            }
            if (!QrScanToken::claim($token, (int)$locked['ID'])) {
                throw new RuntimeException(t('stickers.qr.err.token_used'));
            }
            $sum = round(((float)$locked['PRICE'] * $qty) / $rate, 2);
            $msg = t('stickers.qr.log.weight_spent', [
                'act' => $actId,
                'out' => $openOutId,
                'w' => $weight2,
                'qty' => $qty,
            ]) . $strange;
            $pdo->prepare(
                'UPDATE base_good_sticker_outs SET QTYU = ?, SUM = ?, DESCR = ?, WEIGHT_AFTER = ?,
                 ID_USER_AFTER = ?, TOKEN = ? WHERE ID = ?'
            )->execute([
                $qty,
                $sum,
                (string)$open['descr'] . '<BR>' . self::logTag($userId) . $msg,
                $weight2,
                $userId,
                $token,
                $openOutId,
            ]);
            $log = self::appendLog((string)$locked['LOG'], $userId, $msg);
            $pdo->prepare(
                'UPDATE base_good_sticker_ins SET QTYU_OUT = ?, LOG = ?, TOKEN = ? WHERE ID = ?'
            )->execute([(float)$locked['QTYU_OUT'] + $qty, $log, $token, (int)$locked['ID']]);
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::recalcActSum($actId);
    }

    /**
     * Atomic amount writeoff: lock sticker row, re-check token, insert out + update qty.
     *
     * @param array<string,mixed> $row
     */
    private static function commitAmountWriteoff(
        array $row,
        int $userId,
        string $token,
        int $actId,
        float $amount,
        float $rate
    ): void {
        $pdo = Database::pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $st = $pdo->prepare(
                'SELECT ID, ID_GOOD, ID_STOCK, PRICE, QTYU_IN, QTYU_OUT, LOG
                 FROM base_good_sticker_ins WHERE ID = ? FOR UPDATE'
            );
            $st->execute([(int)$row['id']]);
            $locked = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                throw new RuntimeException(t('stickers.qr.err.not_found'));
            }
            if (!QrScanToken::claim($token, (int)$locked['ID'])) {
                throw new RuntimeException(t('stickers.qr.err.token_used'));
            }
            $qtyIn = (float)$locked['QTYU_IN'];
            $qtyOut = (float)$locked['QTYU_OUT'];
            $left = $qtyIn - $qtyOut;
            if ($amount > $left + 0.0001) {
                throw new RuntimeException(t('stickers.writeoff.err.empty'));
            }
            $sum = round(((float)$locked['PRICE'] * $amount) / $rate, 2);
            $msg = t('stickers.qr.log.spent', ['act' => $actId, 'qty' => $amount]);
            $pdo->prepare(
                'INSERT INTO base_good_sticker_outs
                 (DT_CREATE, ID_USER, ID_USER_AFTER, ID_GOOD, ID_STOCK, ID_ACT, ID_STICKER,
                  QTYU, SUM, DESCR, WEIGHT_BEFORE, WEIGHT_AFTER, TOKEN)
                 VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
            )->execute([
                time(),
                $userId,
                (int)$locked['ID_GOOD'],
                (int)$locked['ID_STOCK'],
                $actId,
                (int)$locked['ID'],
                $amount,
                $sum,
                $msg,
                $token,
            ]);
            $log = self::appendLog((string)$locked['LOG'], $userId, $msg);
            $pdo->prepare(
                'UPDATE base_good_sticker_ins SET QTYU_OUT = ?, LOG = ?, TOKEN = ? WHERE ID = ?'
            )->execute([$qtyOut + $amount, $log, $token, (int)$locked['ID']]);
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::recalcActSum($actId);
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $req
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function phaseCancel(
        array $out,
        array $row,
        int $userId,
        string $token,
        array $req,
        bool $mutate,
        array $ctx
    ): array {
        $out['phase'] = 'cancel';
        $last = self::lastOut((int)$row['id']);
        if ($last === null) {
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.qr.cancel_none')];
            $out['error'] = t('stickers.qr.cancel_none');
            return $out;
        }
        $today = self::isToday((int)$last['dt_create']);
        if (!$today && !$ctx['can_cancel_any']) {
            $out['messages'][] = ['level' => 'err', 'text' => t('stickers.writeoff.err.cancel_old')];
            $out['error'] = t('stickers.writeoff.err.cancel_old');
            return $out;
        }
        if (empty($req['cancel']) && (string)($req['action'] ?? '') !== 'cancel_confirm') {
            $out['actions'][] = [
                'action' => 'cancel_confirm',
                'label' => t('stickers.qr.action.cancel_confirm'),
                'fields' => ['doCancel' => 1, 'cancel' => 1],
            ];
            $out['messages'][] = ['level' => 'warn', 'text' => t('stickers.qr.cancel_ask', [
                'd' => date('d.m.Y H:i', (int)$last['dt_create']),
            ])];
            return $out;
        }

        $qqr = (float)$ctx['qqr'];
        if ($qqr == 1.0) {
            if (!$mutate) {
                $out['actions'][] = [
                    'action' => 'cancel_confirm',
                    'label' => t('stickers.qr.action.cancel_confirm'),
                    'fields' => ['doCancel' => 1, 'cancel' => 1],
                ];
                return $out;
            }
            $res = StickerWriteoff::cancel($userId, (int)$last['id']);
            // Also stamp token
            if ($res['success']) {
                Database::pdo()->prepare(
                    'UPDATE base_good_sticker_ins SET TOKEN = ? WHERE ID = ?'
                )->execute([$token, (int)$row['id']]);
            }
            $out['done'] = $res['success'];
            $out['ok'] = $res['success'];
            $out['messages'][] = [
                'level' => $res['success'] ? 'ok' : 'err',
                'text' => $res['message'],
            ];
            return $out;
        }

        // Multi-unit: amount + idRash
        $amount = self::floatReq($req, 'amount');
        $idRash = (int)($req['idRash'] ?? $req['id_rash'] ?? 0);
        if ($amount <= 0) {
            $out['forms'][] = ['type' => 'amount', 'name' => 'amount', 'label' => t('stickers.qr.form.amount_return')];
            return $out;
        }
        if ($idRash < 1) {
            $outs = self::outsForCancel((int)$row['id'], $ctx['can_cancel_any']);
            $out['forms'][] = [
                'type' => 'rash',
                'name' => 'idRash',
                'label' => t('stickers.qr.form.rash'),
                'amount' => $amount,
                'items' => $outs,
            ];
            return $out;
        }
        if (!$mutate) {
            $out['actions'][] = [
                'action' => 'cancel_confirm',
                'label' => t('stickers.qr.action.cancel_confirm'),
                'fields' => ['doCancel' => 1, 'cancel' => 1, 'amount' => $amount, 'idRash' => $idRash],
            ];
            return $out;
        }
        return self::cancelPartial($out, $row, $userId, $token, $idRash, $amount, $ctx);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function outsForCancel(int $stickerId, bool $anyDay): array
    {
        $sql = 'SELECT ID AS id, DT_CREATE AS dt_create, QTYU AS qtyu
                FROM base_good_sticker_outs WHERE ID_STICKER = ?';
        $params = [$stickerId];
        if (!$anyDay) {
            $sql .= ' AND DT_CREATE >= ?';
            $params[] = (int)strtotime(date('Y-m-d 00:00:00'));
        }
        $sql .= ' ORDER BY DT_CREATE DESC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $row
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function cancelPartial(
        array $out,
        array $row,
        int $userId,
        string $token,
        int $idRash,
        float $amount,
        array $ctx
    ): array {
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, QTYU AS qtyu, ID_ACT AS id_act, DT_CREATE AS dt_create, DESCR AS descr
             FROM base_good_sticker_outs WHERE ID = ? AND ID_STICKER = ? LIMIT 1'
        );
        $st->execute([$idRash, (int)$row['id']]);
        $rash = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rash) {
            $out['error'] = t('stickers.writeoff.err.out_not_found');
            return $out;
        }
        if (!self::isToday((int)$rash['dt_create']) && !$ctx['can_cancel_any']) {
            $out['error'] = t('stickers.writeoff.err.cancel_old');
            return $out;
        }
        if ((float)$rash['qtyu'] < $amount) {
            $out['error'] = t('stickers.qr.cancel_too_much');
            return $out;
        }
        $pdo = Database::pdo();
        $newStickerOut = (float)$row['qtyu_out'] - $amount;
        $log = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.cancel', [
            'qty' => $amount,
            'out' => $idRash,
        ]));
        $pdo->prepare(
            'UPDATE base_good_sticker_ins SET QTYU_OUT = ?, LOG = ?, TOKEN = ? WHERE ID = ?'
        )->execute([$newStickerOut, $log, $token, (int)$row['id']]);
        if ((float)$rash['qtyu'] > $amount) {
            $pdo->prepare(
                'UPDATE base_good_sticker_outs SET QTYU = ?, TOKEN = ?, DESCR = ? WHERE ID = ?'
            )->execute([
                (float)$rash['qtyu'] - $amount,
                $token,
                (string)$rash['descr'] . '<BR>' . self::logTag($userId)
                    . t('stickers.qr.log.cancel_part', ['qty' => $amount]),
                $idRash,
            ]);
        } else {
            $pdo->prepare('DELETE FROM base_good_sticker_outs WHERE ID = ?')->execute([$idRash]);
            $chk = $pdo->prepare('SELECT 1 FROM base_good_sticker_outs WHERE ID_ACT = ? LIMIT 1');
            $chk->execute([(int)$rash['id_act']]);
            if (!$chk->fetchColumn()) {
                $pdo->prepare('DELETE FROM base_acts2_out WHERE ID = ?')->execute([(int)$rash['id_act']]);
            } else {
                self::recalcActSum((int)$rash['id_act']);
            }
        }
        self::syncStickerOutQty((int)$row['id'], $userId);
        $out['done'] = true;
        $out['ok'] = true;
        $out['messages'][] = ['level' => 'ok', 'text' => t('stickers.writeoff.cancel_ok')];
        return $out;
    }

    private static function syncStickerOutQty(int $stickerId, int $userId): void
    {
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(QTYU),0) FROM base_good_sticker_outs WHERE ID_STICKER = ?'
        );
        $st->execute([$stickerId]);
        $real = round((float)$st->fetchColumn(), 2);
        $row = StickerInsRepository::find($stickerId);
        if ($row === null) {
            return;
        }
        if (abs($real - (float)$row['qtyu_out']) < 0.001) {
            return;
        }
        $log = self::appendLog((string)$row['log'], $userId, t('stickers.qr.log.sync', [
            'real' => $real,
            'was' => $row['qtyu_out'],
        ]));
        Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET QTYU_OUT = ?, LOG = ? WHERE ID = ?'
        )->execute([$real, $log, $stickerId]);
    }

    /** @param array<string,mixed> $req */
    private static function floatReq(array $req, string $key): float
    {
        if (!isset($req[$key]) || $req[$key] === '') {
            return 0.0;
        }
        return (float)str_replace(',', '.', (string)$req[$key]);
    }
}
