<?php
declare(strict_types=1);

final class StocksRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'operative' => 'OPERATIVE',
            'id_cabinet' => 'ID_CABINET',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_stocks')) {
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
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_stocks WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ID ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, NAME AS name, DESCR AS descr, OPERATIVE AS operative,
                       ID_CABINET AS id_cabinet, DT AS dt
                FROM base_stocks WHERE $sqlWhere ORDER BY $order";
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
        if (!Database::tableExists('base_stocks')) {
            return [];
        }
        $st = Database::pdo()->query('SELECT ID, NAME FROM base_stocks ORDER BY NAME ASC');
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_stocks')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DESCR AS descr, OPERATIVE AS operative,
                    ID_CABINET AS id_cabinet, DT AS dt
             FROM base_stocks WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Default warehouse for receipts when id_stock omitted.
     * 1) OPERATIVE=1 linked to staff skin cabinet(s); 2) single site-wide OPERATIVE=1.
     */
    public static function defaultForStaff(?int $userId = null): ?int
    {
        if (!Database::tableExists('base_stocks')) {
            return null;
        }
        $pdo = Database::pdo();
        $uid = $userId !== null && $userId > 0 ? $userId : Auth::userId();
        $cabinetIds = [];
        if ($uid > 0 && class_exists('UsersRepository', false)) {
            $user = UsersRepository::find($uid);
            if ($user) {
                $cabinetIds = UsersRepository::parseHashIds((string)($user['skin'] ?? ''));
            }
        }
        foreach ($cabinetIds as $cabId) {
            $cabId = (int)$cabId;
            if ($cabId < 1) {
                continue;
            }
            $st = $pdo->prepare(
                'SELECT ID FROM base_stocks WHERE OPERATIVE = 1 AND ID_CABINET = ? ORDER BY ID ASC LIMIT 1'
            );
            $st->execute([$cabId]);
            $id = (int)$st->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        $ids = $pdo->query('SELECT ID FROM base_stocks WHERE OPERATIVE = 1 ORDER BY ID ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (count($ids) === 1) {
            return (int)$ids[0];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, int $excludeId = 0): array
    {
        unset($excludeId);
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('stocks.err.name_required'));
        }
        if (mb_strlen($name) > 20) {
            throw new InvalidArgumentException(t('stocks.err.name_too_long'));
        }
        $descr = trim((string)($post['descr'] ?? ''));
        if ($descr === '') {
            throw new InvalidArgumentException(t('stocks.err.descr_required'));
        }
        $operative = !empty($post['operative']) ? 1 : 0;
        $idCabinet = max(0, (int)($post['id_cabinet'] ?? 0));

        return [
            'name' => $name,
            'descr' => mb_substr($descr, 0, 255),
            'operative' => $operative,
            'id_cabinet' => $idCabinet,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_stocks
             (NAME, DESCR, OPERATIVE, ID_CABINET, DT)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['name'],
            $data['descr'],
            $data['operative'],
            $data['id_cabinet'],
            time(),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid stock id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_stocks SET
             NAME = ?, DESCR = ?, OPERATIVE = ?, ID_CABINET = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['name'],
            $data['descr'],
            $data['operative'],
            $data['id_cabinet'],
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
        if ($ids === [] || !Database::tableExists('base_stocks')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_stocks WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
