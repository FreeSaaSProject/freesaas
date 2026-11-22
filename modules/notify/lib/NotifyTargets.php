<?php
declare(strict_types=1);

/**
 * Expands TARGETS_JSON into concrete recipients.
 *
 * Recipient: {rcpt_kind: client|staff|chat, rcpt_id, channels[], strategy, user_id?, client_id?, chat_id?, platform?, email?}
 */
final class NotifyTargets
{
    /**
     * @param list<array<string,mixed>> $targets
     * @param array<string,mixed> $subjects map subject name → {client_id|user_id|chat_id[,platform][,email]}
     * @return list<array<string,mixed>>
     */
    public static function expand(array $targets, array $subjects = []): array
    {
        $out = [];
        foreach ($targets as $t) {
            if (!is_array($t)) {
                continue;
            }
            $base = [
                'channels' => (array)($t['channels'] ?? ['bot']),
                'strategy' => (string)($t['strategy'] ?? 'all'),
            ];
            foreach (self::expandOne($t, $subjects) as $r) {
                $r = array_merge($base, $r);
                $key = $r['rcpt_kind'] . ':' . $r['rcpt_id'];
                // Same recipient from two targets: first definition wins.
                if (!isset($out[$key])) {
                    $out[$key] = $r;
                }
            }
        }
        return array_values($out);
    }

    /**
     * @param array<string,mixed> $t
     * @param array<string,mixed> $subjects
     * @return list<array<string,mixed>>
     */
    private static function expandOne(array $t, array $subjects): array
    {
        switch ((string)($t['kind'] ?? '')) {
            case 'chat':
                return self::chat($t);
            case 'staff':
                return self::staff(array_map('intval', (array)($t['user_ids'] ?? [])));
            case 'role':
                return self::staff(self::usersWhere('u.ID_STATUS = ?', [(int)($t['status_id'] ?? 0)]));
            case 'flag':
                return self::staff(self::usersWithFlag((string)($t['flag'] ?? '')));
            case 'on_shift':
                return self::staff(self::onShift((int)($t['status_id'] ?? 0)));
            case 'subject':
                return self::subject((string)($t['subject'] ?? ''), $subjects);
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private static function chat(array $t): array
    {
        $chatId = trim((string)($t['chat_id'] ?? ''));
        $platform = trim((string)($t['platform'] ?? ''));
        $botChatId = (int)($t['bot_chat_id'] ?? 0);
        if ($botChatId > 0 && class_exists('BotChatsRepository', false)) {
            $row = BotChatsRepository::find($botChatId);
            if ($row === null || empty($row['enabled'])) {
                return [];
            }
            $chatId = (string)$row['chat_id'];
            $platform = (string)$row['platform'];
        }
        if ($chatId === '') {
            return [];
        }
        if ($platform === '') {
            $platform = NotifyConfig::get('default_platform');
        }
        return [[
            'rcpt_kind' => 'chat',
            'rcpt_id' => $chatId,
            'chat_id' => $chatId,
            'platform' => $platform,
            'channels' => ['bot'],
        ]];
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private static function staff(array $ids): array
    {
        $out = [];
        foreach (array_unique(array_filter($ids, static fn ($i) => $i > 0)) as $id) {
            $out[] = ['rcpt_kind' => 'staff', 'rcpt_id' => (string)$id, 'user_id' => $id];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $subjects
     * @return list<array<string,mixed>>
     */
    private static function subject(string $name, array $subjects): array
    {
        $s = $subjects[$name] ?? null;
        if (!is_array($s)) {
            return [];
        }
        $email = trim((string)($s['email'] ?? ''));
        if (is_array($s['user_ids'] ?? null)) {
            $out = [];
            foreach ($s['user_ids'] as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    $out[] = ['rcpt_kind' => 'staff', 'rcpt_id' => (string)$uid, 'user_id' => $uid];
                }
            }
            return $out;
        }
        if ((int)($s['client_id'] ?? 0) > 0) {
            $id = (int)$s['client_id'];
            return [['rcpt_kind' => 'client', 'rcpt_id' => (string)$id, 'client_id' => $id, 'email' => $email]];
        }
        if ((int)($s['user_id'] ?? 0) > 0) {
            $id = (int)$s['user_id'];
            return [['rcpt_kind' => 'staff', 'rcpt_id' => (string)$id, 'user_id' => $id, 'email' => $email]];
        }
        $chat = trim((string)($s['chat_id'] ?? ''));
        if ($chat !== '') {
            return [[
                'rcpt_kind' => 'chat',
                'rcpt_id' => $chat,
                'chat_id' => $chat,
                'platform' => (string)($s['platform'] ?? '') !== '' ? (string)$s['platform'] : NotifyConfig::get('default_platform'),
                'channels' => ['bot'],
            ]];
        }
        return [];
    }

    /**
     * @param list<mixed> $params
     * @return list<int>
     */
    private static function usersWhere(string $cond, array $params): array
    {
        if (!Database::tableExists('base_users')) {
            return [];
        }
        $st = Database::pdo()->prepare("SELECT u.id FROM base_users u WHERE u.enabled = '1' AND " . $cond . ' ORDER BY u.id');
        $st->execute($params);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Staff whose role has the given flag column set. No guessing: a missing column means nobody.
     *
     * @return list<int>
     */
    private static function usersWithFlag(string $flag): array
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $flag)
            || !Database::tableExists('base_admin_statuses')
            || !Database::columnExists('base_admin_statuses', $flag)
        ) {
            return [];
        }
        return self::usersWhere(
            'u.ID_STATUS IN (SELECT s.ID FROM base_admin_statuses s WHERE s.`' . $flag . '` = 1)',
            []
        );
    }

    /**
     * Staff assigned a shift today (optionally narrowed to one role).
     *
     * @return list<int>
     */
    private static function onShift(int $statusId): array
    {
        if (!Database::tableExists('base_workweeks')) {
            return [];
        }
        $start = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $st = Database::pdo()->prepare('SELECT DISTINCT ID_OPERATOR FROM base_workweeks WHERE DT >= ? AND DT < ?');
        $st->execute([$start, $start + 86400]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === [] || $statusId < 1) {
            return $ids;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return self::usersWhere('u.ID_STATUS = ? AND u.id IN (' . $ph . ')', array_merge([$statusId], $ids));
    }
}
