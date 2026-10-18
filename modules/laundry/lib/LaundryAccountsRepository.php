<?php
declare(strict_types=1);

final class LaundryAccountsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'name' => 'NAME', 'enabled' => 'ENABLED'];
    }

    /**
     * @param array{q?:string,enabled?:string|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_laundry_accounts')) {
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
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null && $opts['enabled'] !== '') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$opts['enabled'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_laundry_accounts WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'NAME ASC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, NAME AS name, DESCR AS descr, DT AS dt, ENABLED AS enabled
                FROM base_laundry_accounts WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(bool $enabledOnly = true): array
    {
        if (!Database::tableExists('base_laundry_accounts')) {
            return [];
        }
        $sql = 'SELECT ID, NAME FROM base_laundry_accounts';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED > 0';
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
        if ($id < 1 || !Database::tableExists('base_laundry_accounts')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, DT AS dt, ENABLED AS enabled
             FROM base_laundry_accounts WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $post */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('laundry.err.name_required'));
        }
        return [
            'name' => mb_substr($name, 0, 60),
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_laundry_accounts (NAME, DESCR, DT, ENABLED) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$data['name'], $data['descr'], time(), $data['enabled']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::pdo()->prepare(
            'UPDATE base_laundry_accounts SET NAME = ?, DESCR = ?, ENABLED = ? WHERE ID = ?'
        )->execute([$data['name'], $data['descr'], $data['enabled'], $id]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_laundry_accounts WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
