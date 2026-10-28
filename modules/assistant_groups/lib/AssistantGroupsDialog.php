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
        $keyboardMids = is_array($row['keyboard_mids'] ?? null) ? $row['keyboard_mids'] : [];
        $choices = is_array($row['choices'] ?? null) ? $row['choices'] : [];
        $softOptions = is_array($row['soft_options'] ?? null) ? $row['soft_options'] : [];
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
            'keyboard_mids' => self::trimMids($keyboardMids),
            // Preserve button state — saveHistory must not wipe choices / soft picks.
            'choices' => $choices,
            'soft_options' => $softOptions,
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
        $row['bot_mids'] = self::trimMids($mids);
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        if (!isset($row['keyboard_mids']) || !is_array($row['keyboard_mids'])) {
            $row['keyboard_mids'] = [];
        }
        self::write($chatId, $row);
    }

    /**
     * Store validated draft choices for Max buttons (keyed by pending token).
     *
     * @param list<array{op_id?:string,args?:array,label?:string}> $choices
     */
    public static function setChoices(string $chatId, string $token, array $choices): void
    {
        $chatId = trim($chatId);
        $token = trim($token);
        if ($chatId === '' || $token === '' || !preg_match('/^[a-f0-9]{16}$/', $token)) {
            return;
        }
        $clean = [];
        foreach ($choices as $c) {
            if (!is_array($c)) {
                continue;
            }
            $opId = trim((string)($c['op_id'] ?? ''));
            $label = trim((string)($c['label'] ?? ''));
            $args = is_array($c['args'] ?? null) ? $c['args'] : [];
            if ($opId === '' || $label === '') {
                continue;
            }
            $clean[] = ['op_id' => $opId, 'args' => $args, 'label' => $label];
            if (count($clean) >= 5) {
                break;
            }
        }
        $row = self::load($chatId);
        $map = is_array($row['choices'] ?? null) ? $row['choices'] : [];
        if ($clean === []) {
            unset($map[$token]);
        } else {
            $map[$token] = $clean;
        }
        // Keep only a couple of recent tokens.
        if (count($map) > 3) {
            $map = array_slice($map, -3, null, true);
        }
        $row['choices'] = $map;
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        if (!isset($row['bot_mids']) || !is_array($row['bot_mids'])) {
            $row['bot_mids'] = [];
        }
        if (!isset($row['keyboard_mids']) || !is_array($row['keyboard_mids'])) {
            $row['keyboard_mids'] = [];
        }
        self::write($chatId, $row);
    }

    /**
     * @return list<array{op_id:string,args:array<string,mixed>,label:string}>
     */
    public static function getChoices(string $chatId, string $token): array
    {
        $chatId = trim($chatId);
        $token = trim($token);
        if ($chatId === '' || $token === '') {
            return [];
        }
        $row = self::load($chatId);
        $map = is_array($row['choices'] ?? null) ? $row['choices'] : [];
        $list = is_array($map[$token] ?? null) ? $map[$token] : [];
        $out = [];
        foreach ($list as $c) {
            if (!is_array($c)) {
                continue;
            }
            $opId = trim((string)($c['op_id'] ?? ''));
            $label = trim((string)($c['label'] ?? ''));
            if ($opId === '' || $label === '') {
                continue;
            }
            $out[] = [
                'op_id' => $opId,
                'args' => is_array($c['args'] ?? null) ? $c['args'] : [],
                'label' => $label,
            ];
        }
        return $out;
    }

    public static function clearChoices(string $chatId, string $token = ''): void
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return;
        }
        $row = self::load($chatId);
        if ($token === '') {
            $row['choices'] = [];
        } else {
            $map = is_array($row['choices'] ?? null) ? $row['choices'] : [];
            unset($map[$token]);
            $row['choices'] = $map;
        }
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        self::write($chatId, $row);
    }

    /**
     * Soft 1/2/… labels from an LLM reply that had no pending draft yet.
     *
     * @param array<int,string> $options map n => label
     */
    public static function setSoftOptions(string $chatId, array $options): void
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return;
        }
        $clean = [];
        foreach ($options as $n => $label) {
            $n = (int)$n;
            $label = trim((string)$label);
            if ($n < 1 || $n > 9 || $label === '') {
                continue;
            }
            $clean[$n] = $label;
            if (count($clean) >= 5) {
                break;
            }
        }
        ksort($clean, SORT_NUMERIC);
        $row = self::load($chatId);
        $row['soft_options'] = $clean;
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        if (!isset($row['bot_mids']) || !is_array($row['bot_mids'])) {
            $row['bot_mids'] = [];
        }
        if (!isset($row['keyboard_mids']) || !is_array($row['keyboard_mids'])) {
            $row['keyboard_mids'] = [];
        }
        if (!isset($row['choices']) || !is_array($row['choices'])) {
            $row['choices'] = [];
        }
        self::write($chatId, $row);
    }

    /** @return array<int,string> */
    public static function getSoftOptions(string $chatId): array
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return [];
        }
        $row = self::load($chatId);
        $raw = is_array($row['soft_options'] ?? null) ? $row['soft_options'] : [];
        $out = [];
        foreach ($raw as $n => $label) {
            $n = (int)$n;
            $label = trim((string)$label);
            if ($n >= 1 && $n <= 9 && $label !== '') {
                $out[$n] = $label;
            }
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    public static function clearSoftOptions(string $chatId): void
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return;
        }
        $row = self::load($chatId);
        $row['soft_options'] = [];
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        self::write($chatId, $row);
    }

    /** Track a bot message that still has confirm/cancel buttons. */
    public static function rememberKeyboardMid(string $chatId, string $mid): void
    {
        $mid = trim($mid);
        $chatId = trim($chatId);
        if ($mid === '' || $chatId === '') {
            return;
        }
        $row = self::load($chatId);
        $mids = is_array($row['keyboard_mids'] ?? null) ? $row['keyboard_mids'] : [];
        $mids[] = $mid;
        $row['keyboard_mids'] = self::trimMids($mids);
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        if (!isset($row['bot_mids']) || !is_array($row['bot_mids'])) {
            $row['bot_mids'] = [];
        }
        self::write($chatId, $row);
    }

    /**
     * Pop all keyboard message ids (caller clears buttons on Max).
     *
     * @return list<string>
     */
    public static function takeKeyboardMids(string $chatId): array
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return [];
        }
        $row = self::load($chatId);
        $mids = is_array($row['keyboard_mids'] ?? null) ? $row['keyboard_mids'] : [];
        $row['keyboard_mids'] = [];
        $row['updated_at'] = time();
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        if (!isset($row['bot_mids']) || !is_array($row['bot_mids'])) {
            $row['bot_mids'] = [];
        }
        self::write($chatId, $row);
        return self::trimMids(array_map('strval', $mids));
    }

    /** @return list<string> */
    public static function listKeyboardMids(string $chatId): array
    {
        $row = self::load($chatId);
        $mids = is_array($row['keyboard_mids'] ?? null) ? $row['keyboard_mids'] : [];
        return self::trimMids(array_map('strval', $mids));
    }

    /**
     * @param list<mixed> $mids
     * @return list<string>
     */
    private static function trimMids(array $mids): array
    {
        $out = array_values(array_unique(array_filter(array_map(
            static fn($m): string => trim((string)$m),
            $mids
        ), static fn(string $m): bool => $m !== '')));
        if (count($out) > self::BOT_MIDS_MAX) {
            $out = array_slice($out, -self::BOT_MIDS_MAX);
        }
        return $out;
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
