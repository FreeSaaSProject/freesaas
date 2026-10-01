<?php
declare(strict_types=1);

final class CabinetsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'limit_min' => 'LIMIT_MIN',
            'limit_max' => 'LIMIT_MAX',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_cabinets')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR DESCR LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_cabinets WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ID ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT AS dt, NAME AS name, DESCR AS descr,
                       LIMIT_MIN AS limit_min, LIMIT_MAX AS limit_max
                FROM base_cabinets WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_cabinets')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT AS dt, NAME AS name, DESCR AS descr,
                    LIMIT_MIN AS limit_min, LIMIT_MAX AS limit_max
             FROM base_cabinets WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('cabinets.err.name_required'));
        }
        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException(t('cabinets.err.name_too_long'));
        }
        $limitMin = (int)($post['limit_min'] ?? 0);
        $limitMax = (int)($post['limit_max'] ?? 0);
        if ($limitMax < 0) {
            $limitMax = 0;
        }
        if ($limitMin < 0) {
            $limitMin = 0;
        }
        if ($limitMax > 0 && $limitMin > $limitMax) {
            throw new InvalidArgumentException(t('cabinets.err.limits'));
        }
        return [
            'name' => $name,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'limit_min' => $limitMin,
            'limit_max' => $limitMax,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_cabinets (DT, NAME, DESCR, LIMIT_MIN, LIMIT_MAX)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            time(),
            $data['name'],
            $data['descr'],
            $data['limit_min'],
            $data['limit_max'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid cabinet id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_cabinets SET NAME = ?, DESCR = ?, LIMIT_MIN = ?, LIMIT_MAX = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['name'],
            $data['descr'],
            $data['limit_min'],
            $data['limit_max'],
            $id,
        ]);
    }

    /**
     * @param list<int> $ids
     * @return int deleted count
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_cabinets')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_cabinets WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
