<?php
declare(strict_types=1);

/**
 * Who the assistant / staff can message via bots.send (linked staff/clients + known Max groups).
 */
final class BotRecipients
{
    /**
     * @return array{
     *   ok:bool,
     *   staff:list<array{id:int,name:string,login:string,telegram:bool,max:bool}>,
     *   clients:list<array{id:int,name:string,tel:string,telegram:bool,max:bool}>,
     *   chats:list<array{chat_id:string,platform:string,title:string,description?:string,source:string}>
     * }
     */
    public static function list(string $q = '', int $limit = 80): array
    {
        $q = trim($q);
        $limit = max(1, min(200, $limit));
        return [
            'ok' => true,
            'staff' => self::listStaff($q, $limit),
            'clients' => self::listClients($q, $limit),
            'chats' => self::listChats($q, $limit),
            'hint' => 'Staff: user_id=…; client: client_id=…; group: chat_id=… + platform=max. Only listed recipients. Client needs client-bot link in base_bot_links.',
        ];
    }

    /**
     * @return list<array{id:int,name:string,login:string,telegram:bool,max:bool}>
     */
    public static function listStaff(string $q = '', int $limit = 80): array
    {
        if (!BotLinksRepository::tableReady() || !Database::tableExists('base_users')) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT u.id, u.name, u.login, l.telegram_chat_id, l.max_user_id, l.BOT_NO_SEND
             FROM base_bot_links l
             INNER JOIN base_users u ON u.id = l.ID_ENTITY
             WHERE l.BOT_SOURCE = ?
               AND u.enabled = \'1\'
               AND (l.BOT_NO_SEND IS NULL OR l.BOT_NO_SEND = 0)
               AND (
                 (l.telegram_chat_id IS NOT NULL AND l.telegram_chat_id <> 0)
                 OR (l.max_user_id IS NOT NULL AND l.max_user_id <> 0)
               )';
        $params = [BotLinksRepository::SOURCE_STAFF];
        if ($q !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.login LIKE ? OR CAST(u.id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $sql .= ' ORDER BY u.name ASC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $tg = BotIdentity::chatIdValue($row['telegram_chat_id'] ?? '');
            $max = BotIdentity::chatIdValue($row['max_user_id'] ?? '');
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
                'login' => trim((string)($row['login'] ?? '')),
                'telegram' => $tg !== '',
                'max' => $max !== '',
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id:int,name:string,tel:string,telegram:bool,max:bool}>
     */
    public static function listClients(string $q = '', int $limit = 80): array
    {
        if (!BotLinksRepository::tableReady() || !Database::tableExists('base_clients')) {
            return [];
        }
        if (class_exists('ModuleManager', false) && !ModuleManager::isEnabled('clients')) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $enabledExpr = Database::columnExists('base_clients', 'enabled')
            ? 'AND (c.enabled = 1 OR c.enabled = \'1\')'
            : '';
        $sql = 'SELECT c.id, c.name, c.tel, l.telegram_chat_id, l.max_user_id, l.BOT_NO_SEND
             FROM base_bot_links l
             INNER JOIN base_clients c ON c.id = l.ID_ENTITY
             WHERE l.BOT_SOURCE = ?
               ' . $enabledExpr . '
               AND (l.BOT_NO_SEND IS NULL OR l.BOT_NO_SEND = 0)
               AND (
                 (l.telegram_chat_id IS NOT NULL AND l.telegram_chat_id <> 0)
                 OR (l.max_user_id IS NOT NULL AND l.max_user_id <> 0)
               )';
        $params = [BotLinksRepository::SOURCE_CLIENT];
        if ($q !== '') {
            $sql .= ' AND (c.name LIKE ? OR c.tel LIKE ? OR CAST(c.id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $sql .= ' ORDER BY c.name ASC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $tg = BotIdentity::chatIdValue($row['telegram_chat_id'] ?? '');
            $max = BotIdentity::chatIdValue($row['max_user_id'] ?? '');
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
                'tel' => trim((string)($row['tel'] ?? '')),
                'telegram' => $tg !== '',
                'max' => $max !== '',
            ];
        }
        return $out;
    }

    /**
     * Known outbound group chats (allowlisted assistant_groups + discovered titles + orders notify).
     *
     * @return list<array{chat_id:string,platform:string,title:string,description?:string,source:string}>
     */
    public static function listChats(string $q = '', int $limit = 80): array
    {
        $byId = [];
        $add = static function (
            string $chatId,
            string $platform,
            string $title,
            string $source,
            string $description = ''
        ) use (&$byId): void {
            $chatId = trim($chatId);
            if ($chatId === '') {
                return;
            }
            if (!isset($byId[$chatId])) {
                $byId[$chatId] = [
                    'chat_id' => $chatId,
                    'platform' => $platform !== '' ? $platform : 'max',
                    'title' => $title,
                    'description' => $description,
                    'source' => $source,
                ];
                return;
            }
            if ($title !== '' && trim((string)$byId[$chatId]['title']) === '') {
                $byId[$chatId]['title'] = $title;
            }
            if ($description !== '' && trim((string)($byId[$chatId]['description'] ?? '')) === '') {
                $byId[$chatId]['description'] = $description;
            }
        };

        if (class_exists('AssistantGroupsConfig', false) && ModuleManager::isEnabled('assistant_groups')) {
            foreach (AssistantGroupsConfig::groupChatsDetailed() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $add(
                    (string)($row['chat_id'] ?? ''),
                    'max',
                    trim((string)($row['title'] ?? '')),
                    'assistant_groups',
                    trim((string)($row['description'] ?? ''))
                );
            }
            if (class_exists('AssistantGroupsChats', false)) {
                foreach (AssistantGroupsChats::listDiscovered() as $row) {
                    if (!is_array($row) || empty($row['allowed'])) {
                        continue;
                    }
                    $add(
                        (string)($row['chat_id'] ?? ''),
                        'max',
                        trim((string)($row['title'] ?? '')),
                        'assistant_groups'
                    );
                }
            }
        }

        if (class_exists('OrdersConfig', false) && ModuleManager::isEnabled('orders')) {
            $oid = trim((string)OrdersConfig::maxNotifyChatId());
            if ($oid !== '') {
                $add($oid, 'max', t('bots.recipients.chat.orders'), 'orders');
            }
        }

        $out = array_values($byId);
        if ($q !== '') {
            $qLower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
            $out = array_values(array_filter($out, static function (array $c) use ($qLower): bool {
                $hay = ($c['chat_id'] ?? '') . ' ' . ($c['title'] ?? '') . ' '
                    . ($c['description'] ?? '') . ' ' . ($c['source'] ?? '');
                $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
                return strpos($hay, $qLower) !== false;
            }));
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string)($a['title'] ?: $a['chat_id']), (string)($b['title'] ?: $b['chat_id']));
        });
        return array_slice($out, 0, max(1, min(200, $limit)));
    }

    public static function staffReachable(int $userId): bool
    {
        return self::entityReachable(BotLinksRepository::SOURCE_STAFF, $userId);
    }

    public static function clientReachable(int $clientId): bool
    {
        return self::entityReachable(BotLinksRepository::SOURCE_CLIENT, $clientId);
    }

    private static function entityReachable(int $source, int $entityId): bool
    {
        if ($entityId < 1 || !BotLinksRepository::tableReady()) {
            return false;
        }
        $link = BotLinksRepository::find($source, $entityId);
        if ($link === null || !empty($link['BOT_NO_SEND'])) {
            return false;
        }
        $tg = BotIdentity::chatIdValue($link['telegram_chat_id'] ?? '');
        $max = BotIdentity::chatIdValue($link['max_user_id'] ?? '');
        return $tg !== '' || $max !== '';
    }

    public static function chatAllowed(string $chatId, string $platform = 'max'): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }
        foreach (self::listChats('', 200) as $c) {
            if ((string)($c['chat_id'] ?? '') === $chatId) {
                $p = strtolower(trim((string)($c['platform'] ?? 'max')));
                $want = strtolower(trim($platform));
                if ($want === '' || $want === 'both' || $p === '' || $p === $want) {
                    return true;
                }
            }
        }
        return false;
    }
}
