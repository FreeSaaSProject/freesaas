<?php
declare(strict_types=1);

/**
 * Oper-parity hall load on calendar: termloads [N] + B2B ГN on hour slots / proc chips.
 */
final class CalendarLoadOverlay
{
    /** Show red Г{n} when overlapping B2B tickets exceed this (oper threshold). */
    public const B2B_THRESHOLD = 5;

    /**
     * @param array{items?:list<array{id?:int,value?:int}>} $termDay
     * @return array<int,int> unix minute => VALUE
     */
    public static function indexTermloads(array $termDay): array
    {
        $map = [];
        foreach ($termDay['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = (int)($item['value'] ?? 0);
            }
        }
        return $map;
    }

    /**
     * Exact minute, then +5 min, then −5 min (oper show_events).
     *
     * @param array<int,int> $byMinute
     */
    public static function lookupNear(array $byMinute, int $ts): ?int
    {
        if ($ts < 1 || $byMinute === []) {
            return null;
        }
        $base = (int)mktime(
            (int)date('H', $ts),
            (int)date('i', $ts),
            0,
            (int)date('n', $ts),
            (int)date('j', $ts),
            (int)date('Y', $ts)
        );
        foreach ([0, 5, -5] as $deltaMin) {
            $key = $base + ($deltaMin * 60);
            if (array_key_exists($key, $byMinute)) {
                return (int)$byMinute[$key];
            }
        }
        return null;
    }

    /**
     * Count B2B tickets that enter, exit, or fully span the hour (oper getTimeLineDay).
     *
     * @param list<array<string,mixed>> $tickets
     */
    public static function b2bCountForHour(array $tickets, int $hourStart, int $now): int
    {
        $hourEnd = $hourStart + 3599; // oper: H:59:59 inclusive
        $count = 0;
        foreach ($tickets as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string)($row['ticket_type'] ?? '');
            if (strpos($type, 'B2B') === false) {
                continue;
            }
            $start = (int)($row['dtstart'] ?? 0);
            $end = (int)($row['dtend'] ?? 0);
            if ($end > $now) {
                $end = $now;
            }
            $entered = ($start >= $hourStart && $start < $hourEnd);
            $exited = ($end > 0) && ($end >= $hourStart && $end < $hourEnd);
            $fullyInside = ($start < $hourStart) && ($end > 0) && ($end > $hourEnd);
            if ($entered || $exited || $fullyInside) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<int,int> $byMinute
     * @param list<array<string,mixed>> $tickets
     * @param list<int> $hours
     * @return array<int,array{html:string,title:string}>
     */
    public static function hourMarks(array $byMinute, array $tickets, array $hours, int $dayStart, int $now): array
    {
        $marks = [];
        // Oper only paints hour marks (incl. B2B) when the day has termload samples.
        if ($byMinute === []) {
            return $marks;
        }
        foreach ($hours as $h) {
            $h = (int)$h;
            if ($h < 0 || $h > 23) {
                continue;
            }
            $hourTs = $dayStart + ($h * 3600);
            $parts = [];
            $titles = [];
            $val = self::lookupNear($byMinute, $hourTs);
            if ($val !== null) {
                $parts[] = '[' . $val . ']';
                $titles[] = t('terminals.calendar.load_title', ['n' => $val]);
            }
            $b2b = self::b2bCountForHour($tickets, $hourTs, $now);
            if ($b2b > self::B2B_THRESHOLD) {
                $parts[] = '<span class="calendar-tl-b2b">Г' . $b2b . '</span>';
                $titles[] = t('terminals.calendar.b2b_title', ['n' => $b2b]);
            }
            if ($parts === []) {
                continue;
            }
            $marks[$h] = [
                'html' => implode('', $parts),
                'title' => implode(' · ', $titles),
            ];
        }
        return $marks;
    }

    /**
     * Paint chill/rest procedure chips (owned by terminals.chill_product_id + notice chill#).
     * Calendar stays product-agnostic; identification never hardcodes ID 16.
     *
     * @param list<array<string,mixed>> $chips
     * @return list<array<string,mixed>>
     */
    public static function paintChillChips(array $chips): array
    {
        $chillGood = class_exists(TerminalsConfig::class, false)
            ? TerminalsConfig::chillProductId()
            : 0;
        if ($chillGood < 1 && $chips === []) {
            return $chips;
        }
        $painted = false;
        foreach ($chips as &$chip) {
            if (!is_array($chip) || (string)($chip['kind'] ?? '') !== 'proc') {
                continue;
            }
            $idGood = (int)($chip['id_good'] ?? 0);
            $notice = (string)($chip['notice'] ?? '');
            $isChill = ($chillGood > 0 && $idGood === $chillGood)
                || strncmp($notice, 'chill#', 6) === 0;
            if (!$isChill) {
                continue;
            }
            $painted = true;
            // Distinct from staff-colored service chips (slate hatch, dark text).
            $chip['bg'] = 'repeating-linear-gradient(135deg,'
                . 'rgba(100,116,139,0.72) 0 5px,'
                . 'rgba(203,213,225,0.85) 5px 10px)';
            $chip['fg'] = '#0f172a';
            $extra = trim((string)($chip['class'] ?? ''));
            if (!preg_match('/(?:^|\s)is-chill(?:\s|$)/', $extra)) {
                $chip['class'] = trim($extra . ' is-chill');
            }
            $info = (string)($chip['info'] ?? '');
            if (strpos($info, 'calendar-chill-mark') === false) {
                $chip['info'] = '<div class="muted calendar-chill-mark">'
                    . e(t('terminals.calendar.chill_chip'))
                    . '</div>' . $info;
            }
        }
        unset($chip);
        if ($painted && class_exists(Assets::class, false)) {
            Assets::need('terminals');
        }
        return $chips;
    }

    /**
     * Prefix procedure chips with [N] from termloads at DTSTART ±5 min.
     *
     * @param list<array<string,mixed>> $chips
     * @param array<int,int> $byMinute
     * @return list<array<string,mixed>>
     */
    public static function decorateProcChips(array $chips, array $byMinute): array
    {
        if ($byMinute === []) {
            return $chips;
        }
        foreach ($chips as &$chip) {
            if (!is_array($chip) || (string)($chip['kind'] ?? '') !== 'proc') {
                continue;
            }
            $ts = (int)($chip['dtstart'] ?? 0);
            if ($ts < 1) {
                continue;
            }
            $val = self::lookupNear($byMinute, $ts);
            if ($val === null) {
                continue;
            }
            $prefix = '[' . $val . ']';
            $label = (string)($chip['label'] ?? '');
            if (strpos($label, $prefix) !== 0) {
                $chip['label'] = $prefix . ' ' . ltrim($label);
            }
            $title = (string)($chip['title'] ?? $label);
            if (strpos($title, $prefix) !== 0) {
                $chip['title'] = $prefix . ' ' . ltrim($title);
            }
            $info = (string)($chip['info'] ?? '');
            $chip['info'] = '<div class="muted">' . e($prefix) . ' '
                . e(t('terminals.calendar.load_title', ['n' => $val]))
                . '</div>' . $info;
        }
        unset($chip);
        return $chips;
    }
}
