<?php
declare(strict_types=1);

/** Phone normalize + staff/client messenger link on base_users / base_clients. */
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
            'SELECT id, name, tel, telegram_chat_id, max_user_id, BOT_NO_SEND, enabled
             FROM base_users WHERE tel = ? LIMIT 1'
        );
        $st->execute([$tel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function findStaffByChat(string $platform, string $chatId): ?array
    {
        $chatId = self::chatIdValue($chatId);
        if ($chatId === '' || !Database::tableExists('base_users')) {
            return null;
        }
        $col = $platform === 'max' ? 'max_user_id' : 'telegram_chat_id';
        if (!Database::columnExists('base_users', $col)) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, tel, telegram_chat_id, max_user_id, BOT_NO_SEND, enabled
             FROM base_users WHERE `' . $col . '` = ? LIMIT 1'
        );
        $st->execute([$chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function bindStaff(int $userId, string $platform, string $chatId): bool
    {
        if ($userId < 1 || !Database::tableExists('base_users')) {
            return false;
        }
        $chatId = self::chatIdValue($chatId);
        if ($chatId === '') {
            return false;
        }
        $col = $platform === 'max' ? 'max_user_id' : 'telegram_chat_id';
        if (!Database::columnExists('base_users', $col)) {
            return false;
        }
        $st = Database::pdo()->prepare('UPDATE base_users SET `' . $col . '` = ? WHERE id = ?');
        $st->execute([$chatId, $userId]);
        return $st->rowCount() >= 0;
    }

    /** @return array<string,mixed>|null */
    public static function findClientByPhone(string $tel): ?array
    {
        if (!Database::tableExists('base_clients') || $tel === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, tel, telegram_chat_id, max_user_id, enabled
             FROM base_clients WHERE tel = ? LIMIT 1'
        );
        if (!Database::columnExists('base_clients', 'telegram_chat_id')) {
            $st = Database::pdo()->prepare(
                'SELECT id, name, tel, enabled FROM base_clients WHERE tel = ? LIMIT 1'
            );
        }
        $st->execute([$tel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function findClientByChat(string $platform, string $chatId): ?array
    {
        $chatId = self::chatIdValue($chatId);
        if ($chatId === '' || !Database::tableExists('base_clients')) {
            return null;
        }
        $col = $platform === 'max' ? 'max_user_id' : 'telegram_chat_id';
        if (!Database::columnExists('base_clients', $col)) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, tel, telegram_chat_id, max_user_id, enabled
             FROM base_clients WHERE `' . $col . '` = ? LIMIT 1'
        );
        $st->execute([$chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function bindClient(int $clientId, string $platform, string $chatId): bool
    {
        if ($clientId < 1 || !Database::tableExists('base_clients')) {
            return false;
        }
        $chatId = self::chatIdValue($chatId);
        if ($chatId === '') {
            return false;
        }
        $col = $platform === 'max' ? 'max_user_id' : 'telegram_chat_id';
        if (!Database::columnExists('base_clients', $col)) {
            return false;
        }
        $st = Database::pdo()->prepare('UPDATE base_clients SET `' . $col . '` = ? WHERE id = ?');
        $st->execute([$chatId, $clientId]);
        return true;
    }

    /** @return array{telegram:string,max:string,bot_no_send:bool} */
    public static function staffTargets(int $userId): array
    {
        $out = ['telegram' => '', 'max' => '', 'bot_no_send' => false];
        if ($userId < 1 || !Database::tableExists('base_users')) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            'SELECT telegram_chat_id, max_user_id, BOT_NO_SEND FROM base_users WHERE id = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $out;
        }
        $out['telegram'] = self::chatIdValue($row['telegram_chat_id'] ?? '');
        $out['max'] = self::chatIdValue($row['max_user_id'] ?? '');
        $out['bot_no_send'] = !empty($row['BOT_NO_SEND']);
        return $out;
    }

    /** @return array{telegram:string,max:string,bot_no_send:bool} */
    public static function clientTargets(int $clientId): array
    {
        $out = ['telegram' => '', 'max' => '', 'bot_no_send' => false];
        if ($clientId < 1 || !Database::tableExists('base_clients')) {
            return $out;
        }
        if (!Database::columnExists('base_clients', 'telegram_chat_id')) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            'SELECT telegram_chat_id, max_user_id FROM base_clients WHERE id = ? LIMIT 1'
        );
        $st->execute([$clientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $out;
        }
        $out['telegram'] = self::chatIdValue($row['telegram_chat_id'] ?? '');
        $out['max'] = self::chatIdValue($row['max_user_id'] ?? '');
        return $out;
    }
}
