<?php
declare(strict_types=1);

final class HealthSymptomsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'name' => 'NAME'];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_healths_symptoms')) {
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
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_healths_symptoms WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'NAME ASC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, NAME AS name
                FROM base_healths_symptoms WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function findOwned(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_healths_symptoms')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT_CREATE AS dt_create, NAME AS name
             FROM base_healths_symptoms WHERE ID = ? AND ID_USER = ? LIMIT 1'
        );
        $st->execute([$id, WellnessOwner::userId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findOrCreate(string $name): int
    {
        $name = mb_substr(trim($name), 0, 64);
        if ($name === '') {
            return 0;
        }
        $userId = WellnessOwner::userId();
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_healths_symptoms WHERE ID_USER = ? AND NAME = ? LIMIT 1'
        );
        $st->execute([$userId, $name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        return self::create(['name' => $name]);
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('wellness.err.name_required'));
        }
        return ['name' => mb_substr($name, 0, 64)];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_healths_symptoms (ID_USER, DT_CREATE, NAME) VALUES (?, ?, ?)'
        );
        $st->execute([WellnessOwner::userId(), time(), $data['name']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (!self::findOwned($id)) {
            throw new RuntimeException(t('wellness.err.forbidden'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_healths_symptoms SET NAME = ? WHERE ID = ? AND ID_USER = ?'
        );
        $st->execute([$data['name'], $id, WellnessOwner::userId()]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_healths_symptoms')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = WellnessOwner::userId();
        $st = Database::pdo()->prepare("DELETE FROM base_healths_symptoms WHERE ID IN ($ph) AND ID_USER = ?");
        $st->execute($params);
        return $st->rowCount();
    }
}
