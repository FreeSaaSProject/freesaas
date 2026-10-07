<?php
declare(strict_types=1);

final class TermloadsRepository
{
    /**
     * @return array{day:string,from:int,to:int,items:list<array{id:int,value:int,label:string}>,max:int,total_samples:int}
     */
    public static function forDay(string $day): array
    {
        $day = self::normalizeDay($day);
        $from = (int)strtotime($day . ' 00:00:00');
        $to = $from + 86400 - 1;
        $items = [];
        $max = 0;
        if (Database::tableExists('base_termloads')) {
            $st = Database::pdo()->prepare(
                'SELECT ID AS id, VALUE AS value FROM base_termloads
                 WHERE ID BETWEEN ? AND ? ORDER BY ID ASC'
            );
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)$row['id'];
                $value = (int)$row['value'];
                if ($value > $max) {
                    $max = $value;
                }
                $items[] = [
                    'id' => $id,
                    'value' => $value,
                    'label' => date('H:i', $id),
                ];
            }
        }
        return [
            'day' => $day,
            'from' => $from,
            'to' => $to,
            'items' => $items,
            'max' => $max,
            'total_samples' => count($items),
        ];
    }

    public static function upsertMinute(int $minuteTs, int $value): void
    {
        if ($minuteTs < 1 || $value < 1 || !Database::tableExists('base_termloads')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_termloads (ID, VALUE) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE VALUE = VALUES(VALUE)'
        );
        $st->execute([$minuteTs, $value]);
    }

    private static function normalizeDay(string $day): string
    {
        $day = trim($day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return date('Y-m-d');
        }
        return $day;
    }
}
