<?php
declare(strict_types=1);

/** EventBus bi.datasets — calendar events. */
final class CalendarBiContrib
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onDatasets($payload)
    {
        if (!is_array($payload) || !class_exists('BiDatasetCatalog', false)) {
            return is_array($payload) ? $payload : ['datasets' => []];
        }
        $list = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $list[] = BiDatasetCatalog::define('events', 'Calendar events', 'base_events', 'calendar', [
            'period_field' => 'DTSTART',
            'period_kind' => 'inclusive_dt',
            'filters' => [],
            'fields' => [
                ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                ['id' => 'dtstart', 'col' => 'DTSTART', 'type' => 'datetime'],
                ['id' => 'dtend', 'col' => 'DTEND', 'type' => 'datetime'],
            ],
            'joins' => [],
        ]);
        $payload['datasets'] = $list;
        return $payload;
    }
}
