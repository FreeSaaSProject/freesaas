<?php
declare(strict_types=1);

/**
 * Warm-standby isolation: cfg [app] standby=passive|active (empty = primary).
 *
 * passive — sync + health probe only; mute product cron / outbound integrations.
 * active  — promote: internal ops (terminals, calendar, …); still mute public push
 *           (bots webhooks are unreachable without public DNS) and most outbound drains
 *           unless explicitly allowlisted.
 */
final class StandbyMode
{
    /** Cron jobs always allowed on standby (any mode). */
    private const ALWAYS_CRON = [
        'backups.standby_health',
    ];

    /** Pull primary packs — passive only (never while promoted/active). */
    private const PASSIVE_CRON = [
        'backups.standby_sync',
    ];

    /** Extra cron allowed only in active (internal contour + local packs for demote). */
    private const ACTIVE_CRON = [
        'terminals.load',
        'terminals.tickets',
        'backups.tick',
        'backups.cdc_refresh',
    ];

    public static function mode(): string
    {
        $m = strtolower(trim((string)Config::get('app', 'standby', '')));
        if ($m === 'passive' || $m === 'active') {
            return $m;
        }
        return '';
    }

    public static function isStandby(): bool
    {
        return self::mode() !== '';
    }

    public static function isPassive(): bool
    {
        return self::mode() === 'passive';
    }

    public static function isActive(): bool
    {
        return self::mode() === 'active';
    }

    /** Whether a registered Cron job may run in the current mode. */
    public static function allowsCron(string $jobId): bool
    {
        $mode = self::mode();
        if ($mode === '') {
            return true;
        }
        if (in_array($jobId, self::ALWAYS_CRON, true)) {
            return true;
        }
        if ($mode === 'passive' && in_array($jobId, self::PASSIVE_CRON, true)) {
            return true;
        }
        if ($mode === 'active' && in_array($jobId, self::ACTIVE_CRON, true)) {
            return true;
        }
        // Passive standby: backups.tick runs only for local incr/full of this instance
        // (configured separately); product integrations stay muted.
        return false;
    }

    /**
     * Soft gate for outbound integrations (bots drain, banks sync, …).
     * Call sites may check before firing.
     */
    public static function allowsOutbound(string $channel = ''): bool
    {
        $mode = self::mode();
        if ($mode === '') {
            return true;
        }
        if ($mode === 'passive') {
            return false;
        }
        // active: allow internal 1C / terminals outbound; block messengers by default
        $channel = strtolower(trim($channel));
        if (in_array($channel, ['bots', 'banks', 'gcal', 'llm', 'update'], true)) {
            return Config::get('app', 'standby_allow_' . $channel, '0') === '1';
        }
        return true;
    }
}
