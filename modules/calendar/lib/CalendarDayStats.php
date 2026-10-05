<?php
declare(strict_types=1);

/**
 * Collect and render per-day footer stats for calendar (week/day timeline).
 * Modules append sections via EventBus calendar.day.stats.
 */
final class CalendarDayStats
{
    /**
     * @param list<array{ts:int,date:string,is_today?:bool}> $days
     * @param int|null $staffFilter
     * @return array<string,string> date => html
     */
    public static function collect(array $days, ?int $staffFilter, string $view = 'week'): array
    {
        $dayPayload = [];
        $today = date('Y-m-d');
        $now = time();
        foreach ($days as $day) {
            $date = (string)($day['date'] ?? '');
            $ts = (int)($day['ts'] ?? 0);
            if ($date === '' || $ts <= 0) {
                continue;
            }
            $dayPayload[$date] = [
                'date' => $date,
                'ts_start' => $ts,
                'ts_end' => $ts + 86400,
                'is_today' => !empty($day['is_today']) || $date === $today,
                'is_past' => $date < $today,
                'is_future' => $date > $today,
                'staff_filter' => $staffFilter,
                'sections' => [],
            ];
        }
        if ($dayPayload === []) {
            return [];
        }

        $payload = EventBus::emit('calendar.day.stats', [
            'view' => $view,
            'days' => $dayPayload,
            'context' => [
                'my_only' => !empty(Auth::statusFlags()['EVT_MY_ONLY']),
                'can_money' => Auth::capability('SUPERADMIN_ACCESS')
                    || Auth::capability('EVT_MONEY_DATA'),
                'can_see_others' => Auth::capability('SUPERADMIN_ACCESS')
                    || Auth::capability('CAN_SEE_SUM_OTHERS'),
                'is_super' => Auth::capability('SUPERADMIN_ACCESS'),
                'show_admin_data' => Auth::capability('SHOW_ADMINISTRATOR_DATA')
                    || Auth::capability('SUPERADMIN_ACCESS'),
                'now' => $now,
            ],
        ]);

        $out = [];
        $daysOut = is_array($payload['days'] ?? null) ? $payload['days'] : [];
        foreach ($daysOut as $date => $day) {
            if (!is_array($day)) {
                continue;
            }
            $sections = is_array($day['sections'] ?? null) ? $day['sections'] : [];
            usort($sections, static function (array $a, array $b): int {
                return ((int)($a['order'] ?? 100) <=> (int)($b['order'] ?? 100))
                    ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
            });
            $html = '';
            foreach ($sections as $sec) {
                $chunk = trim((string)($sec['html'] ?? ''));
                if ($chunk !== '') {
                    $html .= $chunk;
                }
            }
            $out[(string)$date] = $html;
        }
        return $out;
    }
}
