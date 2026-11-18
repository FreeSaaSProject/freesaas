<?php
declare(strict_types=1);

final class ResourcesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'qty' => 'QTY',
            'enabled' => 'ENABLED',
        ];
    }

    public static function tableReady(): bool
    {
        return Database::tableExists('base_resources');
    }

    /**
     * @param array{q?:string,enabled?:int|null,order?:string,limit?:int,offset?:int} $opts
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
            $where[] = '(NAME LIKE ? OR NOTES LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null) {
            $where[] = 'ENABLED = ?';
            $params[] = !empty($opts['enabled']) ? 1 : 0;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_resources WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ID ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT AS dt, NAME AS name, NOTES AS notes,
                       QTY AS qty, ENABLED AS enabled
                FROM base_resources WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT AS dt, NAME AS name, NOTES AS notes,
                    QTY AS qty, ENABLED AS enabled
             FROM base_resources WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public static function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || !self::tableReady()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, DT AS dt, NAME AS name, NOTES AS notes,
                    QTY AS qty, ENABLED AS enabled
             FROM base_resources WHERE ID IN ($placeholders)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['id']] = $row;
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
            throw new InvalidArgumentException(t('resources.err.name_required'));
        }
        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException(t('resources.err.name_too_long'));
        }
        $qty = (int)($post['qty'] ?? 1);
        if ($qty < 1) {
            throw new InvalidArgumentException(t('resources.err.qty'));
        }
        if ($qty > 9999) {
            $qty = 9999;
        }
        return [
            'name' => $name,
            'notes' => mb_substr(trim((string)($post['notes'] ?? '')), 0, 255),
            'qty' => $qty,
            'enabled' => !empty($post['enabled']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_resources (DT, NAME, NOTES, QTY, ENABLED)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            time(),
            $data['name'],
            $data['notes'],
            $data['qty'],
            $data['enabled'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid resource id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_resources SET NAME = ?, NOTES = ?, QTY = ?, ENABLED = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['name'],
            $data['notes'],
            $data['qty'],
            $data['enabled'],
            $id,
        ]);
    }

    /**
     * @param list<int> $ids
     * @return int deleted count
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || !self::tableReady()) {
            return 0;
        }
        if (class_exists(ProductResourcesRepository::class, false)) {
            ProductResourcesRepository::deleteByResourceIds($ids);
        }
        if (class_exists(ResourceAllocationsRepository::class, false)) {
            ResourceAllocationsRepository::deleteByResourceIds($ids);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_resources WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
