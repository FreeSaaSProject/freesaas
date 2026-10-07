<?php
declare(strict_types=1);

final class Acts2InRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt_create' => 'DT_CREATE',
            'summ' => 'SUMM',
            'id_stock' => 'ID_STOCK',
            'is_signed' => 'IS_SIGNED',
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
            $where[] = '(DESCR LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        if (!empty($opts['id_stock'])) {
            $where[] = 'ID_STOCK = ?';
            $params[] = (int)$opts['id_stock'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_acts2_in WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_SUPPLIER AS id_supplier, ID_STOCK AS id_stock,
                       ID_USER_CREATE AS id_user_create, ID_USER_SIGNED AS id_user_signed,
                       DESCR AS descr, DT_CREATE AS dt_create, IS_STICKED AS is_sticked,
                       IS_STRANGE AS is_strange, IS_SIGNED AS is_signed, SUMM AS summ
                FROM base_acts2_in WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
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
        return $row ?: null;
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
        $where = $positiveSumOnly ? 'WHERE SUMM > 0' : '';
        $order = $positiveSumOnly ? 'DT_CREATE DESC, ID DESC' : 'ID DESC';
        $rows = Database::pdo()->query(
            "SELECT ID, DESCR, DT_CREATE, SUMM FROM base_acts2_in
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
