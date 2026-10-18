<?php
declare(strict_types=1);

/**
 * Remember Max group chat ids seen via bot_added / inbound (Max has no «list my chats»).
 */
final class AssistantGroupsChats
{
    private const FILE = 'var/assistant_groups/discovered_chats.json';
    private const MAX = 50;

    /**
     * @return list<array{chat_id:string,seen_at:int,source:string,title:string,allowed:bool}>
     */
    public static function listDiscovered(): array
    {
        $rows = self::load();
        $allowed = array_fill_keys(AssistantGroupsConfig::groupChatIds(), true);
        $out = [];
        foreach ($rows as $chatId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'chat_id' => (string)$chatId,
                'seen_at' => (int)($row['seen_at'] ?? 0),
                'source' => (string)($row['source'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'allowed' => isset($allowed[(string)$chatId]),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return ($b['seen_at'] <=> $a['seen_at']);
        });
        return $out;
    }

    public static function remember(string $chatId, string $source = 'event', string $title = ''): void
    {
        $chatId = trim($chatId);
        if ($chatId === '' || !isset($chatId[0]) || $chatId[0] !== '-') {
            return;
        }
        $rows = self::load();
        $prev = is_array($rows[$chatId] ?? null) ? $rows[$chatId] : [];
        $rows[$chatId] = [
            'seen_at' => time(),
            'source' => $source !== '' ? $source : (string)($prev['source'] ?? 'event'),
            'title' => $title !== '' ? $title : (string)($prev['title'] ?? ''),
        ];
        // Cap size: keep newest.
        if (count($rows) > self::MAX) {
            uasort($rows, static function ($a, $b): int {
                return ((int)($b['seen_at'] ?? 0) <=> (int)($a['seen_at'] ?? 0));
            });
            $rows = array_slice($rows, 0, self::MAX, true);
        }
        self::save($rows);

        if (AssistantGroupsConfig::autoAddDiscovered()) {
            self::addToAllowlist([$chatId]);
        }
    }

    /**
     * @param list<string> $chatIds
     */
    public static function addToAllowlist(array $chatIds): int
    {
        $cur = AssistantGroupsConfig::groupChatIds();
        $set = array_fill_keys($cur, true);
        $n = 0;
        foreach ($chatIds as $id) {
            $id = trim((string)$id);
            if ($id === '' || isset($set[$id])) {
                continue;
            }
            if (!isset($id[0]) || $id[0] !== '-') {
                continue;
            }
            $set[$id] = true;
            $n++;
        }
        if ($n < 1) {
            return 0;
        }
        Config::set('assistant_groups', 'group_chat_ids', implode(',', array_keys($set)));
        return $n;
    }

    /**
     * @param list<string> $chatIds
     */
    public static function dismiss(array $chatIds): void
    {
        $rows = self::load();
        foreach ($chatIds as $id) {
            unset($rows[trim((string)$id)]);
        }
        self::save($rows);
    }

    /** @return array<string,array<string,mixed>> */
    private static function load(): array
    {
        $path = base_path(self::FILE);
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** @param array<string,array<string,mixed>> $rows */
    private static function save(array $rows): void
    {
        $path = base_path(self::FILE);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            $path,
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
        );
    }
}
