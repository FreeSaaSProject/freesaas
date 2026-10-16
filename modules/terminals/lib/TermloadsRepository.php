<?php
declare(strict_types=1);

final class TermloadsRepository
{
    /** @var array<string,array{day:string,from:int,to:int,items:list<array{id:int,value:int,label:string}>,max:int,total_samples:int}> */
    private static $forDayCache = [];

    /**
     * @return array{day:string,from:int,to:int,items:list<array{id:int,value:int,label:string}>,max:int,total_samples:int}
     */
    public static function forDay(string $day): array
    {
        $day = self::normalizeDay($day);
        if (isset(self::$forDayCache[$day])) {
            return self::$forDayCache[$day];
        }
        $from = (int)strtotime($day . ' 00:00:00');
        $to = $from + 86400 - 1;
        $series = self::seriesFromRows($day, $from, $to, self::rowsBetween($from, $to));
        return self::$forDayCache[$day] = $series;
    }

    /**
     * Prefetch termloads for many calendar days in one query (week enrich).
     *
     * @param list<string> $days Y-m-d
     * @return array<string,array{day:string,from:int,to:int,items:list<array{id:int,value:int,label:string}>,max:int,total_samples:int}>
     */
    public static function forDays(array $days): array
    {
        $normalized = [];
        foreach ($days as $d) {
            $d = self::normalizeDay((string)$d);
            $normalized[$d] = $d;
        }
        $normalized = array_values($normalized);
        if ($normalized === []) {
            return [];
        }
        sort($normalized);
        $missing = [];
        foreach ($normalized as $d) {
            if (!isset(self::$forDayCache[$d])) {
                $missing[] = $d;
            }
        }
        if ($missing !== []) {
            $from = (int)strtotime($missing[0] . ' 00:00:00');
            $to = (int)strtotime($missing[count($missing) - 1] . ' 00:00:00') + 86400 - 1;
            $byDay = [];
            foreach (self::rowsBetween($from, $to) as $row) {
                $id = (int)$row['id'];
                $d = date('Y-m-d', $id);
                $byDay[$d][] = $row;
            }
            foreach ($missing as $d) {
                $dayFrom = (int)strtotime($d . ' 00:00:00');
                $dayTo = $dayFrom + 86400 - 1;
                self::$forDayCache[$d] = self::seriesFromRows($d, $dayFrom, $dayTo, $byDay[$d] ?? []);
            }
        }
        $out = [];
        foreach ($normalized as $d) {
            $out[$d] = self::$forDayCache[$d];
        }
        return $out;
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
        $day = date('Y-m-d', $minuteTs);
        unset(self::$forDayCache[$day]);
    }

    /**
     * @return list<array{id:int,value:int}>
     */
    private static function rowsBetween(int $from, int $to): array
    {
        if ($from < 1 || $to < $from || !Database::tableExists('base_termloads')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, VALUE AS value FROM base_termloads
             WHERE ID BETWEEN ? AND ? ORDER BY ID ASC'
        );
        $st->execute([$from, $to]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'value' => (int)$row['value'],
            ];
        }
        return $items;
    }

    /**
     * @param list<array{id:int,value:int}> $rows
     * @return array{day:string,from:int,to:int,items:list<array{id:int,value:int,label:string}>,max:int,total_samples:int}
     */
    private static function seriesFromRows(string $day, int $from, int $to, array $rows): array
    {
        $items = [];
        $max = 0;
        foreach ($rows as $row) {
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
        return [
            'day' => $day,
            'from' => $from,
            'to' => $to,
            'items' => $items,
            'max' => $max,
            'total_samples' => count($items),
        ];
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
