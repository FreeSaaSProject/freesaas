<?php
declare(strict_types=1);

/**
 * Per-chat bot UI message ids (oper tg_bot_ephemeral_*).
 * File store under var/bots/ephemeral/ — deleted on next UI replace.
 */
final class BotEphemeral
{
    /**
     * @return list<string>
     */
    public static function takeMessageIds(string $audience, string $platform, string $chatId): array
    {
        $out = [];
        self::withLock($audience, $platform, $chatId, static function (array $data) use (&$out): array {
            foreach ((array)($data['message_ids'] ?? []) as $v) {
                $s = trim((string)$v);
                if ($s !== '' && $s !== '0') {
                    $out[] = $s;
                }
            }
            $data['message_ids'] = [];
            return $data;
        });
        return array_values(array_unique($out));
    }

    public static function saveMessageId(string $audience, string $platform, string $chatId, string $messageId): void
    {
        $mid = trim($messageId);
        if ($mid === '' || $mid === '0') {
            return;
        }
        self::withLock($audience, $platform, $chatId, static function (array $data) use ($mid): array {
            $ids = [];
            foreach ((array)($data['message_ids'] ?? []) as $v) {
                $s = trim((string)$v);
                if ($s !== '' && $s !== '0') {
                    $ids[] = $s;
                }
            }
            $ids[] = $mid;
            $data['message_ids'] = array_values(array_unique($ids));
            return $data;
        });
    }

    public static function setLatestMenuMessageId(string $audience, string $platform, string $chatId, string $messageId): void
    {
        $mid = trim($messageId);
        if ($mid === '' || $mid === '0') {
            return;
        }
        self::withLock($audience, $platform, $chatId, static function (array $data) use ($mid): array {
            $data['latest_menu_message_id'] = $mid;
            return $data;
        });
    }

    public static function takeLatestMenuMessageId(string $audience, string $platform, string $chatId): ?string
    {
        $out = null;
        self::withLock($audience, $platform, $chatId, static function (array $data) use (&$out): array {
            $s = trim((string)($data['latest_menu_message_id'] ?? ''));
            $out = ($s !== '' && $s !== '0') ? $s : null;
            $data['latest_menu_message_id'] = null;
            return $data;
        });
        return $out;
    }

    /** @param callable(array):(?array) $modify */
    private static function withLock(string $audience, string $platform, string $chatId, callable $modify): void
    {
        $file = self::filePath($audience, $platform, $chatId);
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }
        $raw = stream_get_contents($fp);
        $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $data = array_merge([
            'message_ids' => [],
            'latest_menu_message_id' => null,
        ], $data);
        $newData = $modify($data);
        if (is_array($newData)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($newData, JSON_UNESCAPED_UNICODE));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function filePath(string $audience, string $platform, string $chatId): string
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return '';
        }
        $audience = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($audience))) ?: 'x';
        $platform = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($platform))) ?: 'x';
        $safeChat = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $chatId) ?: 'chat';
        return base_path('var/bots/ephemeral/' . $audience . '_' . $platform . '_' . $safeChat . '.json');
    }
}
