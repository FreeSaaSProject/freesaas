<?php
declare(strict_types=1);

/**
 * LikeSpa shift windows (PRICE_COL → time lanes).
 * BI derive expand_shift_lanes (EventBus bi.derive_steps) — owned by workweeks.
 * Windows come from WorkweeksRepository when available (same as /workweeks/settings).
 */
final class WorkweeksBiShiftLanes
{
    /**
     * BI engine handler: expandRows(list, cfg).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $cfg
     * @return list<array<string,mixed>>
     */
    public static function expandForBi(array $rows, array $cfg): array
    {
        $dayField = (string)($cfg['day_field'] ?? 'dt');
        $priceField = (string)($cfg['price_field'] ?? 'price_col');
        $mode = (string)($cfg['mode'] ?? 'tickets');
        return self::expandRows($rows, $dayField, $priceField, $mode);
    }

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
     * @return array<int,string>
     */
    public static function smenaMap(): array
    {
        if (class_exists('WorkweeksRepository', false)) {
            $out = [];
            foreach (WorkweeksRepository::shiftWindows() as $n => $w) {
                $out[(int)$n] = (string)$w['label'];
            }
            if ($out !== []) {
                return $out;
            }
        }
        return [
            1 => 'I',
            2 => 'II',
            3 => 'I+II',
            4 => 'IV',
            5 => 'V',
        ];
    }

    /**
     * @return array<int,array{start:int,end:int}>
     */
    public static function windowsForDay(int $dayTs): array
    {
        $dayTs = self::dayStart($dayTs);
        if ($dayTs <= 0) {
            return [];
        }
        if (class_exists('WorkweeksRepository', false)) {
            $out = [];
            foreach (WorkweeksRepository::shiftWindows() as $n => $_w) {
                $range = WorkweeksRepository::shiftRangeForDay($dayTs, (int)$n);
                if ($range === null) {
                    continue;
                }
                $out[(int)$n] = [
                    'start' => $range['start'],
                    'end' => $range['end'],
                ];
            }
            if ($out !== []) {
                return $out;
            }
        }
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
        $map = self::smenaMap();
        $label = $map[$priceCol] ?? (string)$priceCol;
        if ($isHalf) {
            if ($priceCol === 1 || $priceCol === 2) {
                $label = $map[$priceCol] ?? $label;
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

        // Composite full-day lane (classic PRICE_COL=3 / I+II): split into halves at lane 1 end.
        $isComposite = $priceCol === 3 && isset($windows[1], $windows[2], $windows[3]);
        if ($isComposite) {
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
        if ($mode === 'cash' && isset($windows[3])) {
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
