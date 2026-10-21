<?php
declare(strict_types=1);

/** Calendar module ini helpers (event_mode, hours). */
final class CalendarConfig
{
    public const MODE_BLOCKS = 'blocks';
    public const MODE_VISITS = 'visits';

    /** Default minutes for compact (inverted) short chips; 0 in ini disables. */
    public const DEFAULT_COMPACT_CHIP_MINUTES = 10;

    /** Max allowed threshold (minutes) from settings UI / agent. */
    public const MAX_COMPACT_CHIP_MINUTES = 120;

    /**
     * Short-chip highlight threshold in minutes.
     * Duration ≤ this value gets is-compact (bg/fg swap). 0 = disabled.
     */
    public static function compactChipMinutes(): int
    {
        $raw = Config::get('calendar', 'compact_chip_minutes', self::DEFAULT_COMPACT_CHIP_MINUTES);
        if ($raw === '' || $raw === null) {
            return self::DEFAULT_COMPACT_CHIP_MINUTES;
        }
        $n = (int)$raw;
        if ($n < 0) {
            return 0;
        }
        if ($n > self::MAX_COMPACT_CHIP_MINUTES) {
            return self::MAX_COMPACT_CHIP_MINUTES;
        }

        return $n;
    }

    /** @param int $minutes 0 disables; 1…MAX_COMPACT_CHIP_MINUTES */
    public static function setCompactChipMinutes(int $minutes): void
    {
        if ($minutes < 0 || $minutes > self::MAX_COMPACT_CHIP_MINUTES) {
            throw new InvalidArgumentException(t('calendar.settings.err.compact_chip_minutes'));
        }
        Config::set('calendar', 'compact_chip_minutes', (string)$minutes);
    }

    /** @return self::MODE_BLOCKS|self::MODE_VISITS */
    public static function eventMode(): string
    {
        $raw = strtolower(trim((string)Config::get('calendar', 'event_mode', '')));
        if ($raw === self::MODE_BLOCKS || $raw === self::MODE_VISITS) {
            return $raw;
        }
        // Legacy fallback: only if manual_create is explicitly set in ini.
        $manual = strtolower(trim((string)Config::get('calendar', 'manual_create', '')));
        if ($manual === '') {
            return self::MODE_BLOCKS;
        }
        if (in_array($manual, ['0', 'false', 'off', 'no'], true)) {
            return self::MODE_BLOCKS;
        }
        return self::MODE_VISITS;
    }

    public static function isBlocksMode(): bool
    {
        return self::eventMode() === self::MODE_BLOCKS;
    }

    public static function isVisitsMode(): bool
    {
        return self::eventMode() === self::MODE_VISITS;
    }

    /** @param string $mode blocks|visits */
    public static function setEventMode(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if ($mode !== self::MODE_BLOCKS && $mode !== self::MODE_VISITS) {
            throw new InvalidArgumentException(t('calendar.settings.err.event_mode'));
        }
        Config::set('calendar', 'event_mode', $mode);
        // Keep legacy key in sync for older readers.
        Config::set('calendar', 'manual_create', $mode === self::MODE_VISITS ? '1' : '0');
    }
}
