<?php
declare(strict_types=1);

final class NewsRepository
{
    public static function typeLabel(int $type): string
    {
        if ($type >= 0 && $type <= 4) {
            return t('core.news.type.' . $type);
        }
        return t('core.news.type_unknown', ['n' => $type]);
    }

    /** Tabs for type switcher — no combined "Все". */
    public static function typeTabs(): array
    {
        return [
            ['id' => 2, 'label' => t('core.news.tab.2'), 'add' => t('core.news.add.2'), 'addType' => 2],
            ['id' => 1, 'label' => t('core.news.tab.1'), 'add' => t('core.news.add.1'), 'addType' => 1],
            ['id' => 3, 'label' => t('core.news.tab.3'), 'add' => t('core.news.add.3'), 'addType' => 3],
            ['id' => 4, 'label' => t('core.news.tab.4'), 'add' => null, 'addType' => null],
        ];
    }

    /** @return array<int, array{NAME:string,COLOR:string}> */
    public static function statuses(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (!Database::tableExists('base_news_statuses')) {
            return $cache;
        }
        $rows = Database::pdo()->query('SELECT ID, NAME, COLOR FROM base_news_statuses ORDER BY ID')->fetchAll();
        foreach ($rows as $r) {
            $cache[(int)$r['ID']] = ['NAME' => (string)$r['NAME'], 'COLOR' => (string)$r['COLOR']];
        }
        return $cache;
    }

    /** @return array<string,string> request sort key => SQL expression */
    public static function listSortMap(int $type = 0): array
    {
        $map = [
            'date' => 'DT',
            'name' => 'NAME',
            'id' => 'ID',
            'deadline' => 'DT_DEADLINE',
            'status' => 'STATUS',
        ];
        // Keep default behaviour for tickets: status then date.
        if ($type === 2) {
            $map['date'] = 'DT';
        }
        return $map;
    }

    /**
     * @return array{items:list<array>, total:int, folders:list<array>}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_news')) {
            return ['items' => [], 'total' => 0, 'folders' => []];
        }
        $type = isset($opts['type']) ? (int)$opts['type'] : 0;
        $status = isset($opts['status']) ? (int)$opts['status'] : -1;
        $q = trim((string)($opts['q'] ?? ''));
        $pid = isset($opts['pid']) ? (int)$opts['pid'] : 0;
        $limit = (int)($opts['limit'] ?? 40);
        if ($limit < 0) {
            $limit = 0;
        } elseif ($limit > 0) {
            $limit = min(3000, max(1, $limit));
        }
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $userId = (int)($opts['user_id'] ?? 0);
        $unread = !empty($opts['unread']) && $type !== 4;
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = ($type === 2)
                ? 'STATUS ASC, DT DESC, ID DESC'
                : 'DT DESC, ID DESC';
        }

        $pdo = Database::pdo();
        $where = ['DISABLED = 0'];
        $params = [];

        // Favorites virtual type
        if ($type === 4) {
            $favIds = UsersRepository::favoriteIds($userId);
            if (!$favIds) {
                return ['items' => [], 'total' => 0, 'folders' => []];
            }
            $where[] = 'ID IN (' . implode(',', array_map('intval', $favIds)) . ')';
            $where[] = 'HAS_CHILDREN = 0';
        } elseif ($unread) {
            // Oper ANSTYPE=1: flat unread leaves across folders (no PID root filter)
            if ($userId < 1 || !Database::tableExists('base_views')) {
                return ['items' => [], 'total' => 0, 'folders' => []];
            }
            if ($type > 0 && $type < 4) {
                $where[] = 'TYPE = ?';
                $params[] = $type;
            }
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM base_views v
                WHERE v.ID_OBJ = base_news.ID
                  AND v.TYPE_OBJ = \'base_news\'
                  AND v.ID_USER = ?
            )';
            $params[] = $userId;
        } else {
            if ($pid > 0) {
                $where[] = 'PID = ?';
                $params[] = $pid;
            } else {
                $where[] = '(PID = 0 OR PID IS NULL)';
            }
            if ($type > 0 && $type < 4) {
                $where[] = 'TYPE = ?';
                $params[] = $type;
            }
        }

        if ($status >= 0) {
            $where[] = 'STATUS = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR SHORT LIKE ? OR BIG LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (($type === 3 || $type === 0) && $userId > 0) {
            if ($type === 3) {
                $where[] = '(ID_USER = ? OR ACCESS_USER = ? OR ACCESS_USER LIKE ?)';
                $params[] = $userId;
                $params[] = (string)$userId;
                $params[] = '%#' . $userId . '#%';
            } elseif ($type === 0) {
                $where[] = '(TYPE <> 3 OR ID_USER = ? OR ACCESS_USER = ? OR ACCESS_USER LIKE ?)';
                $params[] = $userId;
                $params[] = (string)$userId;
                $params[] = '%#' . $userId . '#%';
            }
        }

        $sqlWhere = implode(' AND ', $where);

        // Folders in current scope (HAS_CHILDREN=1); unread mode is flat leaves only
        $folders = [];
        if ($type !== 4 && !$unread) {
            $folderWhere = $sqlWhere . ' AND HAS_CHILDREN = 1';
            $stF = $pdo->prepare(
                "SELECT ID, DT, ID_USER, NAME, SHORT, TYPE, STATUS, PID, HAS_CHILDREN
                 FROM base_news WHERE $folderWhere ORDER BY NAME ASC"
            );
            $stF->execute($params);
            $folders = $stF->fetchAll() ?: [];
        }

        // Items: not folders
        $itemWhere = $sqlWhere . ' AND HAS_CHILDREN = 0';
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_news WHERE $itemWhere");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT ID, DT, ID_USER, NAME, SHORT, TYPE, STATUS, DT_DEADLINE, ID_CURATOR, DT_UPDATE,
                    USERS_RESP, PID, HAS_CHILDREN, ACCESS_GROUP, ACCESS_GROUP2, ACCESS_USER
             FROM base_news
             WHERE $itemWhere
             ORDER BY $order"
            . ($limit > 0 ? " LIMIT $limit OFFSET $offset" : '')
        );
        $st->execute($params);
        $items = $st->fetchAll() ?: [];
        return ['items' => $items, 'total' => $total, 'folders' => $folders];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_news')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_news WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Breadcrumb folders from root to $pid. */
    public static function breadcrumbs(int $pid): array
    {
        $chain = [];
        $guard = 0;
        while ($pid > 0 && $guard++ < 20) {
            $row = self::find($pid);
            if (!$row) {
                break;
            }
            array_unshift($chain, [
                'ID' => (int)$row['ID'],
                'NAME' => (string)$row['NAME'],
                'TYPE' => (int)$row['TYPE'],
            ]);
            $pid = (int)$row['PID'];
        }
        return $chain;
    }

    /** Parse USERS_RESP "#1#,#2#" → user ids. */
    public static function parseUserList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        preg_match_all('/#?(\d+)#?/', $raw, $m);
        $ids = [];
        foreach ($m[1] ?? [] as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        return array_values($ids);
    }

    public static function canView(array $row, int $userId): bool
    {
        if ((int)$row['DISABLED'] === 1) {
            return false;
        }
        if ((int)$row['TYPE'] !== 3) {
            return true;
        }
        if ($userId < 1) {
            return false;
        }
        if ((int)$row['ID_USER'] === $userId) {
            return true;
        }
        $access = (string)$row['ACCESS_USER'];
        if ($access === (string)$userId) {
            return true;
        }
        return strpos($access, '#' . $userId . '#') !== false;
    }

    public static function canEdit(array $row, int $userId): bool
    {
        if (!self::canView($row, $userId)) {
            return false;
        }
        if (Auth::isSuperAdmin()) {
            return true;
        }
        if ((int)$row['TYPE'] === 2) {
            return $userId > 0 && (
                (int)$row['ID_USER'] === $userId
                || (int)$row['ID_CURATOR'] === $userId
                || in_array($userId, self::parseUserList((string)($row['USERS_RESP'] ?? '')), true)
            );
        }
        return $userId > 0 && (
            (int)$row['ID_USER'] === $userId
            || (int)$row['ID_CURATOR'] === $userId
        );
    }

    public static function canManageTicket(array $row, int $userId): bool
    {
        if ((int)$row['TYPE'] !== 2 || $userId < 1) {
            return false;
        }
        if (Auth::isSuperAdmin()) {
            return true;
        }
        return (int)$row['ID_CURATOR'] === $userId
            || (int)$row['ID_USER'] === $userId
            || in_array($userId, self::parseUserList((string)($row['USERS_RESP'] ?? '')), true);
    }

    /** @param array{NAME:string,BIG?:string,SHORT?:string,TYPE:int,STATUS?:int,ID_CURATOR?:int,DT_DEADLINE?:int,PID?:int,HAS_CHILDREN?:int,USERS_RESP?:string,ACCESS_USER?:string,PID_CONTRACT?:int,COMMENTS_LOCKED?:int} $data */
    public static function create(array $data, int $authorId): int
    {
        $now = time();
        $type = (int)($data['TYPE'] ?? 1);
        $status = isset($data['STATUS']) ? (int)$data['STATUS'] : ($type === 2 ? 1 : 0);
        $hasChildren = !empty($data['HAS_CHILDREN']) ? 1 : 0;
        $pidContract = max(0, (int)($data['PID_CONTRACT'] ?? 0));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_news SET
                DT=?, ID_USER=?, NAME=?, SHORT=?, BIG=?, ACCESS_GROUP=?, ACCESS_GROUP2=?, ACCESS_USER=?,
                TYPE=?, DISABLED=0, STATUS=?, USERS_WATCHED=?, SIGN_REQ=0, USERS_SIGNED=?, COMMENTS_LOCKED=?,
                DT_DEADLINE=?, USERS_RESP=?, DT_STARTLINE=0, ID_CURATOR=?, PID=?, HAS_CHILDREN=?,
                ID_USER_UPDATE=?, DT_UPDATE=?, PID_CONTRACT=?, ID_OFFER_COMMENT=0, IGNORE_FOLDER=0'
        );
        $st->execute([
            $now,
            $authorId,
            (string)($data['NAME'] ?? ''),
            (string)($data['SHORT'] ?? ''),
            (string)($data['BIG'] ?? ''),
            '',
            '',
            (string)($data['ACCESS_USER'] ?? ''),
            $type,
            $status,
            '',
            '',
            isset($data['COMMENTS_LOCKED']) ? (int)$data['COMMENTS_LOCKED'] : ($hasChildren ? 1 : 0),
            (int)($data['DT_DEADLINE'] ?? 0),
            (string)($data['USERS_RESP'] ?? ''),
            (int)($data['ID_CURATOR'] ?? ($type === 2 ? $authorId : 0)),
            (int)($data['PID'] ?? 0),
            $hasChildren,
            $authorId,
            $now,
            $pidContract,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data, int $editorId): void
    {
        $fields = [];
        $params = [];
        $map = [
            'NAME' => 'NAME',
            'SHORT' => 'SHORT',
            'BIG' => 'BIG',
            'TYPE' => 'TYPE',
            'STATUS' => 'STATUS',
            'ID_CURATOR' => 'ID_CURATOR',
            'DT_DEADLINE' => 'DT_DEADLINE',
            'ACCESS_USER' => 'ACCESS_USER',
            'PID' => 'PID',
            'HAS_CHILDREN' => 'HAS_CHILDREN',
            'USERS_RESP' => 'USERS_RESP',
            'COMMENTS_LOCKED' => 'COMMENTS_LOCKED',
            'PID_CONTRACT' => 'PID_CONTRACT',
        ];
        foreach ($map as $key => $col) {
            if (array_key_exists($key, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$key];
            }
        }
        if (!$fields) {
            return;
        }
        $old = self::find($id);
        if ($old) {
            NewsLogsRepository::maybeSnapshot($old, $editorId);
        }
        $fields[] = 'ID_USER_UPDATE = ?';
        $params[] = $editorId;
        $fields[] = 'DT_UPDATE = ?';
        $params[] = time();
        $params[] = $id;
        $sql = 'UPDATE base_news SET ' . implode(', ', $fields) . ' WHERE ID = ?';
        Database::pdo()->prepare($sql)->execute($params);
    }

    public static function softDelete(int $id, int $editorId): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_news SET DISABLED=1, ID_USER_UPDATE=?, DT_UPDATE=? WHERE ID=?'
        );
        $st->execute([$editorId, time(), $id]);
    }

    /** Folder options for a type (HAS_CHILDREN=1). */
    public static function folderOptions(int $type, int $excludeId = 0): array
    {
        if (!Database::tableExists('base_news')) {
            return [];
        }
        $sql = 'SELECT ID, NAME, PID FROM base_news WHERE DISABLED=0 AND HAS_CHILDREN=1';
        $params = [];
        if ($type > 0 && $type < 4) {
            $sql .= ' AND TYPE = ?';
            $params[] = $type;
        }
        if ($excludeId > 0) {
            $sql .= ' AND ID <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY NAME ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    }

    public static function countsByType(int $userId): array
    {
        $out = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
        if (!Database::tableExists('base_news')) {
            return $out;
        }
        $rows = Database::pdo()->query(
            'SELECT TYPE, COUNT(*) AS c FROM base_news
             WHERE DISABLED=0 AND HAS_CHILDREN=0 AND TYPE IN (0,1,2,3)
             GROUP BY TYPE'
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int)$r['TYPE']] = (int)$r['c'];
        }
        // Folders count toward availability of a type tab
        $folderRows = Database::pdo()->query(
            'SELECT TYPE, COUNT(*) AS c FROM base_news
             WHERE DISABLED=0 AND HAS_CHILDREN=1 AND TYPE IN (0,1,2,3)
             GROUP BY TYPE'
        )->fetchAll();
        foreach ($folderRows as $r) {
            $t = (int)$r['TYPE'];
            $out[$t] += (int)$r['c'];
        }
        $out[4] = count(UsersRepository::favoriteIds($userId));
        $out[0] = $out[1] + $out[2] + $out[3];
        return $out;
    }

    public static function markViewed(int $newsId, int $userId): void
    {
        if ($newsId < 1 || $userId < 1 || !Database::tableExists('base_views')) {
            return;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ = ? AND ID_USER = ? LIMIT 1'
        );
        $st->execute([$newsId, 'base_news', $userId]);
        if ($st->fetchColumn()) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_views SET DT = ?, ID_OBJ = ?, TYPE_OBJ = ?, ID_USER = ?'
        );
        $ins->execute([time(), $newsId, 'base_news', $userId]);
    }

    /** @return list<int> */
    public static function viewerIds(int $newsId): array
    {
        if ($newsId < 1 || !Database::tableExists('base_views')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_USER FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ = ? ORDER BY DT ASC'
        );
        $st->execute([$newsId, 'base_news']);
        $ids = [];
        foreach ($st->fetchAll() as $r) {
            $id = (int)$r['ID_USER'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** @return array<int,int> newsId => comment count (via comments module when enabled) */
    public static function commentCounts(array $ids): array
    {
        $payload = EventBus::emit('comments.counts', [
            'ids' => array_values(array_filter(array_map('intval', $ids))),
            'counts' => [],
        ]);
        if (!is_array($payload)) {
            return [];
        }
        $counts = $payload['counts'] ?? [];
        return is_array($counts) ? $counts : [];
    }

    /** @return array<int,int> newsId => viewer count */
    public static function viewerCounts(array $ids): array
    {
        $out = [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !Database::tableExists('base_views')) {
            return $out;
        }
        $in = implode(',', $ids);
        $rows = Database::pdo()->query(
            "SELECT ID_OBJ, COUNT(DISTINCT ID_USER) AS c FROM base_views
             WHERE TYPE_OBJ = 'base_news' AND ID_OBJ IN ($in) GROUP BY ID_OBJ"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int)$r['ID_OBJ']] = (int)$r['c'];
        }
        return $out;
    }

    /** Group status names by IDs from #id# lists. */
    public static function groupNames(string $raw): array
    {
        $ids = self::parseUserList($raw);
        if (!$ids || !Database::tableExists('base_admin_statuses')) {
            return [];
        }
        $names = [];
        $map = UsersRepository::statusNames();
        foreach ($ids as $id) {
            $names[] = $map[$id] ?? ('#' . $id);
        }
        return $names;
    }

    /** HTML chips for ACCESS_GROUP / ACCESS_GROUP2 / ACCESS_USER. */
    public static function accessHtml(array $row): string
    {
        $parts = [];
        $g1 = self::groupNames((string)($row['ACCESS_GROUP'] ?? ''));
        $g2 = self::groupNames((string)($row['ACCESS_GROUP2'] ?? ''));
        $users = self::parseUserList((string)($row['ACCESS_USER'] ?? ''));
        foreach ($g1 as $name) {
            $parts[] = '<span class="access-chip is-group" title="' . te('core.news.access.group') . '">' . e($name) . '</span>';
        }
        foreach ($g2 as $name) {
            $parts[] = '<span class="access-chip is-group2" title="' . te('core.news.access.group2') . '">' . e($name) . '</span>';
        }
        foreach ($users as $uid) {
            $parts[] = UsersRepository::badgeHtml($uid, false);
        }
        if (!$parts) {
            return '<span class="muted">' . te('core.news.access.everyone') . '</span>';
        }
        return implode(' ', $parts);
    }
}
