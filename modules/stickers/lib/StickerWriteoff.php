<?php
declare(strict_types=1);

/**
 * Port of oper sticker_writeoff_engine — amount-based UI writeoff + cancel.
 * Weight-mode and bot/QR token flow deferred.
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
        // Soft activate for CRM UI if never printed (oper requires DT_PRINT)
        if ((int)$row['dt_print'] < 1) {
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
                'user' => $userId,
                'qty' => $amount,
            ]);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $newOut = (float)$row['qtyu_out'] + $amount;
            $log = (string)$row['log'] . '<BR>[' . $userId . '][' . date('d.m.Y H:i') . '] '
                . t('stickers.writeoff.log_spent', ['qty' => $amount]);
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
        $log = (string)$sticker['log'] . '<BR>[' . $userId . '][' . date('d.m.Y H:i') . '] '
            . t('stickers.writeoff.log_cancel', ['qty' => $qty, 'out' => $outId]);

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
