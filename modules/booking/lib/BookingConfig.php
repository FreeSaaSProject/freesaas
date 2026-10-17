<?php
declare(strict_types=1);

/** Ini-backed settings for booking module ([booking] in cfg/modules/booking.ini). */
final class BookingConfig
{
    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'lead_sec' => 180,
            'show_calendar_cta' => 1,
        ];
    }

    /** @return array<string, string> */
    public static function formValues(): array
    {
        return [
            'lead_sec' => (string)self::leadSec(),
            'show_calendar_cta' => self::showCalendarCta() ? '1' : '0',
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $lead = max(0, min(7200, (int)($post['lead_sec'] ?? 180)));
        Config::set('booking', 'lead_sec', (string)$lead);
        if (array_key_exists('show_calendar_cta', $post)) {
            Config::set('booking', 'show_calendar_cta', !empty($post['show_calendar_cta']) ? '1' : '0');
        }
    }

    public static function leadSec(): int
    {
        return BookingSlots::defaultLeadSec();
    }

    /** Show “New booking” on calendar day/week chrome. */
    public static function showCalendarCta(): bool
    {
        $raw = strtolower(trim((string)Config::get('booking', 'show_calendar_cta', '1')));

        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }
}
