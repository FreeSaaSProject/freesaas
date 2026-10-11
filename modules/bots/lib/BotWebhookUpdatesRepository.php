<?php
declare(strict_types=1);

/** Deduplicate Telegram/Max webhook updates by update_id. */
final class BotWebhookUpdatesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bot_webhook_updates');
    }

    /** @return bool true if this update should be processed (first seen) */
    public static function claim(string $platform, string $channel, int $updateId): bool
    {
        if ($updateId < 1 || !self::tableReady()) {
            // No table yet — process (migrate pending); still better than dropping traffic.
            return true;
        }
        $platform = strtolower(trim($platform));
        $channel = strtolower(trim($channel));
        try {
            Database::pdo()->prepare(
                'INSERT INTO base_bot_webhook_updates (platform, channel, update_id, seen_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([$platform, $channel, $updateId, time()]);

            return true;
        } catch (Throwable $e) {
            // Duplicate primary key → already processed.
            return false;
        }
    }

    /** Drop rows older than $ttlSeconds (best-effort hygiene). */
    public static function prune(int $ttlSeconds = 604800): void
    {
        if (!self::tableReady()) {
            return;
        }
        $cut = time() - max(3600, $ttlSeconds);
        Database::pdo()->prepare('DELETE FROM base_bot_webhook_updates WHERE seen_at < ?')
            ->execute([$cut]);
    }
}
