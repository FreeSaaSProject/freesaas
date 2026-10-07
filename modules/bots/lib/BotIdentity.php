<?php
declare(strict_types=1);

/** Phone normalize + staff/client messenger link via base_bot_links. */
final class BotIdentity
{
    public static function normalizePhone(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) === 10) {
            return '7' . $digits;
        }
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            return '7' . substr($digits, 1);
        }
        if (strlen($digits) >= 10) {
            return '7' . substr($digits, -10);
        }
        return null;
    }

    public static function chatIdValue($raw): string
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return '';
        }
        $s = trim((string)$raw);
        if ($s === '' || $s === '0') {
            return '';
        }
        return $s;
    }

    /** @return array<string,mixed>|null */
    public static function findStaffByPhone(string $tel): ?array
    {
        if (!Database::tableExists('base_users') || $tel === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, tel, enabled FROM base_users WHERE tel = ? LIMIT 1'
        );
        $st->execute([$tel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return BotLinksRepository::mergeOnto($row, BotLinksRepository::SOURCE_STAFF);
    }

    /** @return array<string,mixed>|null */
    public static function findStaffByChat(string $platform, string $chatId): ?array
    {
        return BotLinksRepository::findEntityByChat(
            BotLinksRepository::SOURCE_STAFF,
            $platform,
            $chatId
        );
    }

    public static function bindStaff(int $userId, string $platform, string $chatId): bool
    {
        return BotLinksRepository::bindPlatform(
            BotLinksRepository::SOURCE_STAFF,
            $userId,
            $platform,
            $chatId
        );
    }

    /** @return array<string,mixed>|null */
    public static function findClientByPhone(string $tel): ?array
    {
        if (!Database::tableExists('base_clients') || $tel === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, tel, enabled FROM base_clients WHERE tel = ? LIMIT 1'
        );
        $st->execute([$tel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return BotLinksRepository::mergeOnto($row, BotLinksRepository::SOURCE_CLIENT);
    }

    /** @return array<string,mixed>|null */
    public static function findClientByChat(string $platform, string $chatId): ?array
    {
        return BotLinksRepository::findEntityByChat(
            BotLinksRepository::SOURCE_CLIENT,
            $platform,
            $chatId
        );
    }

    public static function bindClient(int $clientId, string $platform, string $chatId): bool
    {
        return BotLinksRepository::bindPlatform(
            BotLinksRepository::SOURCE_CLIENT,
            $clientId,
            $platform,
            $chatId
        );
    }

    /** @return array{telegram:string,max:string,bot_no_send:bool} */
    public static function staffTargets(int $userId): array
    {
        return BotLinksRepository::targets(BotLinksRepository::SOURCE_STAFF, $userId);
    }

    /** @return array{telegram:string,max:string,bot_no_send:bool} */
    public static function clientTargets(int $clientId): array
    {
        return BotLinksRepository::targets(BotLinksRepository::SOURCE_CLIENT, $clientId);
    }
}
