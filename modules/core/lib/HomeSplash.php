<?php
declare(strict_types=1);

/**
 * One-shot versioned welcome splash on GET /.
 * Bump VERSION to re-show the intro to everyone who already acked an older build.
 */
final class HomeSplash
{
    public const VERSION = 5;

    public const PREF_KEY = 'splash.seen_version';

    public static function currentVersion(): string
    {
        return (string)self::VERSION;
    }

    public static function seenVersion(int $userId): ?string
    {
        if ($userId < 1 || !class_exists('UserPrefsRepository', false)) {
            return null;
        }
        $v = UserPrefsRepository::get($userId, self::PREF_KEY);
        return $v === null || $v === '' ? null : $v;
    }

    public static function shouldShow(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        return self::seenVersion($userId) !== self::currentVersion();
    }

    public static function reset(int $userId): void
    {
        if ($userId < 1 || !class_exists('UserPrefsRepository', false)) {
            return;
        }
        UserPrefsRepository::delete($userId, self::PREF_KEY);
    }

    public static function ack(int $userId, string $version): bool
    {
        if ($userId < 1 || !class_exists('UserPrefsRepository', false)) {
            return false;
        }
        if ($version !== self::currentVersion()) {
            return false;
        }
        UserPrefsRepository::set($userId, self::PREF_KEY, $version);
        return true;
    }

    public static function hasAsk(): bool
    {
        return class_exists('ModuleManager', false) && ModuleManager::isEnabled('assistant');
    }
}
