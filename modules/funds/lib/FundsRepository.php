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
        $balances = FundMovementsRepository::balances();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row['id'] = (int)$row['id'];
            $row['pid'] = (int)$row['pid'];
            $row['type'] = (int)$row['type'];
            $row['balance'] = (float)($balances[$row['id']] ?? 0.0);
            $items[] = $row;
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @var array<int,array{id:int,name:string,pid:int,type:int,real_price:float}>|null */
    private static $rowsCache = null;

    /** @return array<int,array{id:int,name:string,pid:int,type:int,real_price:float}> */
    public static function allRows(): array
    {
        if (self::$rowsCache !== null) {
            return self::$rowsCache;
        }
        if (!Database::tableExists('base_funds')) {
            return self::$rowsCache = [];
        }
        $out = [];
        $rows = Database::pdo()->query(
            'SELECT ID, NAME, PID, TYPE, REAL_PRICE FROM base_funds ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $out[(int)$r['ID']] = [
                'id' => (int)$r['ID'],
                'name' => (string)$r['NAME'],
                'pid' => (int)$r['PID'],
                'type' => (int)$r['TYPE'],
                'real_price' => (float)$r['REAL_PRICE'],
            ];
        }
        return self::$rowsCache = $out;
    }

    /** @return array<int,int> fund id => children count */
    public static function childCounts(): array
    {
        $out = [];
        foreach (self::allRows() as $r) {
            if ($r['pid'] > 0) {
                $out[$r['pid']] = ($out[$r['pid']] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** A fund with children is a group: movements are posted to its leaves only. */
    public static function isGroup(int $id): bool
    {
        return isset(self::childCounts()[$id]);
    }

    /**
     * Groups (funds with children) with their children, plus standalone root leaves.
     *
     * @return list<array{id:int,name:string,type:int,is_group:bool,balance:float,children:list<array<string,mixed>>}>
     */
    public static function tree(string $q = ''): array
    {
        $rows = self::allRows();
        $balances = FundMovementsRepository::balances();
        $counts = self::childCounts();
        $q = trim($q);
        $match = static function (array $r) use ($q): bool {
            if ($q === '') {
                return true;
            }
            return (string)$r['id'] === $q || mb_stripos($r['name'], $q) !== false;
        };
        $children = [];
        foreach ($rows as $r) {
            if ($r['pid'] > 0 && isset($rows[$r['pid']])) {
                $r['balance'] = (float)($balances[$r['id']] ?? 0.0);
                $children[$r['pid']][] = $r;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if ($r['pid'] > 0 && isset($rows[$r['pid']])) {
                continue;
            }
            $isGroup = isset($counts[$r['id']]);
            $kids = $children[$r['id']] ?? [];
            if (!$match($r)) {
                $kids = array_values(array_filter($kids, $match));
                if ($kids === []) {
                    continue;
                }
            }
            $sum = (float)($balances[$r['id']] ?? 0.0);
            foreach ($kids as $k) {
                $sum += (float)$k['balance'];
            }
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'type' => $r['type'],
                'is_group' => $isGroup,
                'balance' => $sum,
                'children' => $kids,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['is_group'] !== $b['is_group']) {
                return $a['is_group'] ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Selectable funds (leaves) grouped by parent name for <optgroup>.
     *
     * @return list<array{label:string,options:array<int,string>}>
     */
    public static function leafOptions(): array
    {
        $groups = [];
        $standalone = [];
        foreach (self::tree() as $node) {
            if ($node['is_group']) {
                $opts = [];
                foreach ($node['children'] as $k) {
                    $opts[(int)$k['id']] = (string)$k['name'];
                }
                $groups[] = ['label' => (string)$node['name'], 'options' => $opts];
            } else {
                $standalone[(int)$node['id']] = (string)$node['name'];
            }
        }
        if ($standalone !== []) {
            $groups[] = ['label' => '', 'options' => $standalone];
        }
        return $groups;
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
        $cols = ['NAME', 'PID', 'TYPE', 'DT_CREATE', 'ID_USER_CREATE', 'ID_AMT_OUT', 'INVENTORY_REQ', 'REAL_PRICE'];
        $params = [
            $data['name'], $data['pid'], $data['type'], $data['dt_create'], $data['user_create'],
            $data['amt_out'], $data['inventory_req'], $data['real_price'],
        ];
        // Legacy oper schemas: NOT NULL columns without DEFAULT under strict SQL mode.
        $optional = ['PID_2' => 0, 'SORT_TYPE' => 0, 'DT_REPRICING' => 0, 'DESCR_REPRICING' => '', 'REPRICING_AMORT' => 0];
        foreach ($optional as $col => $val) {
            if (Database::columnExists('base_funds', $col)) {
                $cols[] = $col;
                $params[] = $val;
            }
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_funds (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')'
        );
        $st->execute($params);
        self::$rowsCache = null;
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
        self::$rowsCache = null;
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
        self::$rowsCache = null;
        return $st->rowCount();
    }

    public static function typeLabel(int $type): string
    {
        $key = 'funds.type.' . $type;
        $label = t($key);
        return $label !== $key ? $label : (string)$type;
    }
}
