<?php
declare(strict_types=1);

/**
 * Shared MySQL named lock for occupancy writers (orders + procedures + all channels).
 * Hold only for assertSlot + insert — not for network (1C).
 */
final class BookingLock
{
    /**
     * @template T
     * @param callable():T $fn
     * @return T|null null when lock not acquired
     */
    public static function withOperatorDay(int $operatorId, int $dayTs, int $timeoutSec, callable $fn)
    {
        if ($operatorId < 1) {
            return $fn();
        }
        $ymd = date('Ymd', $dayTs > 0 ? $dayTs : time());
        $name = 'book_op_' . $operatorId . '_' . $ymd;
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
}
