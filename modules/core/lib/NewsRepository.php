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

    /** @var array<int, array{NAME:string,COLOR:string,TYPE?:int,DEADLINE_POLICY?:string}>|null */
    private static $statusesCache = null;

    /** @return array<int, array{NAME:string,COLOR:string,TYPE?:int,DEADLINE_POLICY?:string}> */
    public static function statuses(): array
    {
        if (self::$statusesCache !== null) {
            return self::$statusesCache;
        }
        self::$statusesCache = [];
        if (!Database::tableExists('base_news_statuses')) {
            return self::$statusesCache;
        }
        $rows = Database::pdo()->query(
            'SELECT ID, NAME, COLOR, TYPE, DEADLINE_POLICY FROM base_news_statuses ORDER BY ID'
        )->fetchAll();
        foreach ($rows as $r) {
            self::$statusesCache[(int)$r['ID']] = [
                'NAME' => (string)$r['NAME'],
                'COLOR' => (string)$r['COLOR'],
                'TYPE' => (int)($r['TYPE'] ?? 0),
                'DEADLINE_POLICY' => (string)($r['DEADLINE_POLICY'] ?? 'optional'),
            ];
        }
        return self::$statusesCache;
    }

    public static function flushStatusesCache(): void
    {
        self::$statusesCache = null;
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
        $unsigned = !empty($opts['unsigned']) && $type !== 4;
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = ($type === 2)
                ? 'STATUS ASC, DT DESC, ID DESC'
                : 'DT DESC, ID DESC';
        }

        $pdo = Database::pdo();
        $where = ['DISABLED = 0'];
        $params = [];
        $flatMode = $unread || $unsigned;

        // Favorites virtual type
        if ($type === 4) {
            $favIds = UsersRepository::favoriteIds($userId);
            if (!$favIds) {
                return ['items' => [], 'total' => 0, 'folders' => []];
            }
            $where[] = 'ID IN (' . implode(',', array_map('intval', $favIds)) . ')';
            $where[] = 'HAS_CHILDREN = 0';
        } elseif ($flatMode) {
            // Flat leaves across folders (oper ANSTYPE=1 unread / ANSTYPE=2 needs-sign)
            if ($userId < 1 || !Database::tableExists('base_views')) {
                return ['items' => [], 'total' => 0, 'folders' => []];
            }
            if ($type > 0 && $type < 4) {
                $where[] = 'TYPE = ?';
                $params[] = $type;
            }
            $archiveSql = self::sqlExcludeArchiveChildren('base_news');
            if ($archiveSql !== '') {
                $where[] = $archiveSql;
            }
            if ($unread) {
                $where[] = 'ID NOT IN (
                    SELECT v.ID_OBJ FROM base_views v
                    WHERE v.TYPE_OBJ = \'base_news\' AND v.ID_USER = ?
                )';
                $params[] = $userId;
            }
            if ($unsigned) {
                $where[] = 'SIGN_REQ = 1';
                $where[] = 'ID NOT IN (
                    SELECT v.ID_OBJ FROM base_views v
                    WHERE v.TYPE_OBJ = \'base_news_sign\' AND v.ID_USER = ?
                )';
                $params[] = $userId;
            }
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
        if ($userId > 0 && !Auth::isSuperAdmin()) {
            $acc = self::accessWhereSql($userId, 'base_news');
            if ($acc['sql'] !== '') {
                $where[] = $acc['sql'];
                foreach ($acc['params'] as $p) {
                    $params[] = $p;
                }
            }
        }

        $sqlWhere = implode(' AND ', $where);

        // Folders in current scope (HAS_CHILDREN=1); flat unread/unsigned modes are leaves only
        $folders = [];
        if ($type !== 4 && !$flatMode) {
            $folderWhere = $sqlWhere . ' AND HAS_CHILDREN = 1';
            $stF = $pdo->prepare(
                "SELECT ID, DT, ID_USER, NAME, SHORT, TYPE, STATUS, PID, HAS_CHILDREN, IGNORE_FOLDER
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

    /** Encode ids as oper-style #1#,#2# list (empty = everyone / no restriction). */
    public static function encodeIdList(array $ids): string
    {
        $clean = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $clean[$n] = $n;
            }
        }
        if (!$clean) {
            return '';
        }
        return '#' . implode('#,#', array_values($clean)) . '#';
    }

    public static function idInAccessList(string $raw, int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return false;
        }
        if ($raw === (string)$id) {
            return true;
        }
        return strpos($raw, '#' . $id . '#') !== false;
    }

    public static function canView(array $row, int $userId): bool
    {
        if ((int)($row['DISABLED'] ?? 0) === 1) {
            return Auth::isSuperAdmin();
        }
        if ($userId < 1) {
            return false;
        }
        if (Auth::isSuperAdmin()) {
            return true;
        }
        $author = (int)($row['ID_USER'] ?? 0);
        if ($author === $userId) {
            return true;
        }
        $type = (int)($row['TYPE'] ?? 0);
        $g1 = (string)($row['ACCESS_GROUP'] ?? '');
        $g2 = (string)($row['ACCESS_GROUP2'] ?? '');
        $au = (string)($row['ACCESS_USER'] ?? '');
        $emptyG1 = ($g1 === '' || $g1 === '0');
        $emptyG2 = ($g2 === '' || $g2 === '0');
        $emptyAu = ($au === '' || $au === '0');

        if ($type === 3) {
            return self::idInAccessList($au, $userId);
        }

        // Role without SHOW_NEWS cannot see "everyone" (no group / no users) items
        if ($emptyG1 && $emptyAu && !Auth::capability('SHOW_NEWS')) {
            return false;
        }

        if ($type === 0) {
            $after = self::userShowNewsAfter($userId);
            if ($after > 0 && (int)($row['DT'] ?? 0) < $after) {
                return false;
            }
        }

        $okG1 = $emptyG1 || self::idInAccessList($g1, Auth::statusId());
        $okG2 = $emptyG2 || self::idInAccessList($g2, Auth::statusId2());
        $okAu = $emptyAu || self::idInAccessList($au, $userId);
        return $okG1 && $okG2 && $okAu;
    }

    private static function userShowNewsAfter(int $userId): int
    {
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        $u = UsersRepository::find($userId);
        return $cache[$userId] = (int)($u['showNewsAfter'] ?? 0);
    }

    /**
     * SQL access filter for non-superadmin (oper list/notices predicates).
     * @return array{sql:string,params:list<mixed>}
     */
    public static function accessWhereSql(int $userId, string $alias = 'base_news'): array
    {
        if ($userId < 1 || Auth::isSuperAdmin()) {
            return ['sql' => '', 'params' => []];
        }
        $a = $alias !== '' ? $alias . '.' : '';
        $group = Auth::statusId();
        $group2 = Auth::statusId2();
        $showNews = Auth::capability('SHOW_NEWS');
        $params = [];

        // Params must follow placeholder order in the final SQL string.
        $dm = '(' . $a . 'TYPE = 3 AND (' . $a . 'ID_USER = ? OR ' . $a . 'ACCESS_USER = ? OR ' . $a . 'ACCESS_USER LIKE ?))';
        $params[] = $userId;
        $params[] = (string)$userId;
        $params[] = '%#' . $userId . '#%';

        $g1Empty = '(' . $a . 'ACCESS_GROUP = \'\' OR ' . $a . 'ACCESS_GROUP = \'0\' OR ' . $a . 'ACCESS_GROUP IS NULL)';
        $g2Empty = '(' . $a . 'ACCESS_GROUP2 = \'\' OR ' . $a . 'ACCESS_GROUP2 = \'0\' OR ' . $a . 'ACCESS_GROUP2 IS NULL)';
        $auEmpty = '(' . $a . 'ACCESS_USER = \'\' OR ' . $a . 'ACCESS_USER = \'0\' OR ' . $a . 'ACCESS_USER IS NULL)';

        $g1Ok = '(' . $g1Empty . ' OR ' . $a . 'ACCESS_GROUP = ? OR ' . $a . 'ACCESS_GROUP LIKE ?)';
        $g2Ok = '(' . $g2Empty . ' OR ' . $a . 'ACCESS_GROUP2 = ? OR ' . $a . 'ACCESS_GROUP2 LIKE ?)';
        $auOk = '(' . $auEmpty . ' OR ' . $a . 'ACCESS_USER = ? OR ' . $a . 'ACCESS_USER LIKE ?)';

        $everyoneOk = $showNews
            ? '1=1'
            : 'NOT (' . $g1Empty . ' AND ' . $auEmpty . ')';

        // ID_USER ? comes before ACCESS_* placeholders inside nonDm
        $nonDm = '(' . $a . 'TYPE <> 3 AND (' . $a . 'ID_USER = ? OR ((' . $g1Ok . ') AND (' . $g2Ok . ') AND (' . $auOk . ') AND (' . $everyoneOk . '))))';
        $params[] = $userId;
        $params[] = (string)$group;
        $params[] = '%#' . $group . '#%';
        $params[] = (string)$group2;
        $params[] = '%#' . $group2 . '#%';
        $params[] = (string)$userId;
        $params[] = '%#' . $userId . '#%';

        $after = self::userShowNewsAfter($userId);
        $newsAfter = '';
        if ($after > 0) {
            $newsAfter = ' AND NOT (' . $a . 'TYPE = 0 AND ' . $a . 'DT < ?)';
            $params[] = $after;
        }

        return ['sql' => '((' . $dm . ') OR (' . $nonDm . '))' . $newsAfter, 'params' => $params];
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

    /** Normalize COMMENT_PURGE_LIMIT: 0 = off, otherwise 1…100000. */
    public static function normalizeCommentPurgeLimit($raw): int
    {
        $n = (int)$raw;
        if ($n < 1) {
            return 0;
        }
        return min(100000, $n);
    }

    /** @param array{NAME:string,BIG?:string,SHORT?:string,TYPE:int,STATUS?:int,ID_CURATOR?:int,DT_DEADLINE?:int,PID?:int,HAS_CHILDREN?:int,USERS_RESP?:string,ACCESS_USER?:string,PID_CONTRACT?:int,COMMENTS_LOCKED?:int,COMMENT_PURGE_LIMIT?:int} $data */
    public static function create(array $data, int $authorId): int
    {
        $now = time();
        $type = (int)($data['TYPE'] ?? 1);
        $status = isset($data['STATUS']) ? (int)$data['STATUS'] : ($type === 2 ? 1 : 0);
        $hasChildren = !empty($data['HAS_CHILDREN']) ? 1 : 0;
        $ignoreFolder = $hasChildren && !empty($data['IGNORE_FOLDER']) ? 1 : 0;
        $signReq = (!$hasChildren && !empty($data['SIGN_REQ'])) ? 1 : 0;
        $pidContract = max(0, (int)($data['PID_CONTRACT'] ?? 0));
        $purgeLimit = $hasChildren ? 0 : self::normalizeCommentPurgeLimit($data['COMMENT_PURGE_LIMIT'] ?? 0);
        $accessGroup = (string)($data['ACCESS_GROUP'] ?? '');
        $accessGroup2 = (string)($data['ACCESS_GROUP2'] ?? '');
        $accessUser = (string)($data['ACCESS_USER'] ?? '');
        if ($type === 3) {
            $accessGroup = '';
            $accessGroup2 = '';
        }
        if ($hasChildren) {
            $status = 0;
            $signReq = 0;
            $data['ID_CURATOR'] = 0;
            $data['USERS_RESP'] = '';
            $data['DT_DEADLINE'] = 0;
        }
        $hasPurgeCol = Database::columnExists('base_news', 'COMMENT_PURGE_LIMIT');
        $sql = 'INSERT INTO base_news SET
                DT=?, ID_USER=?, NAME=?, SHORT=?, BIG=?, ACCESS_GROUP=?, ACCESS_GROUP2=?, ACCESS_USER=?,
                TYPE=?, DISABLED=0, STATUS=?, USERS_WATCHED=?, SIGN_REQ=?, USERS_SIGNED=?, COMMENTS_LOCKED=?,
                DT_DEADLINE=?, USERS_RESP=?, DT_STARTLINE=0, ID_CURATOR=?, PID=?, HAS_CHILDREN=?,
                ID_USER_UPDATE=?, DT_UPDATE=?, PID_CONTRACT=?, ID_OFFER_COMMENT=0, IGNORE_FOLDER=?';
        $params = [
            $now,
            $authorId,
            (string)($data['NAME'] ?? ''),
            (string)($data['SHORT'] ?? ''),
            (string)($data['BIG'] ?? ''),
            $accessGroup,
            $accessGroup2,
            $accessUser,
            $type,
            $status,
            '',
            $signReq,
            '',
            isset($data['COMMENTS_LOCKED']) ? (int)$data['COMMENTS_LOCKED'] : ($hasChildren ? 1 : 0),
            (int)($data['DT_DEADLINE'] ?? 0),
            (string)($data['USERS_RESP'] ?? ''),
            (int)($data['ID_CURATOR'] ?? ($type === 2 && !$hasChildren ? $authorId : 0)),
            (int)($data['PID'] ?? 0),
            $hasChildren,
            $authorId,
            $now,
            $pidContract,
            $ignoreFolder,
        ];
        if ($hasPurgeCol) {
            $sql .= ', COMMENT_PURGE_LIMIT=?';
            $params[] = $purgeLimit;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $newId = (int)Database::pdo()->lastInsertId();
        if ($type === 2 && !$hasChildren && class_exists('GcalSyncService')) {
            GcalSyncService::maybeEnqueue($newId, 'create');
        }
        return $newId;
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
            'ACCESS_GROUP' => 'ACCESS_GROUP',
            'ACCESS_GROUP2' => 'ACCESS_GROUP2',
            'ACCESS_USER' => 'ACCESS_USER',
            'SIGN_REQ' => 'SIGN_REQ',
            'PID' => 'PID',
            'HAS_CHILDREN' => 'HAS_CHILDREN',
            'IGNORE_FOLDER' => 'IGNORE_FOLDER',
            'USERS_RESP' => 'USERS_RESP',
            'COMMENTS_LOCKED' => 'COMMENTS_LOCKED',
            'PID_CONTRACT' => 'PID_CONTRACT',
            'COMMENT_PURGE_LIMIT' => 'COMMENT_PURGE_LIMIT',
        ];
        if (array_key_exists('HAS_CHILDREN', $data) && empty($data['HAS_CHILDREN'])) {
            $data['IGNORE_FOLDER'] = 0;
        } elseif (array_key_exists('IGNORE_FOLDER', $data) && array_key_exists('HAS_CHILDREN', $data)) {
            $data['IGNORE_FOLDER'] = !empty($data['HAS_CHILDREN']) && !empty($data['IGNORE_FOLDER']) ? 1 : 0;
        }
        if (array_key_exists('HAS_CHILDREN', $data) && !empty($data['HAS_CHILDREN'])) {
            $data['SIGN_REQ'] = 0;
            $data['STATUS'] = 0;
            $data['ID_CURATOR'] = 0;
            $data['USERS_RESP'] = '';
            $data['DT_DEADLINE'] = 0;
            $data['COMMENT_PURGE_LIMIT'] = 0;
        }
        if (array_key_exists('TYPE', $data) && (int)$data['TYPE'] === 3) {
            $data['ACCESS_GROUP'] = '';
            $data['ACCESS_GROUP2'] = '';
        }
        if (array_key_exists('COMMENT_PURGE_LIMIT', $data)) {
            if (!Database::columnExists('base_news', 'COMMENT_PURGE_LIMIT')) {
                unset($data['COMMENT_PURGE_LIMIT']);
            } else {
                $data['COMMENT_PURGE_LIMIT'] = self::normalizeCommentPurgeLimit($data['COMMENT_PURGE_LIMIT']);
            }
        }
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
            if (array_key_exists('BIG', $data)
                && (string)$data['BIG'] !== (string)($old['BIG'] ?? '')
                && empty($data['keep_signs'])
            ) {
                self::clearViewMarks($id, true, true);
            }
        }
        $fields[] = 'ID_USER_UPDATE = ?';
        $params[] = $editorId;
        $fields[] = 'DT_UPDATE = ?';
        $params[] = time();
        $params[] = $id;
        $sql = 'UPDATE base_news SET ' . implode(', ', $fields) . ' WHERE ID = ?';
        Database::pdo()->prepare($sql)->execute($params);

        $type = array_key_exists('TYPE', $data) ? (int)$data['TYPE'] : (int)($old['TYPE'] ?? 0);
        $gcalKeys = ['STATUS', 'DT_DEADLINE', 'NAME', 'USERS_RESP', 'ID_CURATOR', 'TYPE'];
        $touched = false;
        foreach ($gcalKeys as $k) {
            if (array_key_exists($k, $data)) {
                $touched = true;
                break;
            }
        }
        if ($type === 2 && $touched && class_exists('GcalSyncService')) {
            GcalSyncService::maybeEnqueue($id, 'update');
        }
        if (array_key_exists('COMMENT_PURGE_LIMIT', $data)
            && (int)$data['COMMENT_PURGE_LIMIT'] > 0
            && class_exists('CommentsRepository', false)
        ) {
            CommentsRepository::purgeOverLimit($id);
        }
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
        $excl = self::sqlExcludeArchiveChildren('base_news');
        $acc = $userId > 0 ? self::accessWhereSql($userId, 'base_news') : ['sql' => '', 'params' => []];
        $extra = '';
        $params = [];
        if ($excl !== '') {
            $extra .= ' AND ' . $excl;
        }
        if ($acc['sql'] !== '') {
            $extra .= ' AND (' . $acc['sql'] . ')';
            $params = $acc['params'];
        }
        $st = Database::pdo()->prepare(
            'SELECT TYPE, COUNT(*) AS c FROM base_news
             WHERE DISABLED=0 AND HAS_CHILDREN=0 AND TYPE IN (0,1,2,3)'
            . $extra . '
             GROUP BY TYPE'
        );
        $st->execute($params);
        foreach ($st->fetchAll() ?: [] as $r) {
            $out[(int)$r['TYPE']] = (int)$r['c'];
        }
        // Folders count toward availability of a type tab (incl. archive folders themselves)
        $stF = Database::pdo()->prepare(
            'SELECT TYPE, COUNT(*) AS c FROM base_news
             WHERE DISABLED=0 AND HAS_CHILDREN=1 AND TYPE IN (0,1,2,3)'
            . ($acc['sql'] !== '' ? ' AND (' . $acc['sql'] . ')' : '') . '
             GROUP BY TYPE'
        );
        $stF->execute($acc['params']);
        foreach ($stF->fetchAll() ?: [] as $r) {
            $t = (int)$r['TYPE'];
            $out[$t] += (int)$r['c'];
        }
        $out[4] = count(UsersRepository::favoriteIds($userId));
        $out[0] = $out[1] + $out[2] + $out[3];
        return $out;
    }

    /** Folder IDs with IGNORE_FOLDER=1 (oper archive / «не приглашать»). */
    public static function archiveFolderIds(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (!Database::tableExists('base_news') || !Database::columnExists('base_news', 'IGNORE_FOLDER')) {
            return $cache;
        }
        $rows = Database::pdo()->query(
            'SELECT ID FROM base_news
             WHERE DISABLED = 0 AND HAS_CHILDREN = 1 AND IGNORE_FOLDER = 1'
        )->fetchAll() ?: [];
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            if ($id > 0) {
                $cache[] = $id;
            }
        }
        return $cache;
    }

    /**
     * SQL condition (no leading AND): hide direct children of archive folders.
     * Empty string when there are no archive folders.
     */
    public static function sqlExcludeArchiveChildren(string $tableOrAlias = ''): string
    {
        $ids = self::archiveFolderIds();
        if (!$ids) {
            return '';
        }
        $col = $tableOrAlias !== '' ? $tableOrAlias . '.PID' : 'PID';
        return 'IFNULL(' . $col . ', 0) NOT IN (' . implode(',', array_map('intval', $ids)) . ')';
    }

    public static function isArchiveFolderId(int $folderId): bool
    {
        return $folderId > 0 && in_array($folderId, self::archiveFolderIds(), true);
    }

    /** Direct children count (any DISABLED), used to lock HAS_CHILDREN. */
    public static function childCount(int $newsId): int
    {
        if ($newsId < 1 || !Database::tableExists('base_news')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_news WHERE PID = ?');
        $st->execute([$newsId]);
        return (int)$st->fetchColumn();
    }

    public static function markViewed(int $newsId, int $userId): void
    {
        self::ensureViewRow($newsId, $userId, 'base_news');
    }

    public static function hasSigned(int $newsId, int $userId): bool
    {
        if ($newsId < 1 || $userId < 1 || !Database::tableExists('base_views')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ = ? AND ID_USER = ? LIMIT 1'
        );
        $st->execute([$newsId, 'base_news_sign', $userId]);
        return (bool)$st->fetchColumn();
    }

    /** Acknowledge SIGN_REQ by attaching a reply (oper newSign). */
    public static function markSigned(int $newsId, int $userId): void
    {
        self::ensureViewRow($newsId, $userId, 'base_news_sign');
    }

    private static function ensureViewRow(int $newsId, int $userId, string $typeObj): void
    {
        if ($newsId < 1 || $userId < 1 || !Database::tableExists('base_views')) {
            return;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ = ? AND ID_USER = ? LIMIT 1'
        );
        $st->execute([$newsId, $typeObj, $userId]);
        if ($st->fetchColumn()) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_views SET DT = ?, ID_OBJ = ?, TYPE_OBJ = ?, ID_USER = ?'
        );
        $ins->execute([time(), $newsId, $typeObj, $userId]);
    }

    /** Clear read/sign marks when body changes (oper default). */
    public static function clearViewMarks(int $newsId, bool $reads = true, bool $signs = true): void
    {
        if ($newsId < 1 || !Database::tableExists('base_views')) {
            return;
        }
        $types = [];
        if ($reads) {
            $types[] = 'base_news';
        }
        if ($signs) {
            $types[] = 'base_news_sign';
        }
        if (!$types) {
            return;
        }
        $in = implode(',', array_map(static function ($t) {
            return Database::pdo()->quote($t);
        }, $types));
        Database::pdo()->prepare(
            "DELETE FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ IN ($in)"
        )->execute([$newsId]);
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

    /**
     * Machine-readable news/folder row for agent JSON.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public static function forAgent(?array $row, bool $withBody = false): ?array
    {
        if ($row === null) {
            return null;
        }
        $id = (int)($row['ID'] ?? 0);
        $type = (int)($row['TYPE'] ?? 0);
        $isFolder = (int)($row['HAS_CHILDREN'] ?? 0) === 1;
        $out = [
            'id' => $id,
            'type' => $type,
            'type_label' => self::typeLabel($type),
            'name' => (string)($row['NAME'] ?? ''),
            'short' => (string)($row['SHORT'] ?? ''),
            'dt' => (int)($row['DT'] ?? 0),
            'dt_update' => (int)($row['DT_UPDATE'] ?? 0),
            'id_user' => (int)($row['ID_USER'] ?? 0),
            'id_user_update' => (int)($row['ID_USER_UPDATE'] ?? 0),
            'pid' => (int)($row['PID'] ?? 0),
            'is_folder' => $isFolder,
            'ignore_folder' => !empty($row['IGNORE_FOLDER']) ? 1 : 0,
            'sign_req' => !empty($row['SIGN_REQ']) ? 1 : 0,
            'status' => (int)($row['STATUS'] ?? 0),
            'id_curator' => (int)($row['ID_CURATOR'] ?? 0),
            'dt_deadline' => (int)($row['DT_DEADLINE'] ?? 0),
            'users_resp' => self::parseUserList((string)($row['USERS_RESP'] ?? '')),
            'access_group' => self::parseUserList((string)($row['ACCESS_GROUP'] ?? '')),
            'access_group2' => self::parseUserList((string)($row['ACCESS_GROUP2'] ?? '')),
            'access_user' => self::parseUserList((string)($row['ACCESS_USER'] ?? '')),
            'comments_locked' => !empty($row['COMMENTS_LOCKED']) ? 1 : 0,
            'comment_purge_limit' => (int)($row['COMMENT_PURGE_LIMIT'] ?? 0),
            'disabled' => !empty($row['DISABLED']) ? 1 : 0,
        ];
        if ($withBody) {
            $out['body'] = (string)($row['BIG'] ?? '');
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function listForAgent(array $items, bool $withBody = false): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::forAgent($row, $withBody);
            if ($item !== null) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Normalize ACCESS_* POST value: id[], "#id#,#id#", or "1,2,3".
     *
     * @param mixed $raw
     */
    public static function accessFromPost($raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        if (is_array($raw)) {
            return self::encodeIdList(array_map('intval', $raw));
        }
        $s = trim((string)$raw);
        if ($s === '' || $s === '0') {
            return '';
        }
        if (strpos($s, '#') !== false) {
            return $s;
        }
        $parts = preg_split('/[,\s]+/', $s) ?: [];
        return self::encodeIdList(array_map('intval', $parts));
    }
}
