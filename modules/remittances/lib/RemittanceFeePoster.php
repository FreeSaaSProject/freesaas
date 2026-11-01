<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class RemittanceFeePoster
{
    /**
     * Create expense DPS for withheld fee (idempotent via batch.ID_FEE_MOVE).
     *
     * @return array{ok:bool,fee_move_id:int,error:string}
     */
    public static function postForMove(int $moveId): array
    {
        $fail = static function (string $err): array {
            return ['ok' => false, 'fee_move_id' => 0, 'error' => $err];
        };
        if (!class_exists('LedgerMovesRepository', false)) {
            return $fail('no_ledger');
        }
        $move = LedgerMovesRepository::find($moveId);
        if ($move === null) {
            return $fail('move_not_found');
        }
        $batch = RemittanceReconcile::syncMove($moveId, $move, true);
        if ($batch === null) {
            return $fail('no_batch');
        }
        if ((int)($batch['fee_move_id'] ?? 0) > 0) {
            return ['ok' => true, 'fee_move_id' => (int)$batch['fee_move_id'], 'error' => ''];
        }
        $agreement = RemittanceAgreementsRepository::find((int)$batch['agreement_id']);
        if ($agreement === null || (string)($agreement['fee_posting'] ?? '') !== 'expense_move') {
            return $fail('fee_posting_off');
        }
        $fee = round((float)($batch['fee'] ?? 0), 2);
        if ($fee <= 0) {
            return $fail('no_fee');
        }
        $typeId = (int)($agreement['fee_move_type_id'] ?? 0);
        if ($typeId < 1) {
            return $fail('no_fee_type');
        }
        $accountId = (int)($agreement['fee_account_id'] ?? 0);
        if ($accountId < 1) {
            $accountId = (int)$move['account_id'];
        }
        $batchId = (int)$batch['id'];
        $marker = '[[remittance_fee:batch=' . $batchId . ']]';
        $descr = t('remittances.fee.descr', [
            'move' => (string)$moveId,
            'period' => (string)($batch['period_from'] ?? '') . '…' . (string)($batch['period_to'] ?? ''),
        ]) . ' ' . $marker;

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT ID_FEE_MOVE FROM base_remittance_batches WHERE ID = ? FOR UPDATE'
            );
            $st->execute([$batchId]);
            $lockedFee = (int)$st->fetchColumn();
            if ($lockedFee > 0) {
                $pdo->commit();
                return ['ok' => true, 'fee_move_id' => $lockedFee, 'error' => ''];
            }

            $feeId = LedgerMovesRepository::create([
                'account_id' => $accountId,
                'type_id' => $typeId,
                'direction' => -1,
                'summ' => $fee,
                'descr' => $descr,
                'dt' => (int)$move['dt'],
                'dt_create' => time(),
                'user_create' => Auth::userId(),
                'unconfirmed' => 0,
                'payment_id' => 0,
                'supplier_id' => (int)($agreement['supplier_id'] ?? 0),
                'user_payment' => 0,
                'fund_movement_id' => 0,
                'linked_id' => 0,
                'account_to' => 0,
                'act2_in' => 0,
                'act2_not_req' => 0,
                'dt_promised' => 0,
                'promise_executed' => 0,
            ]);
            if (!RemittanceBatchesRepository::setFeeMoveId($batchId, $feeId)) {
                // Lost race — roll back our move; return the winner's fee id.
                $pdo->rollBack();
                $again = RemittanceBatchesRepository::findByMoveId($moveId);
                $existing = (int)($again['fee_move_id'] ?? 0);
                return $existing > 0
                    ? ['ok' => true, 'fee_move_id' => $existing, 'error' => '']
                    : $fail('fee_failed');
            }
            $pdo->commit();
            return ['ok' => true, 'fee_move_id' => $feeId, 'error' => ''];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $fail('fee_failed');
        }
    }
}
