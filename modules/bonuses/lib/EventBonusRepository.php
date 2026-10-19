<?php
declare(strict_types=1);

/** Per-event bonus intent (base_event_bonus). */
final class EventBonusRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_event_bonus');
    }

    /**
     * @return array{bonus_use:int,bonus_discount:float}
     */
    public static function get(int $eventId): array
    {
        $empty = ['bonus_use' => 0, 'bonus_discount' => 0.0];
        if ($eventId < 1 || !self::tableReady()) {
            return $empty;
        }
        $st = Database::pdo()->prepare(
            'SELECT BONUS_USE AS bonus_use, BONUS_DISCOUNT AS bonus_discount
             FROM base_event_bonus WHERE ID_EVENT = ? LIMIT 1'
        );
        $st->execute([$eventId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }
        return [
            'bonus_use' => (int)$row['bonus_use'] > 0 ? 1 : 0,
            'bonus_discount' => max(0.0, (float)$row['bonus_discount']),
        ];
    }

    public static function upsert(int $eventId, int $bonusUse, float $bonusDiscount): void
    {
        if ($eventId < 1 || !self::tableReady()) {
            return;
        }
        $bonusUse = $bonusUse > 0 ? 1 : 0;
        if ($bonusUse < 1) {
            $bonusDiscount = 0.0;
        }
        $bonusDiscount = max(0.0, $bonusDiscount);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_event_bonus (ID_EVENT, BONUS_USE, BONUS_DISCOUNT, DTUPDATE)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               BONUS_USE = VALUES(BONUS_USE),
               BONUS_DISCOUNT = VALUES(BONUS_DISCOUNT),
               DTUPDATE = VALUES(DTUPDATE)'
        );
        $st->execute([$eventId, $bonusUse, round($bonusDiscount, 2), time()]);
    }

    public static function deleteByEvent(int $eventId): void
    {
        if ($eventId < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_event_bonus WHERE ID_EVENT = ?')->execute([$eventId]);
    }
}
