<?php
declare(strict_types=1);

final class EventBus
{
    /** @var array<string, list<callable>> */
    private static $listeners = [];

    public static function on(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * @param $payload
     * @return mixed mutated payload
     */
    public static function emit(string $event, $payload = null)
    {
        if (empty(self::$listeners[$event])) {
            return $payload;
        }
        foreach (self::$listeners[$event] as $listener) {
            $payload = $listener($payload);
        }
        return $payload;
    }
}
