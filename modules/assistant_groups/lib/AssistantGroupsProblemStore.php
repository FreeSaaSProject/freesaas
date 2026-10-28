<?php
declare(strict_types=1);

/**
 * Staff-flagged problem cases from Max group Confirm keyboard («!» / Problem).
 * JSONL under var/assistant_groups/problems/ — not a ticket; for later review.
 */
final class AssistantGroupsProblemStore
{
    /**
     * @param array{
     *   chat_id?:string,
     *   user_id?:int,
     *   user_name?:string,
     *   pending_token?:string,
     *   op_id?:string,
     *   args?:array<string,mixed>,
     *   summary?:string,
     *   user_text?:string,
     *   bot_mid?:string
     * } $payload
     * @return array{ok:bool,error:string,id:string}
     */
    public static function save(array $payload): array
    {
        $chatId = trim((string)($payload['chat_id'] ?? ''));
        if ($chatId === '') {
            return ['ok' => false, 'error' => 'empty chat_id', 'id' => ''];
        }

        $summary = trim((string)($payload['summary'] ?? ''));
        $userText = trim((string)($payload['user_text'] ?? ''));
        if (function_exists('mb_substr')) {
            $summary = mb_substr($summary, 0, 4000);
            $userText = mb_substr($userText, 0, 2000);
        } else {
            $summary = substr($summary, 0, 4000);
            $userText = substr($userText, 0, 2000);
        }

        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $id = 'agp_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
        $row = [
            'id' => $id,
            'created_at' => gmdate('c'),
            'chat_id' => $chatId,
            'user_id' => (int)($payload['user_id'] ?? 0),
            'user_name' => trim((string)($payload['user_name'] ?? '')),
            'pending_token' => trim((string)($payload['pending_token'] ?? '')),
            'op_id' => trim((string)($payload['op_id'] ?? '')),
            'args' => $args,
            'summary' => $summary,
            'user_text' => $userText,
            'bot_mid' => trim((string)($payload['bot_mid'] ?? '')),
        ];

        if (!self::ensureDir()) {
            return ['ok' => false, 'error' => 'mkdir failed', 'id' => ''];
        }
        $path = self::monthPath();
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'json encode failed', 'id' => ''];
        }
        if (@file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'write failed', 'id' => ''];
        }
        return ['ok' => true, 'error' => '', 'id' => $id];
    }

    public static function dir(): string
    {
        return base_path('var/assistant_groups/problems');
    }

    private static function monthPath(): string
    {
        return self::dir() . '/' . gmdate('Y-m') . '.jsonl';
    }

    private static function ensureDir(): bool
    {
        $dir = self::dir();
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }
}
