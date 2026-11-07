<?php
declare(strict_types=1);

/** Suppress outbox echo while applying inbound Clientics writes. */
final class ClienticsSyncContext
{
    private static string $origin = '';

    public static function begin(string $origin = 'clientics'): void
    {
        self::$origin = trim($origin) !== '' ? trim($origin) : 'clientics';
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
