<?php
declare(strict_types=1);

/**
 * Staff feedback on assistant replies — JSONL under var/assistant/feedback/ (not DB).
 * One line per rating; used later to mine regression / prompt cases.
 */
final class AssistantFeedbackStore
{
    public const RATING_GOOD = 'good';
    public const RATING_BAD = 'bad';

    /**
     * @return list<string> reason ids (stable, not translated)
     */
    public static function reasonIds(): array
    {
        return [
            'inaccurate',
            'ignored_instructions',
            'off_topic',
            'lost_context',
            'slow_or_errors',
            'safety_legal',
            'other',
        ];
    }

    /**
     * @param array{
     *   rating:string,
     *   reasons?:list<string>,
     *   comment?:string,
     *   messages?:list<array{role?:string,content?:string}>,
     *   user_id?:int,
     *   locale?:string
     * } $payload
     * @return array{ok:bool,error:string,id:string}
     */
    public static function save(array $payload): array
    {
        $rating = trim((string)($payload['rating'] ?? ''));
        if ($rating !== self::RATING_GOOD && $rating !== self::RATING_BAD) {
            return ['ok' => false, 'error' => 'bad rating', 'id' => ''];
        }

        $allowedReasons = array_fill_keys(self::reasonIds(), true);
        $reasons = [];
        foreach ((array)($payload['reasons'] ?? []) as $r) {
            $r = trim((string)$r);
            if ($r !== '' && isset($allowedReasons[$r])) {
                $reasons[$r] = true;
            }
        }
        $reasons = array_keys($reasons);

        $comment = trim((string)($payload['comment'] ?? ''));
        if (function_exists('mb_substr')) {
            $comment = mb_substr($comment, 0, 4000);
        } else {
            $comment = substr($comment, 0, 4000);
        }

        $messages = [];
        foreach ((array)($payload['messages'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = trim((string)($m['role'] ?? ''));
            $content = trim((string)($m['content'] ?? ''));
            if (($role !== 'user' && $role !== 'assistant') || $content === '') {
                continue;
            }
            if (function_exists('mb_substr')) {
                $content = mb_substr($content, 0, 8000);
            } else {
                $content = substr($content, 0, 8000);
            }
            $messages[] = ['role' => $role, 'content' => $content];
            if (count($messages) >= 6) {
                break;
            }
        }
        if ($messages === []) {
            return ['ok' => false, 'error' => 'empty context', 'id' => ''];
        }

        $id = 'fb_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
        $row = [
            'id' => $id,
            'created_at' => gmdate('c'),
            'user_id' => (int)($payload['user_id'] ?? 0),
            'locale' => trim((string)($payload['locale'] ?? '')),
            'rating' => $rating,
            'reasons' => $reasons,
            'comment' => $comment,
            'messages' => $messages,
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
        return base_path('var/assistant/feedback');
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
