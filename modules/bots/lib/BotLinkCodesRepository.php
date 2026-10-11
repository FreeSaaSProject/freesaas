<?php
declare(strict_types=1);

/**
 * One-time messenger link codes (not phone proof).
 * Alphabet 32 × length 10 ≈ 50 bits; chat + per-code fail limits.
 */
final class BotLinkCodesRepository
{
    public const TTL_SECONDS = 600;
    public const CODE_LEN = 10;
    /** Crockford-like 32 chars (no 0/O/1/I). */
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    public const CODE_FAIL_MAX = 3;
    public const CHAT_FAIL_MAX = 5;
    public const CHAT_LOCK_SECONDS = 900;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_bot_link_codes');
    }

    public static function throttleTableReady(): bool
    {
        return Database::tableExists('base_bot_link_throttle');
    }

    public static function normalize(string $raw): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $raw) ?? '');

        return $s;
    }

    public static function looksLikeCode(string $raw): bool
    {
        $n = self::normalize($raw);

        return strlen($n) === self::CODE_LEN
            && (bool)preg_match('/^[2-9A-HJ-NP-Z]{' . self::CODE_LEN . '}$/', $n);
    }

    public static function generateCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * @return array{code:string,expires_at:int,bot_source:int,id_entity:int,ttl_seconds:int}
     */
    public static function create(int $botSource, int $entityId, int $ttlSeconds = self::TTL_SECONDS): array
    {
        if (!self::tableReady() || $entityId < 1) {
            throw new RuntimeException('base_bot_link_codes missing');
        }
        $ttlSeconds = max(60, min(86400, $ttlSeconds));
        $code = self::generateCode();
        $hash = hash('sha256', $code);
        $now = time();
        $expires = $now + $ttlSeconds;
        Database::pdo()->prepare(
            'UPDATE base_bot_link_codes SET used_at = ? WHERE bot_source = ? AND id_entity = ? AND used_at IS NULL'
        )->execute([$now, $botSource, $entityId]);

        $cols = 'bot_source, id_entity, code_hash, expires_at, used_at, created_at';
        $vals = '?, ?, ?, ?, NULL, ?';
        $params = [$botSource, $entityId, $hash, $expires, $now];
        if (Database::columnExists('base_bot_link_codes', 'fail_count')) {
            $cols .= ', fail_count';
            $vals .= ', 0';
        }
        Database::pdo()->prepare(
            "INSERT INTO base_bot_link_codes ($cols) VALUES ($vals)"
        )->execute($params);

        return [
            'code' => $code,
            'expires_at' => $expires,
            'bot_source' => $botSource,
            'id_entity' => $entityId,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    public static function isChatLocked(string $platform, string $channel, string $chatId): bool
    {
        if (!self::throttleTableReady()) {
            return false;
        }
        $chatId = BotIdentity::chatIdValue($chatId);
        if ($chatId === '') {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT locked_until FROM base_bot_link_throttle
             WHERE platform = ? AND channel = ? AND chat_id = ? LIMIT 1'
        );
        $st->execute([strtolower($platform), strtolower($channel), $chatId]);
        $until = $st->fetchColumn();
        if ($until === false || $until === null) {
            return false;
        }

        return (int)$until > time();
    }

    /** @return bool true if chat is now locked */
    public static function recordChatFail(string $platform, string $channel, string $chatId): bool
    {
        if (!self::throttleTableReady()) {
            return false;
        }
        $chatId = BotIdentity::chatIdValue($chatId);
        if ($chatId === '') {
            return false;
        }
        $platform = strtolower($platform);
        $channel = strtolower($channel);
        $now = time();
        $pdo = Database::pdo();
        $lockUntil = $now + self::CHAT_LOCK_SECONDS;
        $pdo->prepare(
            'INSERT INTO base_bot_link_throttle (platform, channel, chat_id, fail_count, locked_until, last_fail_at)
             VALUES (?, ?, ?, 1, NULL, ?)
             ON DUPLICATE KEY UPDATE
               fail_count = LEAST(255, fail_count + 1),
               last_fail_at = VALUES(last_fail_at),
               locked_until = IF(fail_count + 1 >= ?, ?, locked_until)'
        )->execute([
            $platform,
            $channel,
            $chatId,
            $now,
            self::CHAT_FAIL_MAX,
            $lockUntil,
        ]);

        return self::isChatLocked($platform, $channel, $chatId);
    }

    public static function clearChatFails(string $platform, string $channel, string $chatId): void
    {
        if (!self::throttleTableReady()) {
            return;
        }
        $chatId = BotIdentity::chatIdValue($chatId);
        if ($chatId === '') {
            return;
        }
        Database::pdo()->prepare(
            'DELETE FROM base_bot_link_throttle WHERE platform = ? AND channel = ? AND chat_id = ?'
        )->execute([strtolower($platform), strtolower($channel), $chatId]);
    }

    /**
     * Atomically consume a valid unused code for the expected audience.
     *
     * @return array{ok:true,bot_source:int,id_entity:int}|array{ok:false,reason:string}
     */
    public static function consume(string $code, int $expectedSource): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }
        $norm = self::normalize($code);
        if (!self::looksLikeCode($norm)) {
            return ['ok' => false, 'reason' => 'shape'];
        }
        $hash = hash('sha256', $norm);
        $pdo = Database::pdo();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $st = $pdo->prepare(
                'SELECT id, bot_source, id_entity, expires_at, used_at, fail_count
                 FROM base_bot_link_codes WHERE code_hash = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$hash]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                if ($started) {
                    $pdo->commit();
                }

                return ['ok' => false, 'reason' => 'not_found'];
            }
            if ($row['used_at'] !== null) {
                if ($started) {
                    $pdo->commit();
                }

                return ['ok' => false, 'reason' => 'used'];
            }
            if ((int)$row['expires_at'] < time()) {
                $upd = $pdo->prepare(
                    'UPDATE base_bot_link_codes SET used_at = ? WHERE id = ? AND used_at IS NULL'
                );
                $upd->execute([time(), (int)$row['id']]);
                if ($started) {
                    $pdo->commit();
                }

                return ['ok' => false, 'reason' => 'expired'];
            }
            if ((int)$row['bot_source'] !== $expectedSource) {
                // Do not burn — wrong bot type; count as chat fail only.
                if ($started) {
                    $pdo->commit();
                }

                return ['ok' => false, 'reason' => 'wrong_audience'];
            }

            $upd = $pdo->prepare(
                'UPDATE base_bot_link_codes SET used_at = ? WHERE id = ? AND used_at IS NULL'
            );
            $upd->execute([time(), (int)$row['id']]);
            if ($upd->rowCount() < 1) {
                if ($started) {
                    $pdo->commit();
                }

                return ['ok' => false, 'reason' => 'used'];
            }
            if ($started) {
                $pdo->commit();
            }

            return [
                'ok' => true,
                'bot_source' => (int)$row['bot_source'],
                'id_entity' => (int)$row['id_entity'],
            ];
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // MyISAM / no FOR UPDATE: fall back without lock (still check rowCount).
            if (stripos($e->getMessage(), 'FOR UPDATE') !== false
                || stripos($e->getMessage(), 'does not support') !== false
            ) {
                return self::consumeUnlocked($hash, $expectedSource);
            }
            throw $e;
        }
    }

    /**
     * Wrong-code path: increment fail_count; burn at CODE_FAIL_MAX.
     *
     * @return 'burned'|'hit'|'miss'
     */
    public static function registerCodeMiss(string $code): string
    {
        if (!self::tableReady() || !self::looksLikeCode($code)) {
            return 'miss';
        }
        $hash = hash('sha256', self::normalize($code));
        $pdo = Database::pdo();
        if (!Database::columnExists('base_bot_link_codes', 'fail_count')) {
            return 'miss';
        }
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $st = $pdo->prepare(
                'SELECT id, used_at, fail_count FROM base_bot_link_codes WHERE code_hash = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$hash]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['used_at'] !== null) {
                if ($started) {
                    $pdo->commit();
                }

                return 'miss';
            }
            $fails = (int)$row['fail_count'] + 1;
            if ($fails >= self::CODE_FAIL_MAX) {
                $pdo->prepare(
                    'UPDATE base_bot_link_codes SET fail_count = ?, used_at = ? WHERE id = ? AND used_at IS NULL'
                )->execute([$fails, time(), (int)$row['id']]);
                if ($started) {
                    $pdo->commit();
                }

                return 'burned';
            }
            $pdo->prepare(
                'UPDATE base_bot_link_codes SET fail_count = ? WHERE id = ? AND used_at IS NULL'
            )->execute([$fails, (int)$row['id']]);
            if ($started) {
                $pdo->commit();
            }

            return 'hit';
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Best-effort without lock.
            $st = $pdo->prepare(
                'UPDATE base_bot_link_codes SET fail_count = LEAST(255, fail_count + 1),
                 used_at = IF(fail_count + 1 >= ?, ?, used_at)
                 WHERE code_hash = ? AND used_at IS NULL'
            );
            $st->execute([self::CODE_FAIL_MAX, time(), $hash]);

            return $st->rowCount() > 0 ? 'hit' : 'miss';
        }
    }

    /**
     * @return array{ok:true,bot_source:int,id_entity:int}|array{ok:false,reason:string}
     */
    private static function consumeUnlocked(string $hash, int $expectedSource): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT id, bot_source, id_entity, expires_at, used_at
             FROM base_bot_link_codes WHERE code_hash = ? LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['used_at'] !== null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ((int)$row['expires_at'] < time()) {
            $pdo->prepare('UPDATE base_bot_link_codes SET used_at = ? WHERE id = ? AND used_at IS NULL')
                ->execute([time(), (int)$row['id']]);

            return ['ok' => false, 'reason' => 'expired'];
        }
        if ((int)$row['bot_source'] !== $expectedSource) {
            return ['ok' => false, 'reason' => 'wrong_audience'];
        }
        $upd = $pdo->prepare(
            'UPDATE base_bot_link_codes SET used_at = ? WHERE id = ? AND used_at IS NULL'
        );
        $upd->execute([time(), (int)$row['id']]);
        if ($upd->rowCount() < 1) {
            return ['ok' => false, 'reason' => 'used'];
        }

        return [
            'ok' => true,
            'bot_source' => (int)$row['bot_source'],
            'id_entity' => (int)$row['id_entity'],
        ];
    }
}
