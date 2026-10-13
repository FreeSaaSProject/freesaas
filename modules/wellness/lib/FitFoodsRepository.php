<?php
declare(strict_types=1);

final class FitFoodsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'ccals' => 'CCALS',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_fit_foods')) {
            return ['items' => [], 'total' => 0];
        }
        $userId = WellnessOwner::userId();
        $pdo = Database::pdo();
        $where = ['ID_USER = ?'];
        $params = [$userId];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_fit_foods WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'NAME ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_USER AS user_id, NAME AS name,
                       CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins, FATS AS fats
                FROM base_fit_foods WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return list<array{id:int,name:string,ccals:int,carbs:float,proteins:float,fats:float}> */
    public static function search(string $q, int $limit = 20): array
    {
        $res = self::list(['q' => $q, 'limit' => $limit, 'offset' => 0, 'order' => 'NAME ASC']);
        $out = [];
        foreach ($res['items'] as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'ccals' => (int)$r['ccals'],
                'carbs' => (float)$r['carbs'],
                'proteins' => (float)$r['proteins'],
                'fats' => (float)$r['fats'],
            ];
        }
        return $out;
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fit_foods')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, NAME AS name,
                    CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins, FATS AS fats
             FROM base_fit_foods WHERE ID = ? AND ID_USER = ? LIMIT 1'
        );
        $st->execute([$id, WellnessOwner::userId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Exact name match for current user (quick-add). */
    public static function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !Database::tableExists('base_fit_foods')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, NAME AS name,
                    CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins, FATS AS fats
             FROM base_fit_foods WHERE ID_USER = ? AND NAME = ? LIMIT 1'
        );
        $st->execute([WellnessOwner::userId(), $name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Unscoped find (bot/recipe sync under Auth::runAs). */
    public static function findRaw(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fit_foods')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, NAME AS name,
                    CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins, FATS AS fats
             FROM base_fit_foods WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function searchByName(int $userId, string $query, int $limit = 8): array
    {
        if ($userId < 1 || !Database::tableExists('base_fit_foods')) {
            return [];
        }
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, NAME AS name,
                    CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins, FATS AS fats
             FROM base_fit_foods
             WHERE ID_USER = ? AND NAME LIKE ?
             ORDER BY NAME ASC LIMIT ' . $limit
        );
        $st->execute([$userId, '%' . $query . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) >= 3) {
            return $rows;
        }
        // Fallback: first token prefix
        $token = preg_split('/\s+/u', mb_strtolower($query, 'UTF-8'))[0] ?? '';
        $token = preg_replace('/[^a-zа-яё0-9]/iu', '', (string)$token) ?? '';
        if (mb_strlen($token, 'UTF-8') < 3) {
            return $rows;
        }
        $st2 = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, NAME AS name,
                    CCALS AS ccals, CARBS AS carbs, PROTEINS AS proteins, FATS AS fats
             FROM base_fit_foods
             WHERE ID_USER = ? AND NAME LIKE ?
             ORDER BY NAME ASC LIMIT ' . $limit
        );
        $st2->execute([$userId, '%' . $token . '%']);
        $more = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seen = [];
        $out = [];
        foreach (array_merge($rows, $more) as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('wellness.err.name_required'));
        }
        return [
            'name' => mb_substr($name, 0, 255),
            'ccals' => max(0, (int)($post['ccals'] ?? 0)),
            'carbs' => max(0.0, (float)str_replace(',', '.', (string)($post['carbs'] ?? 0))),
            'proteins' => max(0.0, (float)str_replace(',', '.', (string)($post['proteins'] ?? 0))),
            'fats' => max(0.0, (float)str_replace(',', '.', (string)($post['fats'] ?? 0))),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return WellnessOwner::insertOwned('base_fit_foods', [
            'NAME' => $data['name'],
            'CCALS' => $data['ccals'],
            'CARBS' => $data['carbs'],
            'PROTEINS' => $data['proteins'],
            'FATS' => $data['fats'],
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (!self::findOwned($id)) {
            throw new RuntimeException(t('wellness.err.forbidden'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_fit_foods SET NAME = ?, CCALS = ?, CARBS = ?, PROTEINS = ?, FATS = ?
             WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([
            $data['name'],
            $data['ccals'],
            $data['carbs'],
            $data['proteins'],
            $data['fats'],
            $id,
            WellnessOwner::userId(),
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = self::normalizeIds($ids);
        if ($ids === [] || !Database::tableExists('base_fit_foods')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_fit_foods WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }

    /** @param list<int> $ids @return list<int> */
    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
