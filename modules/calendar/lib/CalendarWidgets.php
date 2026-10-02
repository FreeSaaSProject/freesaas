<?php
declare(strict_types=1);

/**
 * Shared helpers for calendar home widgets.
 */
final class CalendarWidgets
{
    /**
     * Staff filter for EVT_MY_ONLY (null = everyone visible).
     */
    public static function staffFilter(): ?int
    {
        if (!empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
            $id = Auth::userId();
            return $id > 0 ? $id : null;
        }
        return null;
    }

    public static function myOnly(): bool
    {
        return self::staffFilter() !== null;
    }

    /**
     * Procedures still active or starting later today.
     *
     * @return list<array<string,mixed>>
     */
    public static function proceduresTodayActive(?int $staffId = null): array
    {
        $dayStart = strtotime('today 00:00:00') ?: time();
        $dayEnd = $dayStart + 86400;
        $now = time();
        $all = ProceduresRepository::listForDayRange($dayStart, $dayEnd, $staffId);
        $out = [];
        foreach ($all as $row) {
            $end = (int)($row['dtend'] ?? 0);
            if ($end > 0 && $end <= $now) {
                continue;
            }
            if ((int)($row['enabled'] ?? 1) === 0) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Events still active or starting later today (non-block by default).
     *
     * @return list<array<string,mixed>>
     */
    public static function eventsTodayActive(?int $staffId = null, bool $includeBlocks = false): array
    {
        $dayStart = strtotime('today 00:00:00') ?: time();
        $dayEnd = $dayStart + 86400;
        $now = time();
        $all = EventsRepository::listForDayRange($dayStart, $dayEnd, $staffId);
        $out = [];
        foreach ($all as $row) {
            if (!$includeBlocks && !empty($row['is_block'])) {
                continue;
            }
            if ((int)($row['enabled'] ?? 1) !== 1) {
                continue;
            }
            $end = (int)($row['dtend'] ?? 0);
            if ($end > 0 && $end <= $now) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Merge procedures + events for list widget, sorted by start.
     *
     * @return list<array<string,mixed>>
     */
    public static function todayFeed(?int $staffId, int $limit): array
    {
        $items = [];
        foreach (self::proceduresTodayActive($staffId) as $pr) {
            $items[] = [
                'kind' => 'proc',
                'id' => (int)($pr['id'] ?? 0),
                'dtstart' => (int)($pr['dtstart'] ?? 0),
                'dtend' => (int)($pr['dtend'] ?? 0),
                'label' => self::procedureLabel($pr),
                'operator_name' => (string)($pr['operator_name'] ?? ''),
                'ongoing' => (int)($pr['dtstart'] ?? 0) <= time() && (int)($pr['dtend'] ?? 0) > time(),
                'href' => '/procedures/' . (int)($pr['id'] ?? 0) . '/edit',
            ];
        }
        foreach (self::eventsTodayActive($staffId, false) as $ev) {
            $id = (int)($ev['id'] ?? 0);
            $name = trim((string)($ev['name'] ?? ''));
            $client = trim((string)($ev['client_name'] ?? ''));
            $label = $name !== '' ? $name : ($client !== '' ? $client : t('calendar.widget.upcoming.unnamed'));
            $items[] = [
                'kind' => 'event',
                'id' => $id,
                'dtstart' => (int)($ev['dtstart'] ?? 0),
                'dtend' => (int)($ev['dtend'] ?? 0),
                'label' => $label,
                'operator_name' => (string)($ev['operator_name'] ?? ''),
                'ongoing' => (int)($ev['dtstart'] ?? 0) <= time() && (int)($ev['dtend'] ?? 0) > time(),
                'href' => Auth::can('calendar.edit') && $id > 0
                    ? '/calendar/events/' . $id . '/edit'
                    : EventsRepository::dayUrl((int)($ev['dtstart'] ?? 0)),
            ];
        }
        usort($items, static function (array $a, array $b): int {
            if (!empty($a['ongoing']) !== !empty($b['ongoing'])) {
                return !empty($a['ongoing']) ? -1 : 1;
            }
            return ((int)$a['dtstart'] <=> (int)$b['dtstart']) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        if ($limit > 0 && count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
        }
        return $items;
    }

    /** @param array<string,mixed> $pr */
    private static function procedureLabel(array $pr): string
    {
        $title = trim((string)($pr['product_name'] ?? $pr['type_name'] ?? ''));
        $client = trim((string)($pr['client_name'] ?? ''));
        if ($title === '') {
            $title = '#' . (int)($pr['id'] ?? 0);
        }
        return $client !== '' ? ($client . ' · ' . $title) : $title;
    }
}
