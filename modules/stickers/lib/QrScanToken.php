<?php
declare(strict_types=1);

/** One-shot operation token (legacy qr2 TOKEN on ins/outs). */
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
        if (!self::isValidFormat($token) || !Database::tableExists('base_good_sticker_ins')) {
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
    }
}
