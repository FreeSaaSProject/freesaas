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
        $sql = "SELECT id, name, num, parent_to, link, linkRW AS link_rw, enabled, datas, BG_URL AS bg_url,
                       (content != '' AND content IS NOT NULL) AS has_content
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
     * @param array{q?:string,enabled?:string,limit?:int,offset?:int} $opts
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
        $all = $pdo->query(
            "SELECT id, name, num, parent_to, link, linkRW AS link_rw, enabled, datas, BG_URL AS bg_url,
                    (content != '' AND content IS NOT NULL) AS has_content
             FROM base_page_categories
             ORDER BY num ASC, name ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
            self::PARENT_ROOT => t('likespa.pages.parent.root'),
            self::PARENT_HOME => t('likespa.pages.parent.home'),
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
            return t('likespa.pages.parent.root');
        }
        if ($parentTo === self::PARENT_HOME) {
            return t('likespa.pages.parent.home');
        }
        $row = self::find($parentTo);
        if ($row) {
            return (string)$row['name'];
        }
        return '#' . $parentTo;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_page_categories')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, num, parent_to, content, link, linkRW AS link_rw,
                    enabled, datas, HASHTAGS AS hashtags, BG_URL AS bg_url
             FROM base_page_categories WHERE id = ? LIMIT 1'
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
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $link = trim((string)($post['link'] ?? ''));
        if ($link === '') {
            throw new InvalidArgumentException(t('likespa.pages.err.link_required'));
        }
        return [
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
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
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
