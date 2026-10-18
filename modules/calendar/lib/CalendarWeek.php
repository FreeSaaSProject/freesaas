<?php
declare(strict_types=1);

/**
 * Week helpers for calendar UI (Monday-start weeks).
 */
final class CalendarWeek
{
    /** Midnight of Monday for the week that contains $ts. */
    public static function mondayOf(int $ts): int
    {
        if ($ts <= 0) {
            $ts = time();
        }
        $local = strtotime(date('Y-m-d 00:00:00', $ts));
        if ($local === false) {
            $local = $ts;
        }
        $dow = (int)date('N', $local); // 1=Mon … 7=Sun
        return $local - (($dow - 1) * 86400);
    }

    /**
     * @return list<array{ts:int,date:string,label:string,weekday:string,is_today:bool,is_weekend:bool}>
     */
    public static function days(int $monday): array
    {
        $today = date('Y-m-d');
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $ts = $monday + ($i * 86400);
            $date = date('Y-m-d', $ts);
            $n = (int)date('N', $ts);
            $out[] = [
                'ts' => $ts,
                'date' => $date,
                'label' => date('d.m', $ts),
                'weekday' => self::weekdayShort($n),
                'is_today' => $date === $today,
                'is_weekend' => $n >= 6,
            ];
        }
        return $out;
    }

    public static function weekdayShort(int $n): string
    {
        $keys = [
            1 => 'calendar.week.mon',
            2 => 'calendar.week.tue',
            3 => 'calendar.week.wed',
            4 => 'calendar.week.thu',
            5 => 'calendar.week.fri',
            6 => 'calendar.week.sat',
            7 => 'calendar.week.sun',
        ];
        return t($keys[$n] ?? 'calendar.week.mon');
    }

    /** Three-letter month label (oper moname substr), localized. */
    public static function monthShort(int $month): string
    {
        $keys = [
            1 => 'calendar.month.jan',
            2 => 'calendar.month.feb',
            3 => 'calendar.month.mar',
            4 => 'calendar.month.apr',
            5 => 'calendar.month.may',
            6 => 'calendar.month.jun',
            7 => 'calendar.month.jul',
            8 => 'calendar.month.aug',
            9 => 'calendar.month.sep',
            10 => 'calendar.month.oct',
            11 => 'calendar.month.nov',
            12 => 'calendar.month.dec',
        ];
        return t($keys[$month] ?? 'calendar.month.jan');
    }

    public static function rangeLabel(int $monday): string
    {
        $sunday = $monday + 6 * 86400;
        return date('d.m.Y', $monday) . ' – ' . date('d.m.Y', $sunday);
    }

    /**
     * Oper-style week picker labels: `2026/Окт: 28_29_30__1__2__3__4_ ТЕК`
     *
     * @return list<array{value:string,label:string,current:bool}>
     */
    public static function weekOptions(int $anchorMonday, int $past = 26, int $future = 26): array
    {
        $thisMonday = date('Y-m-d', self::mondayOf(time()));
        $out = [];
        for ($i = -$past; $i <= $future; $i++) {
            $m = $anchorMonday + ($i * 7 * 86400);
            $bits = [];
            for ($d = 0; $d < 7; $d++) {
                $day = (int)date('j', $m + ($d * 86400));
                $bits[] = ($day < 10 ? '_' : '') . $day;
            }
            $value = date('Y-m-d', $m);
            $current = ($value === $thisMonday);
            $label = date('Y', $m)
                . '/' . self::monthShort((int)date('n', $m))
                . ': ' . implode('_', $bits) . '_';
            if ($current) {
                $label .= ' ' . t('calendar.week.current_mark');
            }
            $out[] = [
                'value' => $value,
                'label' => $label,
                'current' => $current,
            ];
        }
        return $out;
    }

    /**
     * Day picker around anchor (±past/future calendar days).
     * Labels: `Пн 17.10` + today mark.
     *
     * @return list<array{value:string,label:string,current:bool}>
     */
    public static function dayOptions(int $anchorTs, int $past = 14, int $future = 14): array
    {
        if ($anchorTs <= 0) {
            $anchorTs = time();
        }
        $anchorMid = strtotime(date('Y-m-d 00:00:00', $anchorTs));
        if ($anchorMid === false) {
            $anchorMid = $anchorTs;
        }
        $today = date('Y-m-d');
        $past = max(0, $past);
        $future = max(0, $future);
        $out = [];
        for ($i = -$past; $i <= $future; $i++) {
            $ts = $anchorMid + ($i * 86400);
            $value = date('Y-m-d', $ts);
            $n = (int)date('N', $ts);
            $label = self::weekdayShort($n) . ' ' . date('d.m', $ts);
            $current = ($value === $today);
            if ($current) {
                $label .= ' ' . t('calendar.day.today_mark');
            }
            $out[] = [
                'value' => $value,
                'label' => $label,
                'current' => $current,
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param string $tsKey
     * @return array<string,list<array<string,mixed>>> date => items
     */
    public static function groupByDate(array $items, string $tsKey = 'dtstart'): array
    {
        $out = [];
        foreach ($items as $item) {
            $ts = (int)($item[$tsKey] ?? 0);
            if ($ts <= 0) {
                continue;
            }
            $date = date('Y-m-d', $ts);
            $out[$date][] = $item;
        }
        return $out;
    }
}
