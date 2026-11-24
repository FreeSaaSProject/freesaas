<?php
declare(strict_types=1);

/**
 * Port of oper sticker_writeoff_engine — amount-based UI writeoff + cancel,
 * plus bot activation (DT_PRINT). Weight-mode writeoff stays on the QR scanner.
 */
final class StickerWriteoff
{
    public static function canUse(): bool
    {
        return Auth::capability('GOOD_USE') || Auth::capability('SUPERADMIN_ACCESS');
    }

    public static function canCancelAnyDay(): bool
    {
        return Auth::capability('SUPERADMIN_ACCESS');
    }

    /**
     * Activate a sticker from the staff bot (oper sticker_writeoff_activate).
     * Sets DT_PRINT. Weighable goods need WEIGHT_START (passed or already stored).
     *
     * @param array{weight?:float|string} $options
     * @return array{success:bool,message:string,details:array<string,mixed>}
     */
    public static function activate(int $userId, int $stickerId, array $options = []): array
    {
        $out = ['success' => false, 'message' => '', 'details' => []];
        if ($userId < 1 || $stickerId < 1) {
            $out['message'] = t('stickers.writeoff.err.params');
            return $out;
        }
        if (!QrScanGate::canRevise()) {
            $out['message'] = t('stickers.writeoff.err.activate_perm');
            return $out;
        }
        $row = StickerInsRepository::find($stickerId);
        if (!$row) {
            $out['message'] = t('stickers.err.sticker_not_found', ['id' => $stickerId]);
            return $out;
        }
        if ((int)$row['dt_print'] > 0) {
            $out['success'] = true;
            $out['message'] = t('stickers.writeoff.activate_already');
            $out['details'] = ['already' => true];
            return $out;
        }

        $weightReq = self::weightRequired($row);
        $weight = isset($options['weight'])
            ? (float)str_replace(',', '.', (string)$options['weight'])
            : 0.0;
        $hasStart = (float)$row['weight_start'] > 0;
        if ($weightReq && !$hasStart && $weight <= 0) {
            $out['message'] = t('stickers.writeoff.err.activate_weight');
            $out['details'] = ['need_weight' => true];
            return $out;
        }

        $wStart = ($weightReq && $weight > 0) ? $weight : (float)$row['weight_start'];
        $log = StickerInsRepository::appendLog(
            (string)$row['log'],
            $userId,
            t('stickers.writeoff.log.activated_bot')
        );
        if ($weightReq && $weight > 0) {
            $log .= ' ' . t('stickers.qr.log.weight', ['w' => $wStart]);
        }
        try {
            Database::pdo()->prepare(
                'UPDATE base_good_sticker_ins
                 SET LOG = ?, WEIGHT_START = ?, DT_PRINT = ?, TOKEN = ?
                 WHERE ID = ? AND DT_PRINT = 0'
            )->execute([$log, $wStart, time(), QrScanToken::mint(), $stickerId]);
        } catch (Throwable $e) {
            $out['message'] = t('stickers.writeoff.err.save');
            return $out;
        }

        $out['success'] = true;
        $out['message'] = t('stickers.writeoff.activate_ok');
        if ($weightReq && $wStart > 0) {
            $out['message'] .= ' ' . t('stickers.qr.log.weight', ['w' => $wStart]);
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function weightRequired(array $row): bool
    {
        if (!class_exists('ProductsRepository', false) || !class_exists('UnitsRepository', false)) {
            return false;
        }
        $product = ProductsRepository::find((int)$row['id_good']);
        $unitUseId = $product ? (int)($product['unit_use_id'] ?? 0) : 0;
        if ($unitUseId < 1) {
            return false;
        }
        $unit = UnitsRepository::find($unitUseId);
        return $unit !== null && !empty($unit['weight_req']);
    }

    /**
     * @param array{amount?:float,descr?:string} $options
     * @return array{success:bool,message:string,details:array<string,mixed>}
     */
    public static function execute(int $userId, int $stickerId, array $options = []): array
    {
        $out = ['success' => false, 'message' => '', 'details' => []];
        if ($userId < 1 || $stickerId < 1) {
            $out['message'] = t('stickers.writeoff.err.params');
            return $out;
        }
        if (!self::canUse()) {
            $out['message'] = t('stickers.writeoff.err.no_perm');
            return $out;
        }
        $row = StickerInsRepository::find($stickerId);
        if (!$row) {
            $out['message'] = t('stickers.err.sticker_not_found', ['id' => $stickerId]);
            return $out;
        }
        if ((int)$row['dt_print'] < 1) {
            if (!StickerActivation::manualAllowed()) {
                $out['message'] = t('stickers.writeoff.err.not_activated');
                return $out;
            }
            // Manual mode: soft activate for CRM UI if never printed (oper requires DT_PRINT)
            Database::pdo()->prepare(
                'UPDATE base_good_sticker_ins SET DT_PRINT = ? WHERE ID = ? AND DT_PRINT = 0'
            )->execute([time(), $stickerId]);
            $row['dt_print'] = time();
        }
        if ((int)$row['dt_revision'] > 0 || (int)$row['dt_movement'] > 0) {
            $out['message'] = t('stickers.writeoff.err.unavailable');
            return $out;
        }

        $rate = (float)$row['qty_qtyu_rate'];
        if ($rate <= 0) {
            $rate = 1.0;
        }
        $remaining = StickerInsRepository::remaining($row);
        if ($remaining <= 0) {
            $out['message'] = t('stickers.writeoff.err.empty');
            return $out;
        }

        $amount = isset($options['amount'])
            ? (float)$options['amount']
            : ($rate == 1.0 ? 1.0 : 0.0);
        if ($amount <= 0) {
            $out['message'] = t('stickers.writeoff.err.amount', ['left' => $remaining]);
            return $out;
        }
        if ($amount > $remaining) {
            $out['message'] = t('stickers.writeoff.err.too_much', ['left' => $remaining]);
            return $out;
        }

        $actId = Acts2OutRepository::getOrCreateFreeAct($userId, (int)$row['id_stock']);
        $sum = round(((float)$row['price'] * $amount) / $rate, 2);
        $descr = trim((string)($options['descr'] ?? ''));
        if ($descr === '') {
            $descr = t('stickers.writeoff.line_descr', [
                'user' => StickerInsRepository::userAcronym($userId),
                'qty' => $amount,
            ]);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $newOut = (float)$row['qtyu_out'] + $amount;
            $log = StickerInsRepository::appendLog(
                (string)$row['log'],
                $userId,
                t('stickers.writeoff.log_spent', ['qty' => $amount])
            );
            $pdo->prepare(
                'UPDATE base_good_sticker_ins SET QTYU_OUT = ?, LOG = ? WHERE ID = ?'
            )->execute([$newOut, $log, $stickerId]);

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
                $actId,
                $stickerId,
                $amount,
                $sum,
                mb_substr($descr, 0, 255),
                bin2hex(random_bytes(8)),
            ]);
            $outId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $out['message'] = t('stickers.writeoff.err.save');
            return $out;
        }

        $out['success'] = true;
        $out['message'] = t('stickers.writeoff.ok', ['qty' => $amount]);
        $out['details'] = ['qtyu' => $amount, 'act_id' => $actId, 'out_id' => $outId];
        return $out;
    }

    /**
     * @return array{success:bool,message:string}
     */
    public static function cancel(int $userId, int $outId): array
    {
        $out = ['success' => false, 'message' => ''];
        $rowOut = StickerOutsRepository::find($outId);
        if (!$rowOut) {
            $out['message'] = t('stickers.writeoff.err.out_not_found');
            return $out;
        }
        if ((int)$rowOut['id_user'] !== $userId && !self::canCancelAnyDay()) {
            $out['message'] = t('stickers.writeoff.err.cancel_own');
            return $out;
        }
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00'));
        $dayEnd = (int)strtotime(date('Y-m-d 23:59:59'));
        $dt = (int)$rowOut['dt_create'];
        $today = $dt >= $dayStart && $dt <= $dayEnd;
        if (!$today && !self::canCancelAnyDay()) {
            $out['message'] = t('stickers.writeoff.err.cancel_old');
            return $out;
        }

        $stickerId = (int)$rowOut['id_sticker'];
        $sticker = StickerInsRepository::find($stickerId);
        if (!$sticker) {
            $out['message'] = t('stickers.err.sticker_not_found', ['id' => $stickerId]);
            return $out;
        }

        $qty = (float)$rowOut['qtyu'];
        $newOut = max(0.0, (float)$sticker['qtyu_out'] - $qty);
        $log = StickerInsRepository::appendLog(
            (string)$sticker['log'],
            $userId,
            t('stickers.writeoff.log_cancel', ['qty' => $qty, 'out' => $outId])
        );

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE base_good_sticker_ins SET QTYU_OUT = ?, LOG = ? WHERE ID = ?')
                ->execute([$newOut, $log, $stickerId]);
            $pdo->prepare('DELETE FROM base_good_sticker_outs WHERE ID = ?')->execute([$outId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $out['message'] = t('stickers.writeoff.err.save');
            return $out;
        }

        $out['success'] = true;
        $out['message'] = t('stickers.writeoff.cancel_ok');
        return $out;
    }
}
