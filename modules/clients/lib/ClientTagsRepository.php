<?php
declare(strict_types=1);

final class ClientTagsRepository
{
    /** @return array<string,string> request sort key => SQL expression */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'enabled' => 'ENABLED',
            'dt_create' => 'DT_CREATE',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_shopusers_tags')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_shopusers_tags WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'NAME ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, ENABLED AS enabled
                FROM base_shopusers_tags WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> id => name */
    public static function names(bool $enabledOnly = false): array
    {
        if (!Database::tableExists('base_shopusers_tags')) {
            return [];
        }
        $sql = 'SELECT ID, NAME FROM base_shopusers_tags';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED = 1';
        }
        $sql .= ' ORDER BY NAME ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_shopusers_tags')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, ENABLED AS enabled
             FROM base_shopusers_tags WHERE ID = ? LIMIT 1'
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
            throw new InvalidArgumentException(t('clients.tag.err.name_required'));
        }
        if (mb_strlen($name) > 200) {
            throw new InvalidArgumentException(t('clients.tag.err.name_too_long'));
        }
        return [
            'name' => $name,
            'enabled' => !empty($post['enabled']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_shopusers_tags (DT_CREATE, NAME, ENABLED) VALUES (?, ?, ?)'
        );
        $st->execute([time(), $data['name'], $data['enabled']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid tag id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_shopusers_tags SET NAME = ?, ENABLED = ? WHERE ID = ?'
        );
        $st->execute([$data['name'], $data['enabled'], $id]);
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
        if ($ids === [] || !Database::tableExists('base_shopusers_tags')) {
            return 0;
        }
        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if (Database::tableExists('base_client_tag_links')) {
            $pdo->prepare("DELETE FROM base_client_tag_links WHERE tag_id IN ($placeholders)")->execute($ids);
        }
        $st = $pdo->prepare("DELETE FROM base_shopusers_tags WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
