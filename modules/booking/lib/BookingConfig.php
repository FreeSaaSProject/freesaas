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
        ];
    }

    /** @return array<string, string> */
    public static function formValues(): array
    {
        return [
            'lead_sec' => (string)self::leadSec(),
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $lead = max(0, min(7200, (int)($post['lead_sec'] ?? 180)));
        Config::set('booking', 'lead_sec', (string)$lead);
    }

    public static function leadSec(): int
    {
        return BookingSlots::defaultLeadSec();
    }
}
