<?php
declare(strict_types=1);

final class ClienticsWebhookEventsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_clientics_webhook_events');
    }

    /** @return bool true if newly claimed */
    public static function claim(string $eventKey): bool
    {
        if ($eventKey === '') {
            return true;
        }
        if (!self::tableReady()) {
            return true;
        }
        try {
            Database::pdo()->prepare(
                'INSERT INTO base_clientics_webhook_events (event_key, seen_at) VALUES (?,?)'
            )->execute([$eventKey, time()]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
