<?php
declare(strict_types=1);

/**
 * Site article tree (oper show_pages → base_page_categories).
 * Pseudo parents: 0 = root, 3000 = "Главная" (homepage section).
 */
final class PageCategoriesRepository
{
    public const PARENT_ROOT = 0;
    public const PARENT_HOME = 3000;

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'id',
            'name' => 'name',
            'num' => 'num',
            'link' => 'link',
            'enabled' => 'enabled',
            'parent' => 'parent_to',
        ];
    }

    /**
     * Flat list (search mode). Prefer listTree() for oper-style hierarchy.
     *
     * @param array{q?:string,enabled?:string,parent?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_page_categories')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(name LIKE ? OR link LIKE ? OR CAST(id AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $en = (string)($opts['enabled'] ?? '');
        if ($en === '1' || $en === '0') {
            $where[] = 'enabled = ?';
            $params[] = (int)$en;
        }
        if (array_key_exists('parent', $opts) && $opts['parent'] !== '' && $opts['parent'] !== null) {
            $where[] = 'parent_to = ?';
            $params[] = (int)$opts['parent'];
        }
        $hasHostCol = Database::columnExists('base_page_categories', 'site_host_id');
        if ($hasHostCol && array_key_exists('site_host_id', $opts) && $opts['site_host_id'] !== null && $opts['site_host_id'] !== '') {
            $hid = (int)$opts['site_host_id'];
            if ($hid === 0) {
                $where[] = 'site_host_id = 0';
            } elseif ($hid > 0) {
                $where[] = '(site_host_id = 0 OR site_host_id = ?)';
                $params[] = $hid;
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_page_categories WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'parent_to ASC, num ASC, name ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $hostSelect = $hasHostCol ? ', site_host_id' : '';
        $sql = "SELECT id, name, num, parent_to, link, linkRW AS link_rw, enabled, datas, BG_URL AS bg_url,
                       (content != '' AND content IS NOT NULL) AS has_content{$hostSelect}
                FROM base_page_categories WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $items[] = self::normalizeListRow($r, 0);
        }
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Oper show_pages tree: roots = parent_to IN (0, 3000), children indented under each root.
     *
     * @param array{q?:string,enabled?:string,site_host_id?:int|string|null,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int,tree:bool}
     */
    public static function listTree(array $opts = []): array
    {
        if (!Database::tableExists('base_page_categories')) {
            return ['items' => [], 'total' => 0, 'tree' => true];
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $flat = self::list($opts);
            $flat['tree'] = false;
            return $flat;
        }

        $en = (string)($opts['enabled'] ?? '');
        $pdo = Database::pdo();
        $hasHostCol = Database::columnExists('base_page_categories', 'site_host_id');
        $hostSelect = $hasHostCol ? ', site_host_id' : '';
        $allSql = "SELECT id, name, num, parent_to, link, linkRW AS link_rw, enabled, datas, BG_URL AS bg_url,
                    (content != '' AND content IS NOT NULL) AS has_content{$hostSelect}
             FROM base_page_categories";
        $allParams = [];
        if ($hasHostCol && array_key_exists('site_host_id', $opts) && $opts['site_host_id'] !== null && $opts['site_host_id'] !== '') {
            $hid = (int)$opts['site_host_id'];
            if ($hid === 0) {
                $allSql .= ' WHERE site_host_id = 0';
            } elseif ($hid > 0) {
                $allSql .= ' WHERE (site_host_id = 0 OR site_host_id = ?)';
                $allParams[] = $hid;
            }
        }
        $allSql .= ' ORDER BY num ASC, name ASC, id ASC';
        $stAll = $pdo->prepare($allSql);
        $stAll->execute($allParams);
        $all = $stAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

        /** @var array<int,array<string,mixed>> $byId */
        $byId = [];
        /** @var array<int,list<int>> $childIds */
        $childIds = [];
        foreach ($all as $r) {
            $id = (int)$r['id'];
            $byId[$id] = $r;
            $pid = (int)$r['parent_to'];
            if (!isset($childIds[$pid])) {
                $childIds[$pid] = [];
            }
            $childIds[$pid][] = $id;
        }

        $rootWhere = 'id > 0 AND (parent_to = 0 OR parent_to = 3000)';
        $rootParams = [];
        if ($en === '1' || $en === '0') {
            $rootWhere .= ' AND enabled = ?';
            $rootParams[] = (int)$en;
        }
        if ($hasHostCol && array_key_exists('site_host_id', $opts) && $opts['site_host_id'] !== null && $opts['site_host_id'] !== '') {
            $hid = (int)$opts['site_host_id'];
            if ($hid === 0) {
                $rootWhere .= ' AND site_host_id = 0';
            } elseif ($hid > 0) {
                $rootWhere .= ' AND (site_host_id = 0 OR site_host_id = ?)';
                $rootParams[] = $hid;
            }
        }
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_page_categories WHERE $rootWhere");
        $stc->execute($rootParams);
        $totalRoots = (int)$stc->fetchColumn();

        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT id FROM base_page_categories WHERE $rootWhere ORDER BY id DESC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($rootParams);
        $rootIdList = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $items = [];
        foreach ($rootIdList as $rootId) {
            if (!isset($byId[$rootId])) {
                continue;
            }
            self::appendTreeBranch($items, $byId, $childIds, $rootId, 0, $en);
        }

        return ['items' => $items, 'total' => $totalRoots, 'tree' => true];
    }

    /**
     * @param array<int,list<int>> $childIds
     * @param array<int,array<string,mixed>> $byId
     * @return list<int>
     */
    private static function filteredChildIds(array $childIds, array $byId, int $parentId, string $enabled): array
    {
        $out = [];
        foreach ($childIds[$parentId] ?? [] as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $ena = (int)$row['enabled'];
            if ($enabled === '1' && $ena !== 1) {
                continue;
            }
            if ($enabled === '0' && $ena !== 0) {
                continue;
            }
            $out[] = $id;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $byId
     * @param array<int,list<int>> $childIds
     */
    private static function appendTreeBranch(
        array &$items,
        array $byId,
        array $childIds,
        int $id,
        int $level,
        string $enabled
    ): void {
        $row = $byId[$id] ?? null;
        if ($row === null) {
            return;
        }
        $kids = self::filteredChildIds($childIds, $byId, $id, $enabled);
        $items[] = self::normalizeListRow($row, $level, $kids !== []);
        foreach ($kids as $childId) {
            self::appendTreeBranch($items, $byId, $childIds, $childId, $level + 1, $enabled);
        }
    }

    /** @param array<string,mixed> $r */
    private static function normalizeListRow(array $r, int $level, bool $hasChildren = false): array
    {
        $parentTo = (int)$r['parent_to'];
        return [
            'id' => (int)$r['id'],
            'site_host_id' => (int)($r['site_host_id'] ?? 0),
            'name' => (string)($r['name'] ?? ''),
            'num' => (int)$r['num'],
            'parent_to' => $parentTo,
            'link' => (string)($r['link'] ?? ''),
            'link_rw' => (string)($r['link_rw'] ?? ''),
            'enabled' => (int)$r['enabled'],
            'datas' => (int)$r['datas'],
            'bg_url' => (string)($r['bg_url'] ?? ''),
            'has_content' => !empty($r['has_content']),
            'parent_label' => self::parentLabel($parentTo),
            'level' => $level,
            'has_children' => $hasChildren,
            'is_home' => $parentTo === self::PARENT_HOME,
            'is_folder' => $hasChildren || $parentTo !== self::PARENT_HOME,
        ];
    }

    /** @return array<int,string> id => name for parent select */
    public static function parentOptions(?int $excludeId = null): array
    {
        $out = [
            self::PARENT_ROOT => t('sites.pages.parent.root'),
            self::PARENT_HOME => t('sites.pages.parent.home'),
        ];
        if (!Database::tableExists('base_page_categories')) {
            return $out;
        }
        $sql = 'SELECT id, name, parent_to FROM base_page_categories WHERE enabled = 1';
        $params = [];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY parent_to ASC, num ASC, name ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int)$r['id'];
            $out[$id] = '#' . $id . ' ' . (string)$r['name'];
        }
        return $out;
    }

    public static function parentLabel(int $parentTo): string
    {
        if ($parentTo === self::PARENT_ROOT) {
            return t('sites.pages.parent.root');
        }
        if ($parentTo === self::PARENT_HOME) {
            return t('sites.pages.parent.home');
        }
        $row = self::find($parentTo);
        if ($row) {
            return (string)$row['name'];
        }
        return '#' . $parentTo;
    }

    /**
     * Resolve /page/{seg}/{seg}/… by walking parent_to + link segments.
     *
     * @param list<string> $segments
     * @return array<string,mixed>|null
     */
    public static function findByLinkPath(array $segments, int $siteHostId = 0): ?array
    {
        if ($segments === [] || !Database::tableExists('base_page_categories')) {
            return null;
        }
        $parentIds = [self::PARENT_ROOT, self::PARENT_HOME];
        $found = null;
        foreach ($segments as $i => $seg) {
            $seg = trim((string)$seg);
            if ($seg === '') {
                return null;
            }
            $row = self::findChildByLink($seg, $parentIds, $siteHostId);
            if ($row === null) {
                return null;
            }
            $found = $row;
            $parentIds = [(int)$row['id']];
        }
        return $found;
    }

    /**
     * @param list<int> $parentIds
     * @return array<string,mixed>|null
     */
    private static function findChildByLink(string $link, array $parentIds, int $siteHostId = 0): ?array
    {
        $parentIds = array_values(array_filter(array_map('intval', $parentIds), static function (int $id): bool {
            return $id >= 0;
        }));
        if ($parentIds === []) {
            return null;
        }
        $in = implode(',', $parentIds);
        $params = [$link];
        $hostWhere = '';
        $hostSelect = '';
        if (Database::columnExists('base_page_categories', 'site_host_id')) {
            $hostSelect = ', site_host_id';
            if ($siteHostId > 0) {
                $hostWhere = ' AND (site_host_id = 0 OR site_host_id = ?)';
                $params[] = $siteHostId;
            }
        }
        $st = Database::pdo()->prepare(
            "SELECT id, name, num, parent_to, content, link, linkRW AS link_rw,
                    enabled, datas, HASHTAGS AS hashtags, BG_URL AS bg_url{$hostSelect}
             FROM base_page_categories
             WHERE enabled = 1 AND parent_to IN ($in) AND link = ?{$hostWhere}
             ORDER BY id ASC LIMIT 1"
        );
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['num'] = (int)$row['num'];
        $row['parent_to'] = (int)$row['parent_to'];
        $row['enabled'] = (int)$row['enabled'];
        $row['site_host_id'] = (int)($row['site_host_id'] ?? 0);
        $row['link'] = (string)($row['link'] ?? '');
        $row['link_rw'] = (string)($row['link_rw'] ?? '');
        $row['content'] = (string)($row['content'] ?? '');
        $row['name'] = (string)($row['name'] ?? '');
        return $row;
    }

    /**
     * Public resolve by link or linkRW (with/without leading slash).
     *
     * @return array<string,mixed>|null
     */
    public static function findByLink(string $link, int $siteHostId = 0): ?array
    {
        $link = trim($link);
        if ($link === '' || !Database::tableExists('base_page_categories')) {
            return null;
        }
        $candidates = array_values(array_unique(array_filter([
            $link,
            '/' . ltrim($link, '/'),
            ltrim($link, '/'),
            rtrim($link, '/') . '/',
            '/' . trim($link, '/') . '/',
        ], static function ($v) {
            return $v !== '' && $v !== '/';
        })));
        if ($candidates === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $params = array_merge($candidates, $candidates);
        $hostSelect = '';
        $hostWhere = '';
        if (Database::columnExists('base_page_categories', 'site_host_id')) {
            $hostSelect = ', site_host_id';
            if ($siteHostId > 0) {
                $hostWhere = ' AND (site_host_id = 0 OR site_host_id = ?)';
                $params[] = $siteHostId;
            }
        }
        $st = Database::pdo()->prepare(
            "SELECT id, name, num, parent_to, content, link, linkRW AS link_rw,
                    enabled, datas, HASHTAGS AS hashtags, BG_URL AS bg_url{$hostSelect}
             FROM base_page_categories
             WHERE enabled = 1 AND (link IN ($placeholders) OR linkRW IN ($placeholders)){$hostWhere}
             ORDER BY id ASC LIMIT 1"
        );
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['num'] = (int)$row['num'];
        $row['parent_to'] = (int)$row['parent_to'];
        $row['enabled'] = (int)$row['enabled'];
        $row['site_host_id'] = (int)($row['site_host_id'] ?? 0);
        $row['link'] = (string)($row['link'] ?? '');
        $row['link_rw'] = (string)($row['link_rw'] ?? '');
        $row['content'] = (string)($row['content'] ?? '');
        $row['name'] = (string)($row['name'] ?? '');
        return $row;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_page_categories')) {
            return null;
        }
        $hostSelect = Database::columnExists('base_page_categories', 'site_host_id')
            ? ', site_host_id' : '';
        $st = Database::pdo()->prepare(
            "SELECT id, name, num, parent_to, content, link, linkRW AS link_rw,
                    enabled, datas, HASHTAGS AS hashtags, BG_URL AS bg_url{$hostSelect}
             FROM base_page_categories WHERE id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['num'] = (int)$row['num'];
        $row['parent_to'] = (int)$row['parent_to'];
        $row['enabled'] = (int)$row['enabled'];
        $row['datas'] = (int)$row['datas'];
        $row['site_host_id'] = (int)($row['site_host_id'] ?? 0);
        $row['name'] = (string)($row['name'] ?? '');
        $row['content'] = (string)($row['content'] ?? '');
        $row['link'] = (string)($row['link'] ?? '');
        $row['link_rw'] = (string)($row['link_rw'] ?? '');
        $row['bg_url'] = (string)($row['bg_url'] ?? '');
        $row['hashtags'] = (string)($row['hashtags'] ?? '');
        return $row;
    }

    public static function childCount(int $id): int
    {
        if ($id < 1 || !Database::tableExists('base_page_categories')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_page_categories WHERE parent_to = ?'
        );
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    /**
     * Enabled child articles for storefront catalog pages (e.g. /page/vacancies/).
     *
     * @return list<array{id:int,name:string,link:string,link_rw:string,parent_to:int,href:string,content_excerpt:string}>
     */
    public static function listChildrenForStorefront(int $parentId, int $siteHostId = 0): array
    {
        if ($parentId < 1 || !Database::tableExists('base_page_categories')) {
            return [];
        }
        $hasHost = Database::columnExists('base_page_categories', 'site_host_id');
        $hostSelect = $hasHost ? ', site_host_id' : '';
        $sql = "SELECT id, name, num, parent_to, link, linkRW AS link_rw, content{$hostSelect}
                FROM base_page_categories
                WHERE enabled = 1 AND parent_to = ?";
        $params = [$parentId];
        if ($hasHost && $siteHostId > 0) {
            $sql .= ' AND (site_host_id = 0 OR site_host_id = ?)';
            $params[] = $siteHostId;
        }
        $sql .= ' ORDER BY num ASC, name ASC, id ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $row = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'link' => (string)($r['link'] ?? ''),
                'link_rw' => (string)($r['link_rw'] ?? ''),
                'parent_to' => (int)$r['parent_to'],
                'href' => '',
                'content_excerpt' => self::plainExcerpt((string)($r['content'] ?? ''), 160),
            ];
            $row['href'] = class_exists(SitesFront::class, false)
                ? SitesFront::pageHref($row)
                : self::fallbackHref($row);
            $out[] = $row;
        }
        return $out;
    }

    private static function plainExcerpt(string $html, int $maxLen): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
        if ($text === '' || mb_strlen($text) <= $maxLen) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxLen), " \t.,;:") . '…';
    }

    /**
     * Enabled pages for storefront menu (roots + one level of children), host-scoped.
     *
     * @return list<array{id:int,name:string,link:string,link_rw:string,parent_to:int,href:string,children:list<array{id:int,name:string,link:string,link_rw:string,parent_to:int,href:string}>}>
     */
    public static function menuTree(int $siteHostId = 0): array
    {
        if (!Database::tableExists('base_page_categories')) {
            return [];
        }
        $hasHost = Database::columnExists('base_page_categories', 'site_host_id');
        $hostSelect = $hasHost ? ', site_host_id' : '';
        $sql = "SELECT id, name, num, parent_to, link, linkRW AS link_rw{$hostSelect}
                FROM base_page_categories WHERE enabled = 1";
        $params = [];
        if ($hasHost && $siteHostId > 0) {
            $sql .= ' AND (site_host_id = 0 OR site_host_id = ?)';
            $params[] = $siteHostId;
        }
        $sql .= ' ORDER BY num ASC, name ASC, id ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $byParent = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $row = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'link' => (string)($r['link'] ?? ''),
                'link_rw' => (string)($r['link_rw'] ?? ''),
                'parent_to' => (int)$r['parent_to'],
                'href' => '',
                'children' => [],
            ];
            $row['href'] = class_exists(SitesFront::class, false)
                ? SitesFront::pageHref($row)
                : self::fallbackHref($row);
            $pid = (int)$r['parent_to'];
            if (!isset($byParent[$pid])) {
                $byParent[$pid] = [];
            }
            $byParent[$pid][] = $row;
        }
        $roots = [];
        foreach ([self::PARENT_ROOT, self::PARENT_HOME] as $rootParent) {
            foreach ($byParent[$rootParent] ?? [] as $root) {
                $root['children'] = $byParent[$root['id']] ?? [];
                $roots[] = $root;
            }
        }
        return $roots;
    }

    /**
     * Ancestor trail for breadcrumbs (root → … → page), excluding pseudo parents.
     *
     * @return list<array{id:int,name:string,href:string}>
     */
    public static function breadcrumbTrail(int $pageId, int $siteHostId = 0): array
    {
        if ($pageId < 1) {
            return [];
        }
        $trail = [];
        $guard = 0;
        $id = $pageId;
        while ($id > 0 && $guard < 20) {
            $guard++;
            $row = self::find($id);
            if ($row === null) {
                break;
            }
            if ($siteHostId > 0 && (int)($row['site_host_id'] ?? 0) > 0
                && (int)$row['site_host_id'] !== $siteHostId) {
                break;
            }
            array_unshift($trail, [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'href' => class_exists(SitesFront::class, false)
                    ? SitesFront::pageHref($row)
                    : self::fallbackHref($row),
            ]);
            $parent = (int)$row['parent_to'];
            if ($parent === self::PARENT_ROOT || $parent === self::PARENT_HOME) {
                break;
            }
            $id = $parent;
        }
        return $trail;
    }

    /** @param array{link?:string,link_rw?:string} $row */
    private static function fallbackHref(array $row): string
    {
        $rw = trim((string)($row['link_rw'] ?? ''));
        if ($rw !== '') {
            if ($rw[0] === '#' || $rw[0] === '/' || preg_match('#^https?://#i', $rw)) {
                return $rw;
            }
            return '/' . ltrim($rw, '/');
        }
        $link = trim((string)($row['link'] ?? ''));
        if ($link === '') {
            return '#';
        }
        if ($link[0] === '#' || strpos($link, '/#') === 0) {
            return $link[0] === '#' ? $link : $link;
        }
        if ($link[0] === '/') {
            return $link;
        }
        return '/page/' . rawurlencode($link) . '/';
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $link = trim((string)($post['link'] ?? ''));
        if ($link === '') {
            throw new InvalidArgumentException(t('sites.pages.err.link_required'));
        }
        $out = [
            'name' => mb_substr(trim((string)($post['name'] ?? '')), 0, 255),
            'num' => max(0, (int)($post['num'] ?? 1)),
            'parent_to' => (int)($post['parent_to'] ?? 0),
            'link' => mb_substr($link, 0, 255),
            'link_rw' => mb_substr(trim((string)($post['link_rw'] ?? '')), 0, 255),
            'bg_url' => mb_substr(trim((string)($post['bg_url'] ?? '')), 0, 255),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'content' => (string)($post['content'] ?? ''),
            'datas' => time(),
        ];
        if (Database::columnExists('base_page_categories', 'site_host_id')) {
            $out['site_host_id'] = max(0, (int)($post['site_host_id'] ?? 0));
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $hasHost = Database::columnExists('base_page_categories', 'site_host_id');
        if ($hasHost) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_page_categories
                    (site_host_id, name, num, parent_to, content, link, linkRW, enabled, datas, HASHTAGS, BG_URL)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', ?)'
            );
            $st->execute([
                (int)($data['site_host_id'] ?? 0),
                $data['name'],
                $data['num'],
                $data['parent_to'],
                $data['content'],
                $data['link'],
                $data['link_rw'],
                $data['enabled'],
                $data['datas'],
                $data['bg_url'],
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_page_categories
                    (name, num, parent_to, content, link, linkRW, enabled, datas, HASHTAGS, BG_URL)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'\', ?)'
            );
            $st->execute([
                $data['name'],
                $data['num'],
                $data['parent_to'],
                $data['content'],
                $data['link'],
                $data['link_rw'],
                $data['enabled'],
                $data['datas'],
                $data['bg_url'],
            ]);
        }
        $id = (int)Database::pdo()->lastInsertId();
        self::syncLegacyPage($data);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid page id');
        }
        $hasHost = Database::columnExists('base_page_categories', 'site_host_id');
        if ($hasHost) {
            $st = Database::pdo()->prepare(
                'UPDATE base_page_categories SET
                    site_host_id = ?, name = ?, num = ?, parent_to = ?, content = ?, link = ?, linkRW = ?,
                    enabled = ?, datas = ?, BG_URL = ?
                 WHERE id = ?'
            );
            $st->execute([
                (int)($data['site_host_id'] ?? 0),
                $data['name'],
                $data['num'],
                $data['parent_to'],
                $data['content'],
                $data['link'],
                $data['link_rw'],
                $data['enabled'],
                $data['datas'],
                $data['bg_url'],
                $id,
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'UPDATE base_page_categories SET
                    name = ?, num = ?, parent_to = ?, content = ?, link = ?, linkRW = ?,
                    enabled = ?, datas = ?, BG_URL = ?
                 WHERE id = ?'
            );
            $st->execute([
                $data['name'],
                $data['num'],
                $data['parent_to'],
                $data['content'],
                $data['link'],
                $data['link_rw'],
                $data['enabled'],
                $data['datas'],
                $data['bg_url'],
                $id,
            ]);
        }
        self::syncLegacyPage($data);
    }

    /**
     * Oper DoAfterSaveBeforeExit: mirror content into legacy phpshop_page / base_page when present.
     *
     * @param array<string,mixed> $data
     */
    public static function syncLegacyPage(array $data): void
    {
        $table = null;
        if (Database::tableExists('base_page')) {
            $table = 'base_page';
        } elseif (Database::tableExists('phpshop_page')) {
            $table = 'phpshop_page';
        }
        if ($table === null) {
            return;
        }
        $content = (string)($data['content'] ?? '');
        $link = (string)($data['link'] ?? '');
        $pdo = Database::pdo();
        if ((int)($data['parent_to'] ?? 0) === self::PARENT_HOME) {
            $st = $pdo->prepare("UPDATE `$table` SET content = ? WHERE category = 2000");
            $st->execute([$content]);
        }
        if ($link !== '') {
            $st = $pdo->prepare("UPDATE `$table` SET content = ? WHERE link = ?");
            $st->execute([$content, $link]);
        }
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || !Database::tableExists('base_page_categories')) {
            return 0;
        }
        $done = 0;
        foreach ($ids as $id) {
            if (self::childCount($id) > 0) {
                continue;
            }
            $st = Database::pdo()->prepare('DELETE FROM base_page_categories WHERE id = ?');
            $st->execute([$id]);
            $done += $st->rowCount();
        }
        return $done;
    }
}
