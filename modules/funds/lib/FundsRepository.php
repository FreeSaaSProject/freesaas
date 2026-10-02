<?php
declare(strict_types=1);

final class FundsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'name' => 'NAME', 'pid' => 'PID', 'type' => 'TYPE'];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_funds')) {
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
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_funds WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'PID ASC, NAME ASC';
        $limit = max(0, (int)($opts['limit'] ?? 100));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, NAME AS name, PID AS pid, TYPE AS type, DT_CREATE AS dt_create,
                       ID_USER_CREATE AS user_create, REAL_PRICE AS real_price, ID_AMT_OUT AS amt_out
                FROM base_funds WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row['id'] = (int)$row['id'];
            $row['pid'] = (int)$row['pid'];
            $row['type'] = (int)$row['type'];
            $row['balance'] = FundMovementsRepository::fundBalance((int)$row['id']);
            $items[] = $row;
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        if (!Database::tableExists('base_funds')) {
            return [];
        }
        $out = [];
        foreach (Database::pdo()->query('SELECT ID, NAME, PID FROM base_funds ORDER BY PID ASC, NAME ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $prefix = ((int)$r['PID'] > 0) ? '— ' : '';
            $out[(int)$r['ID']] = $prefix . (string)$r['NAME'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_funds')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, PID AS pid, TYPE AS type, DT_CREATE AS dt_create,
                    ID_USER_CREATE AS user_create, REAL_PRICE AS real_price, ID_AMT_OUT AS amt_out,
                    INVENTORY_REQ AS inventory_req
             FROM base_funds WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('funds.err.name'));
        }
        $type = (int)($post['type'] ?? 2);
        if (!in_array($type, [1, 2], true)) {
            $type = 2;
        }
        return [
            'name' => $name,
            'pid' => max(0, (int)($post['pid'] ?? 0)),
            'type' => $type,
            'amt_out' => max(0, (int)($post['amt_out'] ?? 0)),
            'inventory_req' => !empty($post['inventory_req']) ? 1 : 0,
            'real_price' => (float)str_replace(',', '.', (string)($post['real_price'] ?? '0')),
            'dt_create' => time(),
            'user_create' => Auth::userId(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_funds (NAME, PID, TYPE, DT_CREATE, ID_USER_CREATE, ID_AMT_OUT, INVENTORY_REQ, REAL_PRICE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['name'], $data['pid'], $data['type'], $data['dt_create'], $data['user_create'],
            $data['amt_out'], $data['inventory_req'], $data['real_price'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_funds SET NAME = ?, PID = ?, TYPE = ?, ID_AMT_OUT = ?, INVENTORY_REQ = ?, REAL_PRICE = ? WHERE ID = ?'
        );
        $st->execute([
            $data['name'], $data['pid'], $data['type'], $data['amt_out'], $data['inventory_req'],
            $data['real_price'], $id,
        ]);
    }

    /** @param list<int> $ids @return int */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_funds WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    public static function typeLabel(int $type): string
    {
        $key = 'funds.type.' . $type;
        $label = t($key);
        return $label !== $key ? $label : (string)$type;
    }
}
