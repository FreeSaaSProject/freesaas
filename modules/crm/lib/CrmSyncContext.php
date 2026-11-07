<?php
declare(strict_types=1);

/** Suppress outbox echo while applying inbound bridge writes. */
final class CrmSyncContext
{
    private static string $origin = '';

    public static function begin(string $origin): void
    {
        self::$origin = trim($origin);
    }

    public static function end(): void
    {
        self::$origin = '';
    }

    public static function origin(): string
    {
        return self::$origin;
    }

    public static function isExternal(): bool
    {
        return self::$origin !== '';
    }

    /** @param array<string,mixed> $payload */
    public static function withOrigin(array $payload): array
    {
        if (self::$origin !== '') {
            $payload['sync_origin'] = self::$origin;
        }
        return $payload;
    }
}
