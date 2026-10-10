<?php
declare(strict_types=1);

final class RedirectsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt_create' => 'DT_CREATE',
            'url_old' => 'URL_OLD',
            'url_new' => 'URL_NEW',
            'enabled' => 'ENABLED',
        ];
    }

    /**
     * @param array{q?:string,enabled?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_redirects')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(URL_OLD LIKE ? OR URL_NEW LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $en = (string)($opts['enabled'] ?? '');
        if ($en === '1' || $en === '0') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$en;
        }
        $hasHostCol = Database::columnExists('base_redirects', 'site_host_id');
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
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_redirects WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ID ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $hostSelect = $hasHostCol ? ', site_host_id' : '';
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, URL_OLD AS url_old,
                       URL_NEW AS url_new, ENABLED AS enabled{$hostSelect}
                FROM base_redirects WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $r['id'] = (int)$r['id'];
            $r['dt_create'] = (int)$r['dt_create'];
            $r['enabled'] = (int)$r['enabled'];
            $r['site_host_id'] = (int)($r['site_host_id'] ?? 0);
            $items[] = $r;
        }
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Match enabled redirect by old URL (normalized path variants).
     *
     * @return array<string,mixed>|null
     */
    public static function findEnabledByOldUrl(string $url, int $siteHostId = 0): ?array
    {
        $url = trim($url);
        if ($url === '' || !Database::tableExists('base_redirects')) {
            return null;
        }
        $candidates = array_values(array_unique(array_filter([
            $url,
            '/' . ltrim($url, '/'),
            rtrim($url, '/') . '/',
            '/' . trim($url, '/') . '/',
        ])));
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $params = $candidates;
        $hostSelect = '';
        $hostWhere = '';
        if (Database::columnExists('base_redirects', 'site_host_id')) {
            $hostSelect = ', site_host_id';
            if ($siteHostId > 0) {
                $hostWhere = ' AND (site_host_id = 0 OR site_host_id = ?)';
                $params[] = $siteHostId;
            }
        }
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, DT_CREATE AS dt_create, URL_OLD AS url_old,
                    URL_NEW AS url_new, ENABLED AS enabled{$hostSelect}
             FROM base_redirects
             WHERE ENABLED = 1 AND URL_OLD IN ($placeholders){$hostWhere}
             ORDER BY ID ASC LIMIT 1"
        );
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['dt_create'] = (int)$row['dt_create'];
        $row['enabled'] = (int)$row['enabled'];
        $row['site_host_id'] = (int)($row['site_host_id'] ?? 0);
        return $row;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_redirects')) {
            return null;
        }
        $hostSelect = Database::columnExists('base_redirects', 'site_host_id')
            ? ', site_host_id' : '';
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, DT_CREATE AS dt_create, URL_OLD AS url_old,
                    URL_NEW AS url_new, ENABLED AS enabled{$hostSelect}
             FROM base_redirects WHERE ID = ? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['dt_create'] = (int)$row['dt_create'];
        $row['enabled'] = (int)$row['enabled'];
        $row['site_host_id'] = (int)($row['site_host_id'] ?? 0);
        return $row;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $urlOld = trim((string)($post['url_old'] ?? ''));
        $urlNew = trim((string)($post['url_new'] ?? ''));
        if ($urlOld === '') {
            throw new InvalidArgumentException(t('sites.redirects.err.url_old_required'));
        }
        if ($urlNew === '') {
            throw new InvalidArgumentException(t('sites.redirects.err.url_new_required'));
        }
        $out = [
            'url_old' => mb_substr($urlOld, 0, 255),
            'url_new' => mb_substr($urlNew, 0, 255),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
        ];
        if (Database::columnExists('base_redirects', 'site_host_id')) {
            $out['site_host_id'] = max(0, (int)($post['site_host_id'] ?? 0));
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $hasHost = Database::columnExists('base_redirects', 'site_host_id');
        if ($hasHost) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_redirects (DT_CREATE, URL_OLD, URL_NEW, ENABLED, site_host_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([
                time(),
                $data['url_old'],
                $data['url_new'],
                (int)$data['enabled'],
                (int)($data['site_host_id'] ?? 0),
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_redirects (DT_CREATE, URL_OLD, URL_NEW, ENABLED)
                 VALUES (?, ?, ?, ?)'
            );
            $st->execute([
                time(),
                $data['url_old'],
                $data['url_new'],
                (int)$data['enabled'],
            ]);
        }
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid redirect id');
        }
        $hasHost = Database::columnExists('base_redirects', 'site_host_id');
        if ($hasHost) {
            $st = Database::pdo()->prepare(
                'UPDATE base_redirects SET URL_OLD = ?, URL_NEW = ?, ENABLED = ?, site_host_id = ? WHERE ID = ?'
            );
            $st->execute([
                $data['url_old'],
                $data['url_new'],
                (int)$data['enabled'],
                (int)($data['site_host_id'] ?? 0),
                $id,
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'UPDATE base_redirects SET URL_OLD = ?, URL_NEW = ?, ENABLED = ? WHERE ID = ?'
            );
            $st->execute([
                $data['url_old'],
                $data['url_new'],
                (int)$data['enabled'],
                $id,
            ]);
        }
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || !Database::tableExists('base_redirects')) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_redirects WHERE ID IN ($in)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
