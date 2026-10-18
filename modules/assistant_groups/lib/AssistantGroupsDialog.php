<?php
declare(strict_types=1);

/**
 * Per-group Max chat history (shared by staff in that chat — not personal AssistantDialog).
 */
final class AssistantGroupsDialog
{
    private const DIR = 'var/assistant_groups/dialogs';
    private const MAX_TURNS = 16;
    private const TTL_SEC = 86400; // 24h idle expire
    private const BOT_MIDS_MAX = 30;

    /**
     * @return list<array{role:string,content:string}>
     */
    public static function getHistory(string $chatId): array
    {
        $row = self::load($chatId);
        $messages = is_array($row['messages'] ?? null) ? $row['messages'] : [];
        return self::normalize($messages);
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    public static function saveHistory(string $chatId, array $history, ?string $lastBotMid = null): void
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return;
        }
        $row = self::load($chatId);
        $mids = is_array($row['bot_mids'] ?? null) ? $row['bot_mids'] : [];
        if ($lastBotMid !== null && $lastBotMid !== '') {
            $mids[] = $lastBotMid;
            $mids = array_values(array_unique(array_filter(array_map('strval', $mids))));
            if (count($mids) > self::BOT_MIDS_MAX) {
                $mids = array_slice($mids, -self::BOT_MIDS_MAX);
            }
        }
        $row = [
            'updated_at' => time(),
            'messages' => self::trim(self::normalize($history)),
            'bot_mids' => $mids,
        ];
        self::write($chatId, $row);
    }

    public static function clear(string $chatId): void
    {
        $path = self::path($chatId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function isBotMessageMid(string $chatId, string $mid): bool
    {
        $mid = self::normMid($mid);
        if ($mid === '') {
            return false;
        }
        $row = self::load($chatId);
        $mids = is_array($row['bot_mids'] ?? null) ? $row['bot_mids'] : [];
        foreach ($mids as $known) {
            if (self::normMid((string)$known) === $mid) {
                return true;
            }
        }
        return false;
    }

    private static function normMid(string $mid): string
    {
        $mid = trim($mid);
        if (stripos($mid, 'mid.') === 0) {
            return $mid;
        }
        // Accept bare hex if stored/compared inconsistently.
        return $mid;
    }

    public static function rememberBotMid(string $chatId, string $mid): void
    {
        $mid = trim($mid);
        $chatId = trim($chatId);
        if ($mid === '' || $chatId === '') {
            return;
        }
        $row = self::load($chatId);
        $mids = is_array($row['bot_mids'] ?? null) ? $row['bot_mids'] : [];
        $mids[] = $mid;
        $mids = array_values(array_unique(array_filter(array_map('strval', $mids))));
        if (count($mids) > self::BOT_MIDS_MAX) {
            $mids = array_slice($mids, -self::BOT_MIDS_MAX);
        }
        $row['bot_mids'] = $mids;
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        self::write($chatId, $row);
    }

    /**
     * @param mixed $raw
     * @return list<array{role:string,content:string}>
     */
    public static function normalize($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = trim((string)($m['role'] ?? ''));
            $content = trim((string)($m['content'] ?? ''));
            if (($role !== 'user' && $role !== 'assistant') || $content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @return list<array{role:string,content:string}>
     */
    public static function trim(array $history): array
    {
        $max = self::MAX_TURNS * 2;
        if (count($history) <= $max) {
            return $history;
        }
        return array_values(array_slice($history, -$max));
    }

    /** @return array<string,mixed> */
    private static function load(string $chatId): array
    {
        $path = self::path($chatId);
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $updated = (int)($data['updated_at'] ?? 0);
        if ($updated > 0 && (time() - $updated) > self::TTL_SEC) {
            @unlink($path);
            return [];
        }
        return $data;
    }

    /** @param array<string,mixed> $row */
    private static function write(string $chatId, array $row): void
    {
        $path = self::path($chatId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    }

    private static function path(string $chatId): string
    {
        $safe = preg_replace('/[^0-9A-Za-z_-]+/', '_', $chatId) ?? 'chat';
        return base_path(self::DIR . '/' . $safe . '.json');
    }
}
