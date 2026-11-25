<?php
declare(strict_types=1);

/**
 * Internal fund-to-fund transfer (cover an overdrawn fund, move reserve): paired TRANSFER_OUT / TRANSFER_IN.
 * No DPS involved: total fund balance is unchanged.
 */
final class FundTransferService
{
    /**
     * @return array{out_id:int,in_id:int,summ:float}
     */
    public static function transfer(int $fromId, int $toId, float $summ, string $note = '', int $dt = 0): array
    {
        $funds = FundsRepository::allRows();
        if (!isset($funds[$fromId], $funds[$toId])) {
            throw new InvalidArgumentException(t('funds.moves.err.fund'));
        }
        if ($fromId === $toId) {
            throw new InvalidArgumentException(t('funds.transfer.err.same'));
        }
        if (FundsRepository::isGroup($fromId) || FundsRepository::isGroup($toId)) {
            throw new InvalidArgumentException(t('funds.moves.err.fund_group'));
        }
        if ((int)$funds[$fromId]['type'] !== 2 || (int)$funds[$toId]['type'] !== 2) {
            throw new InvalidArgumentException(t('funds.transfer.err.type'));
        }
        $summ = round($summ, 2);
        if ($summ <= 0) {
            throw new InvalidArgumentException(t('funds.moves.err.sum'));
        }
        $dt = $dt > 0 ? $dt : time();
        $note = trim($note);

        $base = [
            'summ_out_cap' => 0.0,
            'summ_out_fund' => 0.0,
            'summ_out_infl' => 0.0,
            'summ_out_destroy' => 0.0,
            'descr' => $note,
            'dt_amort_start' => $dt,
            'amort_months' => 0,
            'dt_create' => time(),
            'user_create' => Auth::userId(),
            'src' => FundMovementsRepository::SRC_TRANSFER,
        ];
        $pdo = Database::pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $outId = FundMovementsRepository::create($base + [
                'fund_id' => $fromId,
                'type_id' => FundMovementsRepository::TYPE_TRANSFER_OUT,
                'direction' => -1,
                'summ' => $summ,
                'name' => mb_substr(t('funds.transfer.name_out', ['fund' => (string)$funds[$toId]['name']]), 0, 250),
            ]);
            $inId = FundMovementsRepository::create($base + [
                'fund_id' => $toId,
                'type_id' => FundMovementsRepository::TYPE_TRANSFER_IN,
                'direction' => 1,
                'summ' => $summ,
                'name' => mb_substr(t('funds.transfer.name_in', ['fund' => (string)$funds[$fromId]['name']]), 0, 250),
            ]);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['out_id' => $outId, 'in_id' => $inId, 'summ' => $summ];
    }
}
