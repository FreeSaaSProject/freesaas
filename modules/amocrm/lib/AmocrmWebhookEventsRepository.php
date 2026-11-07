<?php
declare(strict_types=1);

final class AmocrmWebhookEventsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_amocrm_webhook_events');
    }

    public static function claim(string $eventKey): bool
    {
        if ($eventKey === '' || !self::tableReady()) {
            return true;
        }
        try {
            Database::pdo()->prepare(
                'INSERT INTO base_amocrm_webhook_events (event_key, seen_at) VALUES (?,?)'
            )->execute([$eventKey, time()]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
