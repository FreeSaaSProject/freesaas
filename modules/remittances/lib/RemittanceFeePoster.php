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
        $marker = '[[remittance_fee:batch=' . (int)$batch['id'] . ']]';
        $descr = t('remittances.fee.descr', [
            'move' => (string)$moveId,
            'period' => (string)($batch['period_from'] ?? '') . '…' . (string)($batch['period_to'] ?? ''),
        ]) . ' ' . $marker;

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
        RemittanceBatchesRepository::setFeeMoveId((int)$batch['id'], $feeId);
        return ['ok' => true, 'fee_move_id' => $feeId, 'error' => ''];
    }
}
