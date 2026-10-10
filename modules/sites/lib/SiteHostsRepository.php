<?php
declare(strict_types=1);

/** Public storefront hosts (multi-domain). */
final class SiteHostsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_site_hosts');
    }

    public static function normalizeHost(string $raw): string
    {
        $h = trim($raw);
        $h = preg_replace('#^https?://#i', '', $h) ?? $h;
        $h = strtolower(rtrim($h, '/'));
        if (($p = strpos($h, '/')) !== false) {
            $h = substr($h, 0, $p);
        }
        // Strip port for matching (keep in storage only if user entered host:port intentionally — we store without).
        if (($p = strpos($h, ':')) !== false) {
            $h = substr($h, 0, $p);
        }
        return $h;
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'id',
            'host' => 'host',
            'name' => 'name',
            'num' => 'num',
            'enabled' => 'enabled',
            'is_default' => 'is_default',
        ];
    }

    /**
     * @param array{q?:string,enabled?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(host LIKE ? OR name LIKE ? OR CAST(id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $en = (string)($opts['enabled'] ?? '');
        if ($en === '1' || $en === '0') {
            $where[] = 'enabled = ?';
            $params[] = (int)$en;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_site_hosts WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'is_default DESC, num ASC, host ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT id, host, enabled, name, theme, promo_id, catalog_root_id, tel, is_default, num
                FROM base_site_hosts WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $items[] = self::normalizeRow($r);
        }
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<int,string> id => label; 0 = shared across all domains
     */
    public static function options(bool $includeShared = false): array
    {
        $out = [];
        if ($includeShared) {
            $out[0] = t('sites.hosts.option_shared');
        }
        if (!self::tableReady()) {
            return $out;
        }
        foreach (
            Database::pdo()->query(
                'SELECT id, host, name FROM base_site_hosts ORDER BY is_default DESC, num ASC, host ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r
        ) {
            $label = (string)$r['host'];
            $n = trim((string)($r['name'] ?? ''));
            if ($n !== '') {
                $label .= ' — ' . $n;
            }
            $out[(int)$r['id']] = $label;
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, host, enabled, name, theme, promo_id, catalog_root_id, tel, is_default, num
             FROM base_site_hosts WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    public static function findByHost(string $host): ?array
    {
        $host = self::normalizeHost($host);
        if ($host === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, host, enabled, name, theme, promo_id, catalog_root_id, tel, is_default, num
             FROM base_site_hosts WHERE host = ? AND enabled = 1 LIMIT 1'
        );
        $st->execute([$host]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    public static function findDefault(): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->query(
            'SELECT id, host, enabled, name, theme, promo_id, catalog_root_id, tel, is_default, num
             FROM base_site_hosts WHERE is_default = 1 AND enabled = 1 ORDER BY id ASC LIMIT 1'
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row) {
            return self::normalizeRow($row);
        }
        $st = Database::pdo()->query(
            'SELECT id, host, enabled, name, theme, promo_id, catalog_root_id, tel, is_default, num
             FROM base_site_hosts WHERE enabled = 1 ORDER BY num ASC, id ASC LIMIT 1'
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return $row ? self::normalizeRow($row) : null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $host = self::normalizeHost((string)($post['host'] ?? ''));
        if ($host === '') {
            throw new InvalidArgumentException(t('sites.hosts.err.host_required'));
        }
        if (strlen($host) > 255) {
            throw new InvalidArgumentException(t('sites.hosts.err.host_too_long'));
        }
        $theme = trim((string)($post['theme'] ?? 'default'));
        if ($theme === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
            $theme = 'default';
        }
        return [
            'host' => $host,
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'name' => mb_substr(trim((string)($post['name'] ?? '')), 0, 255),
            'theme' => $theme,
            'promo_id' => max(0, (int)($post['promo_id'] ?? 0)),
            'catalog_root_id' => max(0, (int)($post['catalog_root_id'] ?? 0)),
            'tel' => mb_substr(trim((string)($post['tel'] ?? '')), 0, 64),
            'is_default' => !empty($post['is_default']) ? 1 : 0,
            'num' => (int)($post['num'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        self::assertHostUnique((string)$data['host'], 0);
        $pdo = Database::pdo();
        if (!empty($data['is_default'])) {
            $pdo->exec('UPDATE base_site_hosts SET is_default = 0');
        }
        $st = $pdo->prepare(
            'INSERT INTO base_site_hosts
                (host, enabled, name, theme, promo_id, catalog_root_id, tel, is_default, num)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['host'],
            (int)$data['enabled'],
            $data['name'],
            $data['theme'],
            (int)$data['promo_id'],
            (int)$data['catalog_root_id'],
            $data['tel'],
            (int)$data['is_default'],
            (int)$data['num'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid host id');
        }
        self::assertHostUnique((string)$data['host'], $id);
        $pdo = Database::pdo();
        if (!empty($data['is_default'])) {
            $pdo->prepare('UPDATE base_site_hosts SET is_default = 0 WHERE id <> ?')->execute([$id]);
        }
        $st = $pdo->prepare(
            'UPDATE base_site_hosts SET
                host = ?, enabled = ?, name = ?, theme = ?, promo_id = ?,
                catalog_root_id = ?, tel = ?, is_default = ?, num = ?
             WHERE id = ?'
        );
        $st->execute([
            $data['host'],
            (int)$data['enabled'],
            $data['name'],
            $data['theme'],
            (int)$data['promo_id'],
            (int)$data['catalog_root_id'],
            $data['tel'],
            (int)$data['is_default'],
            (int)$data['num'],
            $id,
        ]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        }));
        if ($ids === [] || !self::tableReady()) {
            return 0;
        }
        $in = implode(',', $ids);
        return (int)Database::pdo()->exec("DELETE FROM base_site_hosts WHERE id IN ($in)");
    }

    private static function assertHostUnique(string $host, int $exceptId): void
    {
        $st = Database::pdo()->prepare(
            'SELECT id FROM base_site_hosts WHERE host = ? AND id <> ? LIMIT 1'
        );
        $st->execute([$host, $exceptId]);
        if ($st->fetchColumn()) {
            throw new InvalidArgumentException(t('sites.hosts.err.host_taken'));
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['enabled'] = (int)$row['enabled'];
        $row['promo_id'] = (int)$row['promo_id'];
        $row['catalog_root_id'] = (int)$row['catalog_root_id'];
        $row['is_default'] = (int)$row['is_default'];
        $row['num'] = (int)$row['num'];
        $row['host'] = (string)$row['host'];
        $row['name'] = (string)($row['name'] ?? '');
        $row['theme'] = (string)($row['theme'] ?? 'default');
        $row['tel'] = (string)($row['tel'] ?? '');
        return $row;
    }
}
