<?php
declare(strict_types=1);

final class CategoriesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'id',
            'name' => 'name',
            'num' => 'num',
            'parent_id' => 'parent_id',
            'no_cart' => 'no_cart',
            'hidden' => 'hidden',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_categories')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(name LIKE ? OR name_short LIKE ? OR CAST(id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_categories WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'num ASC, name ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT id, name, name_short, parent_id, num, hidden, no_cart, executors, review
                FROM base_categories WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        if (!Database::tableExists('base_categories')) {
            return [];
        }
        $out = [];
        foreach (Database::pdo()->query(
            'SELECT id, name FROM base_categories ORDER BY num ASC, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_categories')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, name_short, parent_id, num, hidden, no_cart, executors, review
             FROM base_categories WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Child categories of a folder (parent_id = $parentId).
     * @return list<array<string,mixed>>
     */
    public static function childrenOf(int $parentId, string $q = ''): array
    {
        if (!Database::tableExists('base_categories')) {
            return [];
        }
        $where = ['parent_id = ?'];
        $params = [$parentId];
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(name LIKE ? OR name_short LIKE ? OR CAST(id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare(
            "SELECT id, name, name_short, parent_id, num, hidden, no_cart
             FROM base_categories WHERE $sqlWhere ORDER BY num ASC, name ASC"
        );
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $cid = (int)$r['id'];
            $r['child_count'] = self::countChildren($cid);
            $r['product_count'] = self::countProducts($cid);
        }
        unset($r);
        return $rows;
    }

    /** Breadcrumb chain from root to $id (inclusive). */
    public static function breadcrumbs(int $id): array
    {
        $chain = [];
        $guard = 0;
        $pid = $id;
        while ($pid > 0 && $guard++ < 20) {
            $row = self::find($pid);
            if (!$row) {
                break;
            }
            array_unshift($chain, [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'parent_id' => (int)$row['parent_id'],
            ]);
            $pid = (int)$row['parent_id'];
        }
        return $chain;
    }

    public static function countChildren(int $parentId): int
    {
        if (!Database::tableExists('base_categories')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_categories WHERE parent_id = ?'
        );
        $st->execute([$parentId]);
        return (int)$st->fetchColumn();
    }

    public static function countProducts(int $categoryId): int
    {
        if (!Database::tableExists('base_products')) {
            return 0;
        }
        if ($categoryId > 0) {
            $st = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM base_products
                 WHERE category = ? OR dop_cat LIKE ?'
            );
            $st->execute([$categoryId, '%#' . $categoryId . '#%']);
        } else {
            $st = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM base_products WHERE category = 0'
            );
            $st->execute();
        }
        return (int)$st->fetchColumn();
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('catalog.cat.err.name_required'));
        }
        return [
            'name' => mb_substr($name, 0, 64),
            'name_short' => mb_substr(trim((string)($post['name_short'] ?? '')), 0, 20),
            'parent_id' => max(0, (int)($post['parent_id'] ?? 0)),
            'num' => (int)($post['num'] ?? 0),
            'hidden' => !empty($post['hidden']) ? 1 : 0,
            'no_cart' => !empty($post['no_cart']) ? 1 : 0,
            'executors' => trim((string)($post['executors'] ?? '')),
            'review' => trim((string)($post['review'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_categories
             (name, name_short, parent_id, num, hidden, no_cart, executors, review)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['name'],
            $data['name_short'],
            $data['parent_id'],
            $data['num'],
            $data['hidden'],
            $data['no_cart'],
            $data['executors'] !== '' ? $data['executors'] : null,
            $data['review'] !== '' ? $data['review'] : null,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid category id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_categories SET
             name = ?, name_short = ?, parent_id = ?, num = ?, hidden = ?, no_cart = ?,
             executors = ?, review = ?
             WHERE id = ?'
        );
        $st->execute([
            $data['name'],
            $data['name_short'],
            $data['parent_id'],
            $data['num'],
            $data['hidden'],
            $data['no_cart'],
            $data['executors'] !== '' ? $data['executors'] : null,
            $data['review'] !== '' ? $data['review'] : null,
            $id,
        ]);
    }

    public static function setHidden(int $id, bool $hidden): void
    {
        if ($id < 1 || !Database::tableExists('base_categories')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_categories SET hidden = ? WHERE id = ?'
        );
        $st->execute([$hidden ? 1 : 0, $id]);
    }
}
