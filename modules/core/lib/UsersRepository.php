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

    /** @return array<int,string> id => label */
    public static function procedureTypes(): array
    {
        if (!Database::tableExists('base_procedure_types')) {
            return [];
        }
        $out = [];
        $rows = Database::pdo()->query(
            'SELECT ID, TYPE, DESCR FROM base_procedure_types ORDER BY TYPE'
        )->fetchAll() ?: [];
        foreach ($rows as $r) {
            $label = trim((string)$r['TYPE']);
            $descr = trim((string)($r['DESCR'] ?? ''));
            if ($descr !== '') {
                $label .= ' — ' . $descr;
            }
            $out[(int)$r['ID']] = $label;
        }
        return $out;
    }

    /**
     * Products for RESTRICT_PROCS — always base_products (never phpshop_*).
     * Empty table → empty list.
     * @return array<int,string>
     */
    public static function productsForRestrict(): array
    {
        if (!Database::tableExists('base_products')) {
            return [];
        }
        $out = [];
        $where = '1=1';
        if (Database::columnExists('base_products', 'enabled')) {
            $where .= ' AND enabled = 1';
        }
        if (Database::columnExists('base_products', 'is_material')) {
            $where .= ' AND NOT is_material';
        } elseif (Database::columnExists('base_products', 'IS_MATERIAL')) {
            $where .= ' AND NOT IS_MATERIAL';
        }
        $order = Database::columnExists('base_products', 'name') ? 'name' : 'id';
        if (Database::columnExists('base_products', 'category')) {
            $order = 'category, ' . $order;
        }
        $idCol = Database::columnExists('base_products', 'id') ? 'id' : 'ID';
        $nameCol = Database::columnExists('base_products', 'name') ? 'name' : $idCol;
        $sql = "SELECT `$idCol` AS id, `$nameCol` AS name FROM base_products WHERE $where ORDER BY $order LIMIT 2000";
        foreach (Database::pdo()->query($sql)->fetchAll() ?: [] as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    }

    /** @return array<int,string> id => label */
    public static function cabinets(): array
    {
        if (!Database::tableExists('base_cabinets')) {
            return [];
        }
        $out = [];
        $rows = Database::pdo()->query(
            'SELECT ID, NAME, DESCR FROM base_cabinets ORDER BY ID'
        )->fetchAll() ?: [];
        foreach ($rows as $r) {
            $label = trim((string)$r['NAME']);
            $descr = trim((string)($r['DESCR'] ?? ''));
            if ($descr !== '') {
                $label .= ' — ' . $descr;
            }
            $out[(int)$r['ID']] = $label !== '' ? $label : ('#' . (int)$r['ID']);
        }
        return $out;
    }

    /**
     * Which staff-edit sections belong to enabled product modules.
     * Core always owns profile/password/colors/news/permissions; module UIs stay hidden until modules exist.
     *
     * @return array{
     *   procedure_types:bool,
     *   procedure_restrict:bool,
     *   cabinets:bool,
     *   acceptor:bool,
     *   audio:bool,
     *   stocks:bool,
     *   employee_bot:bool,
     *   site_profile:bool
     * }
     */
    public static function editFeatures(): array
    {
        $mod = static function (string ...$ids): bool {
            foreach ($ids as $id) {
                if (ModuleManager::isEnabled($id)) {
                    return true;
                }
            }
            return false;
        };
        return [
            // types from base_procedure_types
            'procedure_types' => $mod('procedures', 'likespa-procedures'),
            // RESTRICT_PROCS from base_products (catalog); empty table → empty UI
            'procedure_restrict' => $mod('catalog'),
            'cabinets' => $mod('cabinets'),
            'acceptor' => $mod('contracts'),
            'audio' => $mod('audio'),
            'stocks' => $mod('likespa-stocks', 'stocks'),
            'employee_bot' => $mod('employee-bot', 'likespa-employee-bot'),
            'site_profile' => $mod('likespa-promopages', 'likespa-site'),
        ];
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
        return [
            'name' => 'name',
            'login' => 'login',
            'role' => 'name_enabled',
            'enabled' => 'enabled',
            'id' => 'id',
        ];
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
            $order = 'enabled DESC, name ASC';
        }

        $where = ['1=1'];
        $params = [];
        if ($enabled === '1' || $enabled === '0') {
            $where[] = 'enabled = ?';
            $params[] = $enabled;
        }
        if ($q !== '') {
            $where[] = '(login LIKE ? OR name LIKE ? OR mail LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_users WHERE $sqlWhere");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $sql = "SELECT id, login, name, mail, enabled, name_enabled, color, color_text, tel, IS_HIDDEN"
            . (Database::columnExists('base_users', 'IS_ACCEPTOR') ? ', IS_ACCEPTOR' : '')
            . "
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
        $st = Database::pdo()->prepare(
            'SELECT id, login, name, mail, enabled, name_enabled, color, color_text, tel, address, url,
                    expire, telegram_chat_id, max_user_id, AUDIO_ANALYTICS_ENABLED, IS_HIDDEN, STATUS2'
            . (Database::columnExists('base_users', 'IS_ACCEPTOR') ? ', IS_ACCEPTOR' : '')
            . ' FROM base_users WHERE id = ? LIMIT 1'
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
            'id', 'login', 'name', 'mail', 'tel', 'enabled', 'name_enabled', 'STATUS2',
            'color', 'color_text', 'IS_HIDDEN',
            'expire', 'retries', 'password',
            'link_ok',
            'telegram_chat_id', 'max_user_id', 'BOT_NO_SEND',
            'photo', 'site_descr',
            'ASSIST_TEAM', 'NOT_IN_TEAM', 'STOCK_REVISION', 'AUDIO_ANALYTICS_ENABLED',
            'skin', 'RESTRICT_TYPES', 'RESTRICT_TYPES_ONLINE', 'RESTRICT_PROCS',
            'favorites', 'showNewsAfter', 'status', 'locale',
        ];
        if (Database::columnExists('base_users', 'IS_ACCEPTOR')) {
            $cols[] = 'IS_ACCEPTOR';
        }
        $safe = [];
        foreach ($cols as $c) {
            if (Database::columnExists('base_users', $c)) {
                $safe[] = '`' . $c . '`';
            }
        }
        if (!$safe) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . implode(', ', $safe) . ' FROM base_users WHERE id = ? LIMIT 1'
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
        $list = array_keys($need);
        $in = implode(',', array_map('intval', $list));
        $sql = 'SELECT id, login, name, mail, enabled, name_enabled, color, color_text, tel, address, url,
                       expire, telegram_chat_id, max_user_id, AUDIO_ANALYTICS_ENABLED, IS_HIDDEN, STATUS2'
            . (Database::columnExists('base_users', 'IS_ACCEPTOR') ? ', IS_ACCEPTOR' : '')
            . " FROM base_users WHERE id IN ($in)";
        foreach (Database::pdo()->query($sql)->fetchAll() ?: [] as $row) {
            self::$findCache[(int)$row['id']] = $row;
            unset($need[(int)$row['id']]);
        }
        foreach ($need as $id => $_) {
            self::$findCache[$id] = null;
        }
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

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        $allowed = [
            'name', 'mail', 'tel', 'enabled', 'name_enabled', 'STATUS2',
            'color', 'color_text', 'IS_HIDDEN',
            'expire', 'retries',
            'link_ok',
            'telegram_chat_id', 'max_user_id', 'BOT_NO_SEND',
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
}
