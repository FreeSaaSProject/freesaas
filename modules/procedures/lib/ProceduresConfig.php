<?php
declare(strict_types=1);

/**
 * Module ini helpers (cfg/modules/procedures.ini → [procedures]).
 */
final class ProceduresConfig
{
    /** @return array{show_calendar_yoy:int} */
    public static function formValues(): array
    {
        return [
            'show_calendar_yoy' => self::showCalendarYoy() ? 1 : 0,
        ];
    }

    public static function showCalendarYoy(): bool
    {
        $raw = strtolower(trim((string)Config::get('procedures', 'show_calendar_yoy', '1')));
        if ($raw === '') {
            return true;
        }

        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        if (!array_key_exists('show_calendar_yoy', $post)
            && !isset($post['procedures_calendar_yoy_save'])
        ) {
            return;
        }
        // Checkbox: absent when unchecked.
        $on = !empty($post['show_calendar_yoy']) ? '1' : '0';
        Config::set('procedures', 'show_calendar_yoy', $on);
    }
}
