<?php
declare(strict_types=1);

final class UsersRepository
{
    /** @var array<int, ?array> */
    private static $findCache = [];

    /** @return array<int,string> */
    public static function statusNames(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (!Database::tableExists('base_admin_statuses')) {
            return $cache;
        }
        $rows = Database::pdo()->query('SELECT ID, NAME FROM base_admin_statuses ORDER BY ID')->fetchAll();
        foreach ($rows as $r) {
            $cache[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $cache;
    }

    /**
     * Admin statuses for staff role (ASSIST_STA &lt; 1) or partner (ASSIST_STA &gt; 0).
     * @return array<int,string>
     */
    public static function statusNamesFiltered(bool $partner = false): array
    {
        if (!Database::tableExists('base_admin_statuses')) {
            return [];
        }
        $hasAssist = Database::columnExists('base_admin_statuses', 'ASSIST_STA');
        $sql = 'SELECT ID, NAME FROM base_admin_statuses WHERE 1=1';
        if ($hasAssist) {
            $sql .= $partner ? ' AND ASSIST_STA > 0' : ' AND ASSIST_STA < 1';
        }
        if (Database::columnExists('base_admin_statuses', 'ENABLED') && $partner) {
            $sql .= ' AND ENABLED > 0';
        }
        $sql .= ' ORDER BY ID';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll() ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /** Parse legacy "#1#,#2#" / "1,2" / "2" into int ids. */
    public static function parseHashIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $ids = [];
        if (preg_match_all('/\d+/', $raw, $m)) {
            foreach ($m[0] as $n) {
                $id = (int)$n;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    /** Encode ids as legacy "#1#,#2#". */
    public static function encodeHashIds(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return '';
        }
        return '#' . implode('#,#', $ids) . '#';
    }

    /** @return array<string,int> */
    public static function decodePermissions($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $un = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($un) || !isset($un['permissions']) || !is_array($un['permissions'])) {
            return [];
        }
        $out = [];
        foreach ($un['permissions'] as $k => $v) {
            $out[(string)$k] = (int)$v;
        }
        return $out;
    }

    /**
     * Merge permission keys into existing status blob.
     * @param array<string,int> $permissions
     */
    public static function encodeStatusBlob($existingRaw, array $permissions): string
    {
        $blob = [];
        if (is_string($existingRaw) && $existingRaw !== '') {
            $un = @unserialize($existingRaw, ['allowed_classes' => false]);
            if (is_array($un)) {
                $blob = $un;
            }
        }
        $old = [];
        if (isset($blob['permissions']) && is_array($blob['permissions'])) {
            foreach ($blob['permissions'] as $k => $v) {
                $old[(string)$k] = (int)$v;
            }
        }
        foreach ($permissions as $k => $v) {
            $old[(string)$k] = (int)$v ? 1 : 0;
        }
        $blob['permissions'] = $old;
        return serialize($blob);
    }

    /** @return list<array{id:int,login:string,name:string}> */
    public static function listForCopy(): array
    {
        if (!Database::tableExists('base_users')) {
            return [];
        }
        $rows = Database::pdo()->query(
            "SELECT id, login, name FROM base_users WHERE enabled = '1' ORDER BY name ASC, login ASC LIMIT 500"
        )->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'login' => (string)$r['login'],
                'name' => (string)$r['name'],
            ];
        }
        return $out;
    }

    /**
     * Enabled staff names for selects; always include $extraIds (e.g. disabled linked user).
     *
     * @param list<int> $extraIds
     * @return array<int,string> id => label
     */
    public static function staffNamesIncluding(array $extraIds = []): array
    {
        $out = [];
        foreach (self::listForCopy() as $u) {
            $id = (int)$u['id'];
            $label = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
            $out[$id] = $label;
        }
        foreach ($extraIds as $id) {
            $id = (int)$id;
            if ($id < 1 || isset($out[$id])) {
                continue;
            }
            $u = self::find($id);
            if (!$u) {
                $out[$id] = '#' . $id;
                continue;
            }
            $label = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
            $enabled = !empty($u['enabled']);
            $out[$id] = $label . ($enabled ? '' : ' (' . t('core.users.filter_disabled') . ')');
        }
        return $out;
    }

    /**
     * Permissions declared by enabled modules, grouped by module id.
     * @return array<string,array{name:string,keys:list<array{id:string,label:string}>}>
     */
    public static function availablePermissionKeys(): array
    {
        $grouped = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            $perms = $meta['permissions'] ?? [];
            if (!is_array($perms) || !$perms) {
                continue;
            }
            $keys = [];
            foreach ($perms as $p) {
                if (is_array($p)) {
                    $pid = trim((string)($p['id'] ?? ''));
                    $label = trim((string)($p['label'] ?? ''));
                    if ($pid === '') {
                        continue;
                    }
                    $keys[] = [
                        'id' => $pid,
                        'label' => $label !== '' ? t($label) : $pid,
                    ];
                } else {
                    $pid = trim((string)$p);
                    if ($pid === '') {
                        continue;
                    }
                    $keys[] = ['id' => $pid, 'label' => $pid];
                }
            }
            if ($keys) {
                $modName = (string)($meta['name'] ?? $id);
                $grouped[$id] = [
                    'name' => t($modName),
                    'keys' => $keys,
                ];
            }
        }
        return $grouped;
    }

    /** @return array<string,string> request sort key => SQL expression */
    public static function listSortMap(): array
    {
        $map = [];
        if (Database::columnExists('base_users', 'num')) {
            $map['num'] = 'num';
        }
        $map['name'] = 'name';
        $map['login'] = 'login';
        $map['role'] = 'ID_STATUS';
        $map['enabled'] = 'enabled';
        $map['id'] = 'id';
        return $map;
    }

    /**
     * @param array{q?:string,enabled?:string|null,limit?:int,offset?:int,order?:string} $opts
     * @return array{items:list<array>, total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_users')) {
            return ['items' => [], 'total' => 0];
        }
        $q = trim((string)($opts['q'] ?? ''));
        $enabled = $opts['enabled'] ?? null; // null=all, '1', '0'
        $limit = (int)($opts['limit'] ?? 100);
        if ($limit < 0) {
            $limit = 0;
        } elseif ($limit > 0) {
            $limit = min(3000, max(1, $limit));
        }
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = Database::columnExists('base_users', 'num')
                ? 'num ASC'
                : 'enabled DESC, name ASC';
        }

        $where = ['1=1'];
        $params = [];
        if ($enabled === '1' || $enabled === '0') {
            $where[] = 'enabled = ?';
            $params[] = $enabled;
        }
        if ($q !== '') {
            $where[] = '(login LIKE ? OR name LIKE ? OR mail LIKE ? OR tel LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $statusId = (int)($opts['status_id'] ?? 0);
        if ($statusId > 0 && Database::columnExists('base_users', 'ID_STATUS')) {
            $where[] = 'ID_STATUS = ?';
            $params[] = $statusId;
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_users WHERE $sqlWhere");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $cols = self::selectList([
            'id', 'login', 'name', 'mail', 'enabled', 'ID_STATUS', 'color', 'color_text', 'tel', 'IS_HIDDEN', 'IS_ACCEPTOR', 'num',
        ]);
        if ($cols === '') {
            return ['items' => [], 'total' => $total];
        }
        $sql = "SELECT $cols
             FROM base_users
             WHERE $sqlWhere
             ORDER BY $order";
        if ($limit > 0) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll() ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        if (array_key_exists($id, self::$findCache)) {
            return self::$findCache[$id];
        }
        if (!Database::tableExists('base_users')) {
            return self::$findCache[$id] = null;
        }
        $cols = self::selectList([
            'id', 'login', 'name', 'mail', 'enabled', 'ID_STATUS', 'color', 'color_text', 'tel', 'address', 'url',
            'expire', 'AUDIO_ANALYTICS_ENABLED', 'IS_HIDDEN', 'ID_STATUS2', 'IS_ACCEPTOR', 'num',
        ]);
        if ($cols === '') {
            return self::$findCache[$id] = null;
        }
        $st = Database::pdo()->prepare(
            "SELECT $cols FROM base_users WHERE id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch();
        return self::$findCache[$id] = ($row ?: null);
    }

    /** Full row for staff edit form (not cached with badge find). */
    public static function findForEdit(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_users')) {
            return null;
        }
        $cols = [
            'id', 'login', 'name', 'mail', 'tel', 'enabled', 'ID_STATUS', 'ID_STATUS2',
            'color', 'color_text', 'IS_HIDDEN', 'num',
            'expire', 'retries', 'password',
            'link_ok',
            'photo', 'site_descr',
            'ASSIST_TEAM', 'NOT_IN_TEAM', 'STOCK_REVISION', 'AUDIO_ANALYTICS_ENABLED',
            'skin', 'RESTRICT_TYPES', 'RESTRICT_TYPES_ONLINE', 'RESTRICT_PROCS',
            'favorites', 'showNewsAfter', 'status', 'locale', 'IS_ACCEPTOR',
        ];
        $sqlCols = self::selectList($cols);
        if ($sqlCols === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . $sqlCols . ' FROM base_users WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Warm find cache for many ids in one query (comment trees). */
    public static function preload(array $ids): void
    {
        $need = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !array_key_exists($id, self::$findCache)) {
                $need[$id] = true;
            }
        }
        if (!$need || !Database::tableExists('base_users')) {
            return;
        }
        $cols = self::selectList([
            'id', 'login', 'name', 'mail', 'enabled', 'ID_STATUS', 'color', 'color_text', 'tel', 'address', 'url',
            'expire', 'AUDIO_ANALYTICS_ENABLED', 'IS_HIDDEN', 'ID_STATUS2', 'IS_ACCEPTOR',
        ]);
        if ($cols === '') {
            foreach ($need as $id => $_) {
                self::$findCache[$id] = null;
            }
            return;
        }
        $list = array_keys($need);
        $in = implode(',', array_map('intval', $list));
        $sql = "SELECT $cols FROM base_users WHERE id IN ($in)";
        foreach (Database::pdo()->query($sql)->fetchAll() ?: [] as $row) {
            self::$findCache[(int)$row['id']] = $row;
            unset($need[(int)$row['id']]);
        }
        foreach ($need as $id => $_) {
            self::$findCache[$id] = null;
        }
    }

    /**
     * Build a SELECT column list from existing columns; map legacy oper names when needed.
     *
     * @param list<string> $want
     */
    private static function selectList(array $want): string
    {
        $parts = [];
        $seen = [];
        foreach ($want as $col) {
            if (isset($seen[$col])) {
                continue;
            }
            if (Database::columnExists('base_users', $col)) {
                $parts[] = '`' . $col . '`';
                $seen[$col] = true;
                continue;
            }
            // Vanilla oper: name_enabled / STATUS2 until core migration 006.
            if ($col === 'ID_STATUS' && Database::columnExists('base_users', 'name_enabled')) {
                $parts[] = '`name_enabled` AS `ID_STATUS`';
                $seen[$col] = true;
            } elseif ($col === 'ID_STATUS2' && Database::columnExists('base_users', 'STATUS2')) {
                $parts[] = '`STATUS2` AS `ID_STATUS2`';
                $seen[$col] = true;
            }
        }
        return implode(', ', $parts);
    }

    public static function nameById(int $id): string
    {
        if ($id < 1) {
            return '—';
        }
        $u = self::find($id);
        if (!$u) {
            return '#' . $id;
        }
        $name = trim((string)$u['name']);
        return $name !== '' ? $name : (string)$u['login'];
    }

    /** Initials from ФИО (legacy ShortName). */
    public static function shortName(string $name, int $letters = 1): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $simb = mb_substr($part, 0, $letters, 'UTF-8');
            $out .= $letters < 2 ? mb_strtoupper($simb, 'UTF-8') : $simb;
        }
        return $out;
    }

    /** Comma-separated acronyms for user ids (ОЛ / куратор в статусных комментах). */
    public static function acronymsList(array $ids): string
    {
        $parts = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            $u = self::find($id);
            if (!$u) {
                $parts[] = '#' . $id;
                continue;
            }
            $name = trim((string)$u['name']);
            if ($name === '') {
                $name = (string)$u['login'];
            }
            $short = self::shortName($name);
            $parts[] = $short !== '' ? $short : ('#' . $id);
        }
        return implode(', ', $parts);
    }

    /** Colored acronym badge HTML (legacy replaceAdminINAPI). */
    public static function badgeHtml(int $id, bool $link = true, bool $brackets = true): string
    {
        if ($id < 1) {
            return '<span class="user-badge is-empty">—</span>';
        }
        $u = self::find($id);
        if (!$u) {
            return '<span class="user-badge is-empty">[' . $id . ']</span>';
        }
        $name = trim((string)$u['name']);
        if ($name === '') {
            $name = (string)$u['login'];
        }
        $short = self::shortName($name);
        $bg = trim((string)($u['color'] ?? ''));
        if ($bg === '') {
            $bg = '#64748b';
        }
        $fg = trim((string)($u['color_text'] ?? ''));
        if ($fg === '') {
            $fg = '#000000';
        }
        $label = $brackets ? '[' . $short . ']' : $short;
        $style = 'background-color:' . e($bg) . ';color:' . e($fg);
        $inner = e($label);
        if ($link) {
            return '<a class="user-badge" style="' . $style . '" href="/users/' . $id . '" title="' . e($name) . '">' . $inner . '</a>';
        }
        return '<span class="user-badge" style="' . $style . '" title="' . e($name) . '">' . $inner . '</span>';
    }

    /** First name + patronymic for reply prefix (legacy). */
    public static function replyAddress(int $id): string
    {
        $name = self::nameById($id);
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($parts) >= 3) {
            return $parts[1] . ' ' . $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[1];
        }
        return $parts[0] ?? '';
    }

    /** @return list<int> */
    public static function favoriteIds(int $userId): array
    {
        if ($userId < 1 || !Database::tableExists('base_users')) {
            return [];
        }
        $st = Database::pdo()->prepare('SELECT favorites FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $raw = (string)$st->fetchColumn();
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $p) {
            $n = (int)trim($p);
            if ($n > 0) {
                $ids[] = $n;
            }
        }
        return $ids;
    }

    public static function setFavorites(int $userId, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $st = Database::pdo()->prepare('UPDATE base_users SET favorites = ? WHERE id = ?');
        $st->execute([implode(',', $ids), $userId]);
    }

    public static function toggleFavorite(int $userId, int $newsId): bool
    {
        $fav = self::favoriteIds($userId);
        $set = array_flip($fav);
        if (isset($set[$newsId])) {
            unset($set[$newsId]);
            self::setFavorites($userId, array_keys($set));
            return false;
        }
        $set[$newsId] = true;
        self::setFavorites($userId, array_keys($set));
        return true;
    }

    /** Whether status ID has SUPERADMIN_ACCESS (oper-protected staff). */
    public static function statusIsSuperadmin(int $statusId): bool
    {
        if ($statusId < 1 || !Database::tableExists('base_admin_statuses')) {
            return false;
        }
        if (!Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT SUPERADMIN_ACCESS FROM base_admin_statuses WHERE ID = ? LIMIT 1'
        );
        $st->execute([$statusId]);
        return (int)$st->fetchColumn() > 0;
    }

    /**
     * Whether the current actor may toggle enabled for this staff row.
     * Blocks self and SUPERADMIN_ACCESS targets for non-super actors (as in oper).
     *
     * @param array<string,mixed>|null $user row with id / ID_STATUS when already loaded
     */
    public static function canToggleEnabled(int $id, ?array $user = null): bool
    {
        if ($id < 1 || $id === Auth::userId()) {
            return false;
        }
        if ($user === null) {
            $user = self::find($id);
        }
        if (!$user) {
            return false;
        }
        if (self::statusIsSuperadmin((int)($user['ID_STATUS'] ?? 0))
            && !Auth::capability('SUPERADMIN_ACCESS')
        ) {
            return false;
        }
        return true;
    }

    /** Enable/disable staff account. Returns false when blocked or missing. */
    public static function setEnabled(int $id, bool $enabled): bool
    {
        if (!Database::tableExists('base_users')) {
            return false;
        }
        $user = self::find($id);
        if (!$user || !self::canToggleEnabled($id, $user)) {
            return false;
        }
        $st = Database::pdo()->prepare('UPDATE base_users SET enabled = ? WHERE id = ?');
        $st->execute([$enabled ? '1' : '0', $id]);
        unset(self::$findCache[$id]);
        return true;
    }

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        $allowed = [
            'name', 'mail', 'tel', 'enabled', 'ID_STATUS', 'ID_STATUS2',
            'color', 'color_text', 'IS_HIDDEN', 'num',
            'expire', 'retries',
            'link_ok',
            'photo', 'site_descr',
            'ASSIST_TEAM', 'NOT_IN_TEAM', 'STOCK_REVISION', 'AUDIO_ANALYTICS_ENABLED',
            'skin', 'RESTRICT_TYPES', 'RESTRICT_TYPES_ONLINE', 'RESTRICT_PROCS',
            'favorites', 'showNewsAfter',
            'IS_ACCEPTOR',
            'locale',
        ];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            if (!Database::columnExists('base_users', $col)) {
                continue;
            }
            $fields[] = "`$col` = ?";
            $params[] = $data[$col];
        }
        if (!empty($data['password_plain'])) {
            $fields[] = '`password` = ?';
            $params[] = Auth::hashPassword((string)$data['password_plain']);
        }
        if (array_key_exists('status_blob', $data) && Database::columnExists('base_users', 'status')) {
            $fields[] = '`status` = ?';
            $params[] = (string)$data['status_blob'];
        }
        if (!$fields) {
            return;
        }
        $params[] = $id;
        $sql = 'UPDATE base_users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        Database::pdo()->prepare($sql)->execute($params);
        unset(self::$findCache[$id]);
    }

    public static function countUsers(): int
    {
        if (!Database::tableExists('base_users')) {
            return 0;
        }
        return (int)Database::pdo()->query('SELECT COUNT(*) FROM base_users')->fetchColumn();
    }

    public static function findByLogin(string $login): ?array
    {
        if (!Database::tableExists('base_users')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_users WHERE login = ? LIMIT 1');
        $st->execute([trim($login)]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Create a staff user (greenfield bootstrap / admin tooling).
     *
     * @param array{
     *   login:string,
     *   password_plain:string,
     *   name?:string,
     *   mail?:string,
     *   enabled?:string,
     *   ID_STATUS?:int,
     *   status_blob?:string,
     *   locale?:string
     * } $data
     */
    public static function create(array $data): int
    {
        if (!Database::tableExists('base_users')) {
            throw new RuntimeException('base_users missing — run schema migrate first');
        }
        $login = trim((string)($data['login'] ?? ''));
        $password = (string)($data['password_plain'] ?? '');
        if ($login === '' || $password === '') {
            throw new InvalidArgumentException('login and password_plain required');
        }
        if (self::findByLogin($login) !== null) {
            throw new RuntimeException('login already exists: ' . $login);
        }

        $cols = [];
        $vals = [];
        $params = [];
        $add = static function (string $col, $value) use (&$cols, &$vals, &$params): void {
            if (!Database::columnExists('base_users', $col)) {
                return;
            }
            $cols[] = '`' . $col . '`';
            $vals[] = '?';
            $params[] = $value;
        };

        $add('login', $login);
        $add('password', Auth::hashPassword($password));
        $add('name', trim((string)($data['name'] ?? $login)));
        $add('mail', trim((string)($data['mail'] ?? '')));
        $add('tel', trim((string)($data['tel'] ?? '')));
        $add('enabled', (string)($data['enabled'] ?? '1'));
        $add('ID_STATUS', (int)($data['ID_STATUS'] ?? 0));
        if (isset($data['ID_STATUS2'])) {
            $add('ID_STATUS2', (int)$data['ID_STATUS2']);
        }
        $add('content', (string)($data['content'] ?? ''));
        $add('status', (string)($data['status_blob'] ?? serialize(['permissions' => []])));
        $add('skin', (string)($data['skin'] ?? ''));
        $add('skin_enabled', '0');
        $add('color', trim((string)($data['color'] ?? '')));
        $add('color_text', trim((string)($data['color_text'] ?? '')));
        $add('url', '');
        $add('retries', 0);
        $add('expire', 0);
        if (isset($data['num'])) {
            $add('num', max(0, (int)$data['num']));
        }
        if (isset($data['ASSIST_TEAM'])) {
            $add('ASSIST_TEAM', !empty($data['ASSIST_TEAM']) ? 1 : 0);
        }
        if (isset($data['NOT_IN_TEAM'])) {
            $add('NOT_IN_TEAM', !empty($data['NOT_IN_TEAM']) ? 1 : 0);
        }
        if (isset($data['IS_HIDDEN'])) {
            $add('IS_HIDDEN', !empty($data['IS_HIDDEN']) ? 1 : 0);
        }
        if (!empty($data['locale']) && Database::columnExists('base_users', 'locale')) {
            $add('locale', (string)$data['locale']);
        }

        if ($cols === []) {
            throw new RuntimeException('no writable columns on base_users');
        }
        $sql = 'INSERT INTO base_users (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        Database::pdo()->prepare($sql)->execute($params);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * Agent-safe user row (password omitted).
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public static function forAgent(?array $row, bool $withPermissions = false): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = $row;
        unset($out['password'], $out['PASSWORD']);
        if (array_key_exists('status', $out) || array_key_exists('STATUS', $out)) {
            $raw = $out['status'] ?? $out['STATUS'] ?? null;
            if ($withPermissions) {
                $out['permissions'] = self::decodePermissions($raw);
            }
            unset($out['status'], $out['STATUS']);
        }
        $out['id'] = (int)($out['id'] ?? $out['ID'] ?? 0);
        $out['status_id'] = (int)($out['ID_STATUS'] ?? $out['status_id'] ?? 0);
        $out['status2_id'] = (int)($out['ID_STATUS2'] ?? $out['status2_id'] ?? 0);
        $names = self::statusNames();
        $out['status_name'] = $names[$out['status_id']] ?? '';
        $out['status2_name'] = $out['status2_id'] > 0 ? ($names[$out['status2_id']] ?? '') : '';
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function listForAgent(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            $item = self::forAgent($row, false);
            if ($item !== null) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
