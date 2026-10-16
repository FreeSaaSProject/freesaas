<?php
declare(strict_types=1);

/**
 * EventBus enrichment for timeline chips and per-day hour marks.
 * Subscribers (e.g. terminals) mutate days[*].chips / days[*].hour_marks.
 */
final class CalendarTimelineEnrich
{
    /**
     * @param list<array<string,mixed>> $dayColumns
     * @param list<int> $hours
     * @return list<array<string,mixed>>
     */
    public static function enrichColumns(array $dayColumns, array $hours, string $view = 'day'): array
    {
        $days = [];
        foreach ($dayColumns as $col) {
            $day = is_array($col['day'] ?? null) ? $col['day'] : [];
            $date = (string)($day['date'] ?? '');
            $ts = (int)($day['ts'] ?? 0);
            if ($date === '' || $ts <= 0) {
                continue;
            }
            $days[$date] = [
                'date' => $date,
                'ts_start' => $ts,
                'ts_end' => $ts + 86400,
                'chips' => is_array($col['chips'] ?? null) ? $col['chips'] : [],
                'hour_marks' => is_array($col['hour_marks'] ?? null) ? $col['hour_marks'] : [],
            ];
        }
        if ($days === []) {
            return $dayColumns;
        }

        $payload = EventBus::emit('calendar.timeline.enrich', [
            'view' => $view,
            'hours' => $hours,
            'days' => $days,
            'context' => [
                'my_only' => !empty(Auth::statusFlags()['EVT_MY_ONLY']),
                'can_money' => Auth::capability('SUPERADMIN_ACCESS')
                    || Auth::capability('EVT_MONEY_DATA'),
                'can_see_others' => Auth::capability('SUPERADMIN_ACCESS')
                    || Auth::capability('CAN_SEE_SUM_OTHERS'),
                'is_super' => Auth::capability('SUPERADMIN_ACCESS'),
                'show_admin_data' => Auth::capability('SHOW_ADMINISTRATOR_DATA')
                    || Auth::capability('SUPERADMIN_ACCESS'),
                'now' => time(),
            ],
        ]);

        $daysOut = is_array($payload['days'] ?? null) ? $payload['days'] : [];
        foreach ($dayColumns as &$col) {
            $date = (string)($col['day']['date'] ?? '');
            if ($date === '' || !isset($daysOut[$date]) || !is_array($daysOut[$date])) {
                continue;
            }
            $enriched = $daysOut[$date];
            if (isset($enriched['chips']) && is_array($enriched['chips'])) {
                $col['chips'] = $enriched['chips'];
            }
            if (isset($enriched['hour_marks']) && is_array($enriched['hour_marks'])) {
                $col['hour_marks'] = $enriched['hour_marks'];
            }
        }
        unset($col);

        return $dayColumns;
    }
}
