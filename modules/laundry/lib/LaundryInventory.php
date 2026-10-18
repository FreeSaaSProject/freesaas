<?php
declare(strict_types=1);

final class LaundryInventory
{
    /**
     * Apply absolute counts: facts[accountId][typeId] = qty.
     *
     * @param array<int,array<int,int>> $facts
     * @return int number of adjust moves created
     */
    public static function applyAbsolute(array $facts, int $userId, ?int $dt = null): int
    {
        $dt = $dt && $dt > 0 ? $dt : time();
        $descr = t('laundry.move.inv_descr', ['date' => date('d.m.Y', $dt)]);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($facts as $accountId => $byType) {
                $accountId = (int)$accountId;
                if ($accountId < 1 || !is_array($byType)) {
                    continue;
                }
                foreach ($byType as $typeId => $fact) {
                    $typeId = (int)$typeId;
                    $fact = max(0, (int)$fact);
                    if ($typeId < 1) {
                        continue;
                    }
                    $book = LaundryMovesRepository::balance($accountId, $typeId);
                    $diff = $fact - $book;
                    if ($diff === 0) {
                        continue;
                    }
                    LaundryMovesRepository::insertAdjust($pdo, $accountId, $typeId, $diff, $descr, $dt, $userId);
                    $n++;
                }
            }
            $pdo->commit();
            return $n;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
