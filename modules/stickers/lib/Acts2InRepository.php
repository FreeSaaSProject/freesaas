<?php
declare(strict_types=1);

final class Acts2InRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'a.ID',
            'dt_create' => 'a.DT_CREATE',
            'summ' => 'COALESCE(st.sum_price, 0)',
            'id_stock' => 'a.ID_STOCK',
            'is_signed' => 'a.IS_SIGNED',
        ];
    }

    /**
     * @param array{q?:string,id_stock?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_acts2_in')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.DESCR LIKE ? OR CAST(a.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        if (!empty($opts['id_stock'])) {
            $where[] = 'a.ID_STOCK = ?';
            $params[] = (int)$opts['id_stock'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_acts2_in a WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'a.ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $joinSt = Database::tableExists('base_good_sticker_ins')
            ? 'LEFT JOIN (
                    SELECT ID_ACT, COALESCE(SUM(PRICE), 0) AS sum_price
                    FROM base_good_sticker_ins
                    GROUP BY ID_ACT
               ) st ON st.ID_ACT = a.ID'
            : 'LEFT JOIN (SELECT 0 AS ID_ACT, 0 AS sum_price) st ON 0';
        $sql = "SELECT a.ID AS id, a.ID_SUPPLIER AS id_supplier, a.ID_STOCK AS id_stock,
                       a.ID_USER_CREATE AS id_user_create, a.ID_USER_SIGNED AS id_user_signed,
                       a.DESCR AS descr, a.DT_CREATE AS dt_create, a.IS_STICKED AS is_sticked,
                       a.IS_STRANGE AS is_strange, a.IS_SIGNED AS is_signed,
                       a.SUMM AS summ_stored,
                       ROUND(COALESCE(st.sum_price, 0), 2) AS summ
                FROM base_acts2_in a
                $joinSt
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Heal denormalized SUMM (oper: «если 0 — сохраните акт ещё раз»).
        foreach ($items as &$item) {
            $live = round((float)$item['summ'], 2);
            $stored = round((float)($item['summ_stored'] ?? 0), 2);
            if (abs($live - $stored) > 0.009) {
                self::recalcSumm((int)$item['id']);
            }
            unset($item['summ_stored']);
        }
        unset($item);
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_acts2_in')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_SUPPLIER AS id_supplier, ID_STOCK AS id_stock,
                    ID_USER_CREATE AS id_user_create, ID_USER_SIGNED AS id_user_signed,
                    DESCR AS descr, LOG AS log, DT_CREATE AS dt_create,
                    IS_STICKED AS is_sticked, IS_STRANGE AS is_strange,
                    IS_SIGNED AS is_signed, SUMM AS summ
             FROM base_acts2_in WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Prefer live sticker sum when denormalized column is stale.
        if (Database::tableExists('base_good_sticker_ins')) {
            $live = self::liveSumm($id);
            if (abs($live - (float)$row['summ']) > 0.009) {
                self::recalcSumm($id);
                $row['summ'] = $live;
            }
        }
        return $row;
    }

    public static function liveSumm(int $actId): float
    {
        if ($actId < 1 || !Database::tableExists('base_good_sticker_ins')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(PRICE), 0) FROM base_good_sticker_ins WHERE ID_ACT = ?'
        );
        $st->execute([$actId]);
        return round((float)$st->fetchColumn(), 2);
    }

    /**
     * Recent receipt acts for ledger move select (+ optional current id).
     *
     * @return array<int,string>
     */
    public static function optionsForSelect(int $limit = 80, int $includeId = 0): array
    {
        return self::buildOptions($limit, $includeId, false);
    }

    /**
     * Oper setAct2 picker: recent acts with SUMM>0 by DT_CREATE DESC.
     *
     * @return array<int,string>
     */
    public static function optionsForAttach(int $limit = 50): array
    {
        return self::buildOptions($limit, 0, true);
    }

    /**
     * @return array<int,string>
     */
    private static function buildOptions(int $limit, int $includeId, bool $positiveSumOnly): array
    {
        if (!Database::tableExists('base_acts2_in')) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $out = [];
        $join = Database::tableExists('base_good_sticker_ins')
            ? 'LEFT JOIN (
                    SELECT ID_ACT, COALESCE(SUM(PRICE), 0) AS sum_price
                    FROM base_good_sticker_ins GROUP BY ID_ACT
               ) st ON st.ID_ACT = a.ID'
            : '';
        $sumExpr = $join !== '' ? 'COALESCE(st.sum_price, a.SUMM, 0)' : 'a.SUMM';
        $where = $positiveSumOnly ? "WHERE $sumExpr > 0" : '';
        $order = $positiveSumOnly ? 'a.DT_CREATE DESC, a.ID DESC' : 'a.ID DESC';
        $rows = Database::pdo()->query(
            "SELECT a.ID, a.DESCR, a.DT_CREATE, ROUND($sumExpr, 2) AS SUMM
             FROM base_acts2_in a $join
             $where ORDER BY $order LIMIT " . $limit
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            $out[$id] = self::optionLabel($id, (string)$r['DESCR'], (int)$r['DT_CREATE'], (float)$r['SUMM']);
        }
        if ($includeId > 0 && !isset($out[$includeId])) {
            $row = self::find($includeId);
            if ($row) {
                $out[$includeId] = self::optionLabel(
                    $includeId,
                    (string)$row['descr'],
                    (int)$row['dt_create'],
                    (float)$row['summ']
                );
            } else {
                $out[$includeId] = '#' . $includeId;
            }
        }
        return $out;
    }

    private static function optionLabel(int $id, string $descr, int $dtCreate, float $summ): string
    {
        $dt = $dtCreate > 0 ? date('d.m.Y', $dtCreate) : '';
        $descr = trim($descr);
        $label = '#' . $id;
        if ($dt !== '') {
            $label .= ' ' . $dt;
        }
        if ($descr !== '') {
            $label .= ' — ' . mb_strimwidth($descr, 0, 48, '…');
        }
        $label .= ' (' . number_format($summ, 2, '.', ' ') . ')';
        return $label;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $idStock = (int)($post['id_stock'] ?? 0);
        if ($idStock < 1 && class_exists('StocksRepository', false)) {
            $idStock = (int)(StocksRepository::defaultForStaff() ?? 0);
        }
        if ($idStock < 1) {
            throw new InvalidArgumentException(t('stickers.err.stock_required'));
        }
        $descr = trim((string)($post['descr'] ?? ''));
        if ($descr === '') {
            throw new InvalidArgumentException(t('stickers.err.descr_required'));
        }
        return [
            'id_supplier' => max(0, (int)($post['id_supplier'] ?? 0)),
            'id_stock' => $idStock,
            'descr' => mb_substr($descr, 0, 255),
            'is_signed' => !empty($post['is_signed']) ? 1 : 0,
            'is_sticked' => !empty($post['is_sticked']) ? 1 : 0,
        ];
    }

    /**
     * Optional sticker lines for atomic receipt create (assistant / JSON).
     * Accepts `lines` as list of maps or a JSON string.
     *
     * @param array<string,mixed> $post
     * @return list<array<string,mixed>>
     */
    public static function linesFromPost(array $post): array
    {
        $raw = $post['lines'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $line) {
            if (is_array($line)) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, int $userId): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_acts2_in
             (ID_SUPPLIER, ID_STOCK, ID_USER_CREATE, ID_USER_SIGNED, DESCR, LOG,
              DT_CREATE, IS_STICKED, IS_STRANGE, IS_SIGNED, SUMM)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0)'
        );
        $signedUser = !empty($data['is_signed']) ? $userId : 0;
        $st->execute([
            $data['id_supplier'],
            $data['id_stock'],
            $userId,
            $signedUser,
            $data['descr'],
            '',
            time(),
            $data['is_sticked'],
            $data['is_signed'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * Create receipt act and sticker batches in one transaction (one Confirm for inbound).
     *
     * @param array<string,mixed> $data from dataFromPost
     * @param list<array<string,mixed>> $lines raw line posts (id_good|good_q, qty, price, …)
     * @return array{id:int,sticker_ids:list<int>}
     */
    public static function createWithLines(array $data, array $lines, int $userId): array
    {
        if ($lines === []) {
            return ['id' => self::create($data, $userId), 'sticker_ids' => []];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = self::create($data, $userId);
            $stickerIds = [];
            foreach ($lines as $line) {
                $linePost = $line;
                $linePost['id_act'] = $id;
                $batch = StickerInsRepository::dataFromPost($linePost);
                foreach (StickerInsRepository::createBatch($batch, $userId) as $sid) {
                    $stickerIds[] = $sid;
                }
            }
            $pdo->commit();
            return ['id' => $id, 'sticker_ids' => $stickerIds];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, int $userId): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new InvalidArgumentException(t('stickers.err.act_not_found', ['id' => $id]));
        }
        if (!empty($row['is_signed']) && !Auth::capability('SUPERADMIN_ACCESS')) {
            throw new InvalidArgumentException(t('stickers.err.act_signed_locked'));
        }
        $signedUser = !empty($data['is_signed'])
            ? ((int)$row['id_user_signed'] > 0 ? (int)$row['id_user_signed'] : $userId)
            : 0;
        $st = Database::pdo()->prepare(
            'UPDATE base_acts2_in SET
             ID_SUPPLIER = ?, ID_STOCK = ?, DESCR = ?, IS_STICKED = ?, IS_SIGNED = ?,
             ID_USER_SIGNED = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['id_supplier'],
            $data['id_stock'],
            $data['descr'],
            $data['is_sticked'],
            $data['is_signed'],
            $signedUser,
            $id,
        ]);
        // Cascade stock to stickers
        Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET ID_STOCK = ?, ID_SUPPLIER = ? WHERE ID_ACT = ?'
        )->execute([$data['id_stock'], $data['id_supplier'], $id]);
        self::recalcSumm($id);
    }

    public static function recalcSumm(int $actId): void
    {
        if ($actId < 1 || !Database::tableExists('base_good_sticker_ins')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(PRICE), 0) FROM base_good_sticker_ins WHERE ID_ACT = ?'
        );
        $st->execute([$actId]);
        $summ = (float)$st->fetchColumn();
        Database::pdo()->prepare('UPDATE base_acts2_in SET SUMM = ? WHERE ID = ?')
            ->execute([$summ, $actId]);
    }

    public static function stickerCount(int $actId): int
    {
        if ($actId < 1 || !Database::tableExists('base_good_sticker_ins')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_good_sticker_ins WHERE ID_ACT = ?');
        $st->execute([$actId]);
        return (int)$st->fetchColumn();
    }

    public static function usedStickerCount(int $actId): int
    {
        if ($actId < 1 || !Database::tableExists('base_good_sticker_ins')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_good_sticker_ins WHERE ID_ACT = ? AND QTYU_OUT > 0'
        );
        $st->execute([$actId]);
        return (int)$st->fetchColumn();
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<int>}
     */
    public static function deleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            if (self::stickerCount($id) > 0) {
                $skipped[] = $id;
                continue;
            }
            Database::pdo()->prepare('DELETE FROM base_acts2_in WHERE ID = ?')->execute([$id]);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }
}
