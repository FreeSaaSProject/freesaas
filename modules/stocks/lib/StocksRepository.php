<?php
declare(strict_types=1);

final class StocksRepository
{
    public const TYPE_REAL = 1;
    public const TYPE_VIRTUAL = 2;

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'type' => 'TYPE',
            'operative' => 'OPERATIVE',
            'id_cabinet' => 'ID_CABINET',
        ];
    }

    /**
     * @param array{q?:string,type?:string|null,order?:string,limit?:int,offset?:int} $opts
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
        if (array_key_exists('type', $opts) && $opts['type'] !== null && $opts['type'] !== '') {
            $where[] = 'TYPE = ?';
            $params[] = (int)$opts['type'];
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
                       ID_CABINET AS id_cabinet, DT AS dt, TYPE AS type,
                       AUTOCONTROL_REQ AS autocontrol_req, VRT_STOCK_ID AS vrt_stock_id
                FROM base_stocks WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(?int $type = null): array
    {
        if (!Database::tableExists('base_stocks')) {
            return [];
        }
        $sql = 'SELECT ID, NAME FROM base_stocks';
        $params = [];
        if ($type !== null) {
            $sql .= ' WHERE TYPE = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY NAME ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
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
                    ID_CABINET AS id_cabinet, DT AS dt, TYPE AS type,
                    AUTOCONTROL_REQ AS autocontrol_req, VRT_STOCK_ID AS vrt_stock_id
             FROM base_stocks WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, int $excludeId = 0): array
    {
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
        $type = (int)($post['type'] ?? self::TYPE_REAL);
        if ($type !== self::TYPE_REAL && $type !== self::TYPE_VIRTUAL) {
            $type = self::TYPE_REAL;
        }
        $operative = !empty($post['operative']) ? 1 : 0;
        $autocontrol = !empty($post['autocontrol_req']) ? 1 : 0;
        $vrtStockId = max(0, (int)($post['vrt_stock_id'] ?? 0));
        $idCabinet = max(0, (int)($post['id_cabinet'] ?? 0));

        if ($type === self::TYPE_VIRTUAL) {
            $operative = 0;
            $autocontrol = 0;
            $vrtStockId = 0;
        }

        if ($autocontrol === 1 && $vrtStockId < 1) {
            throw new InvalidArgumentException(t('stocks.err.autocontrol_needs_vrt'));
        }
        if ($vrtStockId > 0) {
            $vrt = self::find($vrtStockId);
            if (!$vrt || (int)$vrt['type'] !== self::TYPE_VIRTUAL) {
                throw new InvalidArgumentException(t('stocks.err.vrt_invalid'));
            }
            if (self::vrtStockInUse($vrtStockId, $excludeId)) {
                throw new InvalidArgumentException(t('stocks.err.vrt_in_use'));
            }
        }

        return [
            'name' => $name,
            'descr' => mb_substr($descr, 0, 255),
            'operative' => $operative,
            'id_cabinet' => $idCabinet,
            'type' => $type,
            'autocontrol_req' => $autocontrol,
            'vrt_stock_id' => $vrtStockId,
        ];
    }

    public static function vrtStockInUse(int $vrtStockId, int $excludeId = 0): bool
    {
        if ($vrtStockId < 1 || !Database::tableExists('base_stocks')) {
            return false;
        }
        $sql = 'SELECT COUNT(*) FROM base_stocks WHERE VRT_STOCK_ID = ?';
        $params = [$vrtStockId];
        if ($excludeId > 0) {
            $sql .= ' AND ID != ?';
            $params[] = $excludeId;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn() > 0;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_stocks
             (NAME, DESCR, OPERATIVE, ID_CABINET, DT, TYPE, AUTOCONTROL_REQ, VRT_STOCK_ID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['name'],
            $data['descr'],
            $data['operative'],
            $data['id_cabinet'],
            time(),
            $data['type'],
            $data['autocontrol_req'],
            $data['vrt_stock_id'],
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
             NAME = ?, DESCR = ?, OPERATIVE = ?, ID_CABINET = ?,
             TYPE = ?, AUTOCONTROL_REQ = ?, VRT_STOCK_ID = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['name'],
            $data['descr'],
            $data['operative'],
            $data['id_cabinet'],
            $data['type'],
            $data['autocontrol_req'],
            $data['vrt_stock_id'],
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
