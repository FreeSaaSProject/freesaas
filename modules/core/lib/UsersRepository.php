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
     * Non-super actors never see SUPERADMIN_ACCESS roles in pickers.
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
        if (!Auth::capability('SUPERADMIN_ACCESS')
            && Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS')
        ) {
            $sql .= ' AND (SUPERADMIN_ACCESS IS NULL OR SUPERADMIN_ACCESS = 0)';
        }
        $sql .= ' ORDER BY ID';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll() ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /**
     * Defense in depth for create/update: block superadmin escalation and
     * privileged field writes for non-SUPERADMIN_ACCESS actors.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $targetUser existing row (null when creating)
     */
    public static function assertCanWritePrivileged(array $data, ?array $targetUser = null): void
    {
        // CLI / virgin bootstrap / agent break-glass have no staff session.
        if (!Auth::check() || Auth::capability('SUPERADMIN_ACCESS')) {
            return;
        }
        $creating = $targetUser === null;
        $targetId = $creating ? 0 : (int)($targetUser['id'] ?? 0);

        if (!$creating) {
            if (self::statusIsSuperadmin((int)($targetUser['ID_STATUS'] ?? 0))
                || self::statusIsSuperadmin((int)($targetUser['ID_STATUS2'] ?? 0))
            ) {
                throw new RuntimeException(t('core.user_edit.err.forbidden_super'));
            }
        }

        foreach (['ID_STATUS', 'ID_STATUS2'] as $k) {
            if (array_key_exists($k, $data) && self::statusIsSuperadmin((int)$data[$k])) {
                throw new InvalidArgumentException(t('core.user_edit.err.forbidden_super'));
            }
        }

        // Self-escalation: cannot change own role / permission blob / enabled.
        if (!$creating && $targetId > 0 && $targetId === Auth::userId()) {
            foreach (['ID_STATUS', 'ID_STATUS2', 'status_blob', 'enabled'] as $k) {
                if (array_key_exists($k, $data)) {
                    throw new RuntimeException(t('core.user_edit.err.forbidden_self_priv'));
                }
            }
        }

        if (!$creating && array_key_exists('enabled', $data) && !self::canToggleEnabled($targetId, $targetUser)) {
            throw new RuntimeException(t('core.user_edit.err.forbidden_enabled'));
        }
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

    /**
     * Enabled staff for UI pickers/filters (includes SUPERADMIN_ACCESS roles).
     *
     * @return list<array{id:int,login:string,name:string,status_id:int,status_name:string}>
     */
    public static function listEnabledStaff(): array
    {
        return self::listStaffRows(false);
    }

    /**
     * Staff rows usable as permission/role templates (copyFrom).
     * Excludes SUPERADMIN_ACCESS roles so agents cannot clone elevated accounts.
     *
     * @return list<array{id:int,login:string,name:string,status_id:int,status_name:string}>
     */
    public static function listForCopy(bool $excludeSuperadmin = true): array
    {
        return self::listStaffRows($excludeSuperadmin);
    }

    /**
     * @return list<array{id:int,login:string,name:string,status_id:int,status_name:string}>
     */
    private static function listStaffRows(bool $excludeSuperadmin): array
    {
        if (!Database::tableExists('base_users')) {
            return [];
        }
        $cols = 'id, login, name';
        if (Database::columnExists('base_users', 'ID_STATUS')) {
            $cols .= ', ID_STATUS';
        } elseif (Database::columnExists('base_users', 'name_enabled')) {
            $cols .= ', name_enabled AS ID_STATUS';
        }
        $rows = Database::pdo()->query(
            "SELECT {$cols} FROM base_users WHERE enabled = '1' ORDER BY name ASC, login ASC LIMIT 500"
        )->fetchAll() ?: [];
        $names = self::statusNames();
        $out = [];
        foreach ($rows as $r) {
            $statusId = (int)($r['ID_STATUS'] ?? 0);
            if ($excludeSuperadmin && self::statusIsSuperadmin($statusId)) {
                continue;
            }
            $out[] = [
                'id' => (int)$r['id'],
                'login' => (string)$r['login'],
                'name' => (string)$r['name'],
                'status_id' => $statusId,
                'status_name' => $names[$statusId] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Enabled staff names for selects; always include $extraIds (e.g. disabled linked user).
     * Includes SUPERADMIN_ACCESS roles (unlike listForCopy / copyFrom templates).
     *
     * @param list<int> $extraIds
     * @return array<int,string> id => label
     */
    public static function staffNamesIncluding(array $extraIds = []): array
    {
        $out = [];
        foreach (self::listEnabledStaff() as $u) {
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
     * Staff picker including disabled (rates / skill UI). Labels mark disabled.
     *
     * @return array<int,string> id => label
     */
    public static function staffNamesAll(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $listed = self::list(['enabled' => null, 'limit' => $limit, 'order' => 'name ASC, login ASC']);
        $out = [];
        foreach ($listed['items'] as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $label = trim((string)($u['name'] ?? '')) !== ''
                ? (string)$u['name']
                : (string)($u['login'] ?? ('#' . $id));
            $enabled = (string)($u['enabled'] ?? '1') === '1' || (int)($u['enabled'] ?? 1) === 1;
            if (!$enabled) {
                $label .= ' (' . t('core.users.filter_disabled') . ')';
            }
            $out[$id] = $label;
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
                        // Keep i18n keys; views translate once via t().
                        'label' => $label !== '' ? $label : $pid,
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
                $grouped[$id] = [
                    'name' => (string)($meta['name'] ?? $id),
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

        // Uppercase 2–5 letters (МСА, ДВМ): match ФИО initials — LIKE on name never finds them.
        $acronymNeedle = self::acronymQueryNeedle($q);

        $where = ['1=1'];
        $params = [];
        if ($enabled === '1' || $enabled === '0') {
            $where[] = 'enabled = ?';
            $params[] = $enabled;
        }
        if ($q !== '' && $acronymNeedle === '') {
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

        $cols = self::selectList([
            'id', 'login', 'name', 'mail', 'enabled', 'ID_STATUS', 'color', 'color_text', 'tel', 'IS_HIDDEN', 'IS_ACCEPTOR', 'num',
        ]);
        if ($cols === '') {
            return ['items' => [], 'total' => 0];
        }

        if ($acronymNeedle !== '') {
            $sql = "SELECT $cols
                 FROM base_users
                 WHERE $sqlWhere
                 ORDER BY $order";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $all = $st->fetchAll() ?: [];
            $items = [];
            foreach ($all as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    $name = (string)($row['login'] ?? '');
                }
                if (self::shortName($name) !== $acronymNeedle) {
                    continue;
                }
                $items[] = $row;
            }
            $total = count($items);
            if ($limit > 0) {
                $items = array_slice($items, $offset, $limit);
            } elseif ($offset > 0) {
                $items = array_slice($items, $offset);
            }
            return ['items' => $items, 'total' => $total];
        }

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_users WHERE $sqlWhere");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

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

    /**
     * Staff МСА/ДВМ-style query: uppercase Cyrillic/Latin letters only, length 2–5.
     * Empty string = not an acronym search (use normal LIKE).
     */
    public static function acronymQueryNeedle(string $q): string
    {
        $q = trim($q);
        if ($q === '' || !preg_match('/^[\p{L}]{2,5}$/u', $q)) {
            return '';
        }
        $upper = function_exists('mb_strtoupper')
            ? mb_strtoupper($q, 'UTF-8')
            : strtoupper($q);
        return $upper === $q ? $upper : '';
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
        if ($userId < 1 || !Database::tableExists('base_users') || !Database::columnExists('base_users', 'favorites')) {
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
        if ($userId < 1 || !Database::columnExists('base_users', 'favorites')) {
            return;
        }
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
        Auth::bumpAuthEpoch($id);
        return true;
    }

    public static function update(int $id, array $data): void
    {
        $before = self::find($id);
        if ($before === null) {
            throw new RuntimeException(t('core.user.err.not_found', ['id' => $id]));
        }
        self::assertCanWritePrivileged($data, $before);
        $fields = [];
        $params = [];
        $allowed = [
            'login',
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
        $passwordChanged = false;
        if (!empty($data['password_plain'])) {
            $plain = (string)$data['password_plain'];
            $statusId = array_key_exists('ID_STATUS', $data)
                ? (int)$data['ID_STATUS']
                : (int)((self::find($id)['ID_STATUS'] ?? 0));
            $status2Id = array_key_exists('ID_STATUS2', $data)
                ? (int)$data['ID_STATUS2']
                : (int)((self::find($id)['ID_STATUS2'] ?? 0));
            self::assertPasswordPolicy($plain, $statusId, $status2Id);
            $fields[] = '`password` = ?';
            $params[] = Auth::hashPassword($plain);
            $passwordChanged = true;
            if (Database::columnExists('base_users', 'locked_until')) {
                $fields[] = '`locked_until` = NULL';
            }
            if (Database::columnExists('base_users', 'last_failed_at')) {
                $fields[] = '`last_failed_at` = NULL';
            }
            if (!array_key_exists('retries', $data) && Database::columnExists('base_users', 'retries')) {
                $fields[] = '`retries` = ?';
                $params[] = 0;
            }
        }
        $statusBlobChanged = false;
        if (array_key_exists('status_blob', $data) && Database::columnExists('base_users', 'status')) {
            $fields[] = '`status` = ?';
            $params[] = (string)$data['status_blob'];
            $statusBlobChanged = true;
        }
        if (!$fields) {
            return;
        }
        $params[] = $id;
        $sql = 'UPDATE base_users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        Database::pdo()->prepare($sql)->execute($params);
        unset(self::$findCache[$id]);
        if ($passwordChanged && class_exists('LoginLinksRepository', false)) {
            LoginLinksRepository::invalidateForUser($id);
        }

        $bump = $passwordChanged || $statusBlobChanged;
        if (!$bump && is_array($before)) {
            foreach (['enabled', 'ID_STATUS', 'ID_STATUS2', 'expire'] as $k) {
                if (!array_key_exists($k, $data)) {
                    continue;
                }
                if ((string)($before[$k] ?? '') !== (string)$data[$k]) {
                    $bump = true;
                    break;
                }
            }
        }
        if ($bump) {
            Auth::bumpAuthEpoch($id);
        }
    }

    /** Rehash-on-login / CLI path — no policy check (already verified password). */
    public static function updatePasswordHash(int $id, string $hash): void
    {
        if ($id < 1 || $hash === '' || !Database::tableExists('base_users')) {
            return;
        }
        Database::pdo()->prepare('UPDATE base_users SET password = ? WHERE id = ?')
            ->execute([$hash, $id]);
        unset(self::$findCache[$id]);
    }

    public static function assertPasswordPolicy(string $plain, int $statusId = 0, int $status2Id = 0): void
    {
        $strong = self::statusIsSuperadmin($statusId) || self::statusIsSuperadmin($status2Id);
        $err = Auth::passwordPolicyError($plain, $strong);
        if ($err !== null) {
            throw new InvalidArgumentException(t('core.password.policy.' . $err));
        }
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

    /** True when another user (not $exceptId) already owns this login. */
    public static function loginTaken(string $login, int $exceptId = 0): bool
    {
        $row = self::findByLogin($login);
        if ($row === null) {
            return false;
        }
        return (int)($row['id'] ?? 0) !== $exceptId;
    }

    /**
     * Login base from raw input.
     * Values with letters keep a slug (e.g. admin1); phone-like / digit-only inputs
     * collapse to digits (national 10 when 7/8 + 11 digits).
     */
    public static function normalizeLoginCandidate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/[A-Za-z]/', $raw)) {
            $slug = strtolower($raw);
            $slug = preg_replace('/[^a-z0-9._-]+/', '_', $slug) ?? '';
            return trim($slug, '._-');
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) >= 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, -10);
        }
        return $digits;
    }

    /**
     * Unique login: prefer explicit login, else tel digits, else staff_<hex>.
     */
    public static function allocateLogin(string $preferred = '', string $tel = ''): string
    {
        $candidates = [];
        $fromPref = self::normalizeLoginCandidate($preferred);
        if ($fromPref !== '') {
            $candidates[] = $fromPref;
        }
        $fromTel = self::normalizeLoginCandidate($tel);
        if ($fromTel !== '' && $fromTel !== $fromPref) {
            $candidates[] = $fromTel;
        }
        if ($candidates === []) {
            $candidates[] = 'staff_' . bin2hex(random_bytes(4));
        }
        foreach ($candidates as $base) {
            if (self::findByLogin($base) === null) {
                return $base;
            }
            for ($i = 2; $i <= 99; $i++) {
                $try = $base . '_' . $i;
                if (self::findByLogin($try) === null) {
                    return $try;
                }
            }
        }
        return 'staff_' . bin2hex(random_bytes(8));
    }

    /** Cryptographically random password (never return to agents / LLM). */
    public static function generateSecretPassword(int $bytes = 18): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(12, $bytes))), '+/', '-_'), '=');
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
        self::assertCanWritePrivileged($data, null);
        self::assertPasswordPolicy(
            $password,
            (int)($data['ID_STATUS'] ?? 0),
            (int)($data['ID_STATUS2'] ?? 0)
        );

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
        // Strict schemas: bday (unix/int) or birthday date NOT NULL without DEFAULT.
        if (Database::columnExists('base_users', 'bday') && !array_key_exists('bday', $data)) {
            $add('bday', 0);
        }
        if (Database::columnExists('base_users', 'birthday') && !array_key_exists('birthday', $data)) {
            $add('birthday', '0000-00-00');
        }
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
        // Procedure restrict + likespa profile columns: always set when present
        // (strict SQL / TEXT NOT NULL without DEFAULT — greenfield MariaDB/MySQL).
        foreach (
            [
                'RESTRICT_TYPES',
                'RESTRICT_TYPES_ONLINE',
                'RESTRICT_PROCS',
                'photo',
                'site_descr',
                'link_ok',
            ] as $optionalCol
        ) {
            if (array_key_exists($optionalCol, $data)) {
                $add($optionalCol, (string)$data[$optionalCol]);
            } else {
                $add($optionalCol, '');
            }
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
        $display = trim((string)($out['name'] ?? ''));
        if ($display === '') {
            $display = trim((string)($out['login'] ?? ''));
        }
        $out['acronym'] = $display !== '' ? self::shortName($display) : '';
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

    /**
     * Count references that block hard-delete. Empty string = safe to delete.
     *
     * @return list<string>
     */
    public static function deleteBlockReasons(int $id): array
    {
        $reasons = [];
        if ($id < 1) {
            return [t('core.entity.err.not_found')];
        }
        if ($id === Auth::userId()) {
            $reasons[] = t('core.users.err.delete_self');
        }
        if (self::isLastSuperadmin($id)) {
            $reasons[] = t('core.users.err.delete_last_super');
        }
        $probes = [
            ['base_procedures', 'ID_ADMIN', 'core.users.err.in_use_procedures'],
            ['base_procedures', 'ID_OPERATOR', 'core.users.err.in_use_procedures'],
            ['base_events', 'ID_OPERATOR', 'core.users.err.in_use_events'],
            ['base_workweeks', 'ID_USER', 'core.users.err.in_use_workweeks'],
            ['base_payments', 'ID_USER', 'core.users.err.in_use_payments'],
            ['base_news', 'ID_AUTHOR', 'core.users.err.in_use_news'],
            ['base_records', 'ID_USER', 'core.users.err.in_use_audio'],
        ];
        $pdo = Database::pdo();
        foreach ($probes as [$table, $col, $key]) {
            if (!Database::tableExists($table) || !Database::columnExists($table, $col)) {
                continue;
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $col . '` = ?');
            $st->execute([$id]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) {
                $reasons[] = t($key, ['n' => $n]);
            }
        }
        return array_values(array_unique($reasons));
    }

    public static function isLastSuperadmin(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $row = self::find($userId);
        if ($row === null) {
            return false;
        }
        $isSuper = self::statusIsSuperadmin((int)($row['ID_STATUS'] ?? 0))
            || self::statusIsSuperadmin((int)($row['ID_STATUS2'] ?? 0));
        if (!$isSuper) {
            return false;
        }
        if (!Database::tableExists('base_users') || !Database::tableExists('base_admin_statuses')) {
            return false;
        }
        $sql = 'SELECT COUNT(*) FROM base_users u
            LEFT JOIN base_admin_statuses s1 ON s1.ID = u.ID_STATUS
            LEFT JOIN base_admin_statuses s2 ON s2.ID = u.ID_STATUS2
            WHERE u.ID <> ? AND u.ENABLED > 0
              AND (IFNULL(s1.SUPERADMIN_ACCESS,0) > 0 OR IFNULL(s2.SUPERADMIN_ACCESS,0) > 0)';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$userId]);
        return (int)$st->fetchColumn() < 1;
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function deleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        $pdo = Database::pdo();
        foreach ($ids as $raw) {
            $id = (int)$raw;
            if ($id < 1) {
                continue;
            }
            $reasons = self::deleteBlockReasons($id);
            if ($reasons !== []) {
                $skipped[] = ['id' => $id, 'reason' => implode('; ', $reasons)];
                continue;
            }
            if (!Database::tableExists('base_users')) {
                continue;
            }
            $pdo->prepare('DELETE FROM base_users WHERE ID = ?')->execute([$id]);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }
}
