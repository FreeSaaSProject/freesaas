<?php
declare(strict_types=1);

/**
 * EventBus emitters for records calendar / summaries (LikeSpa overlay hooks).
 */
final class CalendarHooks
{
    /**
     * @param array{
     *   date:string,admin_id:int,
     *   aggregates:array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int},
     *   html?:string,sections?:list<array{id?:string,order?:int,html?:string}>
     * } $cell
     * @return array{html:string,sections:list<array{id?:string,order?:int,html?:string}>}
     */
    public static function emitCell(array $cell): array
    {
        $payload = EventBus::emit('audio_reports.calendar.cell', [
            'date' => (string)($cell['date'] ?? ''),
            'admin_id' => (int)($cell['admin_id'] ?? 0),
            'aggregates' => is_array($cell['aggregates'] ?? null) ? $cell['aggregates'] : [],
            'sections' => is_array($cell['sections'] ?? null) ? $cell['sections'] : [],
            'html' => (string)($cell['html'] ?? ''),
        ]);
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        usort($sections, static function (array $a, array $b): int {
            return ((int)($a['order'] ?? 100) <=> (int)($b['order'] ?? 100))
                ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });
        $extra = '';
        foreach ($sections as $sec) {
            $chunk = trim((string)($sec['html'] ?? ''));
            if ($chunk !== '') {
                $extra .= $chunk;
            }
        }
        $html = (string)($payload['html'] ?? '') . $extra;
        return ['html' => $html, 'sections' => $sections];
    }

    /**
     * @param array{date:string,year:int,month:int,day:int,html?:string,sections?:list<array>} $day
     */
    public static function emitDayExtras(array $day): string
    {
        $payload = EventBus::emit('audio_reports.calendar.day_extras', [
            'date' => (string)($day['date'] ?? ''),
            'year' => (int)($day['year'] ?? 0),
            'month' => (int)($day['month'] ?? 0),
            'day' => (int)($day['day'] ?? 0),
            'sections' => is_array($day['sections'] ?? null) ? $day['sections'] : [],
            'html' => (string)($day['html'] ?? ''),
        ]);
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        usort($sections, static function (array $a, array $b): int {
            return ((int)($a['order'] ?? 100) <=> (int)($b['order'] ?? 100))
                ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });
        $html = (string)($payload['html'] ?? '');
        foreach ($sections as $sec) {
            $chunk = trim((string)($sec['html'] ?? ''));
            if ($chunk !== '') {
                $html .= $chunk;
            }
        }
        return $html;
    }

    /**
     * @param list<string> $cards
     * @return list<string>
     */
    public static function emitSummaryCards(array $cards, array $context = []): array
    {
        $payload = EventBus::emit('audio_reports.summary.cards', [
            'cards' => array_values($cards),
            'context' => $context,
        ]);
        $out = is_array($payload['cards'] ?? null) ? $payload['cards'] : $cards;
        $clean = [];
        foreach ($out as $c) {
            $c = trim((string)$c);
            if ($c !== '') {
                $clean[] = $c;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * @return list<array{key:string,title:string,unit?:string,by_employee?:array<int,float|int>,total?:float|int}>
     */
    public static function emitSummaryExternal(array $context): array
    {
        $payload = EventBus::emit('audio_reports.summary.external', [
            'context' => $context,
            'series' => [],
        ]);
        return is_array($payload['series'] ?? null) ? $payload['series'] : [];
    }
}
