<?php
declare(strict_types=1);

/** One-shot operation token (legacy qr2 TOKEN on ins/outs + claim table). */
final class QrScanToken
{
    public static function mint(): string
    {
        return md5(bin2hex(random_bytes(16)) . (string)microtime(true));
    }

    public static function isValidFormat(string $token): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32}$/i', $token);
    }

    public static function isUsed(string $token): bool
    {
        if (!self::isValidFormat($token)) {
            return false;
        }
        try {
            if (Database::tableExists('base_qr_token_claims')) {
                $st = Database::pdo()->prepare(
                    'SELECT 1 FROM base_qr_token_claims WHERE TOKEN = ? LIMIT 1'
                );
                $st->execute([$token]);
                if ($st->fetchColumn()) {
                    return true;
                }
            }
            if (!Database::tableExists('base_good_sticker_ins')) {
                return false;
            }
            $pdo = Database::pdo();
            $st = $pdo->prepare('SELECT 1 FROM base_good_sticker_ins WHERE TOKEN = ? LIMIT 1');
            $st->execute([$token]);
            if ($st->fetchColumn()) {
                return true;
            }
            if (!Database::tableExists('base_good_sticker_outs')) {
                return false;
            }
            $st = $pdo->prepare('SELECT 1 FROM base_good_sticker_outs WHERE TOKEN = ? LIMIT 1');
            $st->execute([$token]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Atomically claim a token (INSERT UNIQUE). Call inside the same PDO transaction as the writeoff.
     * Falls back to legacy isUsed() when the claims table is not migrated yet.
     */
    public static function claim(string $token, int $stickerId = 0): bool
    {
        if (!self::isValidFormat($token)) {
            return false;
        }
        try {
            if (!Database::tableExists('base_qr_token_claims')) {
                return !self::isUsed($token);
            }
            Database::pdo()->prepare(
                'INSERT INTO base_qr_token_claims (TOKEN, DT_CREATE, ID_STICKER) VALUES (?, ?, ?)'
            )->execute([$token, time(), $stickerId]);
            return true;
        } catch (PDOException $e) {
            return false;
        } catch (Throwable $e) {
            return !self::isUsed($token);
        }
    }
}
