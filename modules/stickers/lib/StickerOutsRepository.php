<?php
declare(strict_types=1);

final class StickerOutsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt_create' => 'DT_CREATE',
            'id_sticker' => 'ID_STICKER',
            'id_good' => 'ID_GOOD',
            'qtyu' => 'QTYU',
        ];
    }

    /**
     * @param array{q?:string,id_act?:int|null,id_sticker?:int|null,id_good?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_good_sticker_outs')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(DESCR LIKE ? OR CAST(ID AS CHAR) = ? OR CAST(ID_STICKER AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }
        foreach (['id_act' => 'ID_ACT', 'id_sticker' => 'ID_STICKER', 'id_good' => 'ID_GOOD'] as $key => $col) {
            if (!empty($opts[$key])) {
                $where[] = $col . ' = ?';
                $params[] = (int)$opts[$key];
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_good_sticker_outs WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, ID_USER AS id_user, ID_GOOD AS id_good,
                       ID_STOCK AS id_stock, ID_ACT AS id_act, ID_STICKER AS id_sticker,
                       QTYU AS qtyu, SUM AS summ, DESCR AS descr,
                       WEIGHT_BEFORE AS weight_before, WEIGHT_AFTER AS weight_after
                FROM base_good_sticker_outs WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_good_sticker_outs')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, ID_USER AS id_user, ID_GOOD AS id_good,
                    ID_STOCK AS id_stock, ID_ACT AS id_act, ID_STICKER AS id_sticker,
                    QTYU AS qtyu, SUM AS summ, DESCR AS descr, TOKEN AS token,
                    WEIGHT_BEFORE AS weight_before, WEIGHT_AFTER AS weight_after
             FROM base_good_sticker_outs WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
