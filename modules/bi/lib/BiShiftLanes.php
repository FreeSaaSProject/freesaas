<?php
declare(strict_types=1);

/**
 * LikeSpa shift windows (oper show_kpi10 PRICE_COL → time lanes).
 * Used by derive expand_shift_lanes so recipes can attribute guests/cash by shift.
 */
final class BiShiftLanes
{
    /** @var array<int,string> */
    public const SMENA = [
        1 => 'I',
        2 => 'II',
        3 => 'I+II',
        4 => 'IV',
        5 => 'V',
    ];

    /**
     * Local midnight for a unix timestamp (PHP default TZ).
     */
    public static function dayStart(int $ts): int
    {
        if ($ts <= 0) {
            return 0;
        }
        return (int)mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
    }

    /**
     * Oper day label: "3.10.2026".
     */
    public static function dayLabel(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        return date('j.n.Y', $ts);
    }

    /**
     * @return array<int,array{start:int,end:int}>
     */
    public static function windowsForDay(int $dayTs): array
    {
        $y = (int)date('Y', $dayTs);
        $m = (int)date('n', $dayTs);
        $d = (int)date('j', $dayTs);
        return [
            1 => [
                'start' => (int)mktime(0, 0, 0, $m, $d, $y),
                'end' => (int)mktime(15, 30, 0, $m, $d, $y),
            ],
            2 => [
                'start' => (int)mktime(15, 30, 0, $m, $d, $y),
                'end' => (int)mktime(23, 59, 0, $m, $d, $y),
            ],
            3 => [
                'start' => (int)mktime(0, 0, 0, $m, $d, $y),
                'end' => (int)mktime(23, 59, 0, $m, $d, $y),
            ],
            4 => [
                'start' => (int)mktime(9, 30, 0, $m, $d, $y),
                'end' => (int)mktime(17, 0, 0, $m, $d, $y),
            ],
            5 => [
                'start' => (int)mktime(14, 0, 0, $m, $d, $y),
                'end' => (int)mktime(21, 30, 0, $m, $d, $y),
            ],
        ];
    }

    public static function smenaLabel(int $priceCol, bool $isHalf): string
    {
        $label = self::SMENA[$priceCol] ?? (string)$priceCol;
        if ($isHalf) {
            // Half lanes use price_col 1/2 labels in oper display via halfLane number.
            if ($priceCol === 1 || $priceCol === 2) {
                $label = self::SMENA[$priceCol] ?? $label;
            }
            $label .= ' (½ смены)';
        }
        return $label;
    }

    /**
     * Expand one workweek row into lane rows.
     *
     * mode=tickets: real shift windows (incl. I+II → two halves).
     * mode=cash: non-I+II → full calendar day window (oper attributes all day cash);
     *            I+II → two halves (same as tickets).
     *
     * @param array<string,mixed> $row must include day ts + price_col
     * @return list<array<string,mixed>>
     */
    public static function expandRow(array $row, string $dayField, string $priceField, string $mode = 'tickets'): array
    {
        $dayTs = self::dayStart((int)($row[$dayField] ?? 0));
        $priceCol = (int)($row[$priceField] ?? 0);
        if ($dayTs <= 0 || $priceCol < 1) {
            return [];
        }
        $windows = self::windowsForDay($dayTs);
        $mode = strtolower($mode) === 'cash' ? 'cash' : 'tickets';
        $out = [];

        if ($priceCol === 3) {
            foreach ([1, 2] as $half) {
                if ($half === 1) {
                    $start = $windows[1]['start'];
                    $end = $windows[1]['end'];
                } else {
                    $start = $windows[1]['end'] + 1;
                    $end = $windows[2]['end'];
                }
                $lane = $row;
                $lane['day_start'] = $dayTs;
                $lane['day_label'] = self::dayLabel($dayTs);
                $lane['lane'] = $half;
                $lane['is_half'] = 1;
                $lane['window_start'] = $start;
                $lane['window_end'] = $end;
                $lane['smena_label'] = self::smenaLabel($half, true);
                $lane['stats_key'] = (int)($row['id_operator'] ?? $row['ID_OPERATOR'] ?? 0) . '_h' . $half;
                $out[] = $lane;
            }
            return $out;
        }

        if (!isset($windows[$priceCol])) {
            return [];
        }
        $lane = $row;
        $lane['day_start'] = $dayTs;
        $lane['day_label'] = self::dayLabel($dayTs);
        $lane['lane'] = $priceCol;
        $lane['is_half'] = 0;
        if ($mode === 'cash') {
            // Full day — oper cash for non-I+II is not window-clipped.
            $lane['window_start'] = $windows[3]['start'];
            $lane['window_end'] = $windows[3]['end'];
        } else {
            $lane['window_start'] = $windows[$priceCol]['start'];
            $lane['window_end'] = $windows[$priceCol]['end'];
        }
        $lane['smena_label'] = self::smenaLabel($priceCol, false);
        $op = (int)($row['id_operator'] ?? $row['ID_OPERATOR'] ?? 0);
        $lane['stats_key'] = (string)$op;
        $out[] = $lane;
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function expandRows(array $rows, string $dayField, string $priceField, string $mode = 'tickets'): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (self::expandRow($row, $dayField, $priceField, $mode) as $lane) {
                $out[] = $lane;
            }
        }
        return $out;
    }
}
