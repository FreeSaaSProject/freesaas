<?php
declare(strict_types=1);

/** One-time codes to link a messenger chat without trusting a phone number. */
final class BotLinkCodesRepository
{
    public const TTL_SECONDS = 600;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_bot_link_codes');
    }

    /**
     * @return array{code:string,expires_at:int,bot_source:int,id_entity:int}
     */
    public static function create(int $botSource, int $entityId, int $ttlSeconds = self::TTL_SECONDS): array
    {
        if (!self::tableReady() || $entityId < 1) {
            throw new RuntimeException('base_bot_link_codes missing');
        }
        $ttlSeconds = max(60, min(86400, $ttlSeconds));
        $code = (string)random_int(100000, 999999);
        $hash = hash('sha256', $code);
        $now = time();
        $expires = $now + $ttlSeconds;
        // Invalidate previous unused codes for this entity.
        Database::pdo()->prepare(
            'UPDATE base_bot_link_codes SET used_at = ? WHERE bot_source = ? AND id_entity = ? AND used_at IS NULL'
        )->execute([$now, $botSource, $entityId]);
        Database::pdo()->prepare(
            'INSERT INTO base_bot_link_codes (bot_source, id_entity, code_hash, expires_at, used_at, created_at)
             VALUES (?, ?, ?, ?, NULL, ?)'
        )->execute([$botSource, $entityId, $hash, $expires, $now]);

        return [
            'code' => $code,
            'expires_at' => $expires,
            'bot_source' => $botSource,
            'id_entity' => $entityId,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    /**
     * Consume a code. Returns entity ids or null.
     *
     * @return array{bot_source:int,id_entity:int}|null
     */
    public static function consume(string $code): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $code = trim($code);
        if (!preg_match('/^\d{6,8}$/', $code)) {
            return null;
        }
        $hash = hash('sha256', $code);
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT id, bot_source, id_entity, expires_at, used_at
             FROM base_bot_link_codes WHERE code_hash = ? LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['used_at'] !== null) {
            return null;
        }
        if ((int)$row['expires_at'] < time()) {
            $pdo->prepare('UPDATE base_bot_link_codes SET used_at = ? WHERE id = ?')
                ->execute([time(), (int)$row['id']]);

            return null;
        }
        $pdo->prepare('UPDATE base_bot_link_codes SET used_at = ? WHERE id = ? AND used_at IS NULL')
            ->execute([time(), (int)$row['id']]);

        return [
            'bot_source' => (int)$row['bot_source'],
            'id_entity' => (int)$row['id_entity'],
        ];
    }
}
