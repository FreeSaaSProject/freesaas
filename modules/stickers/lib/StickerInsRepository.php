<?php
declare(strict_types=1);

final class StickerInsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt_create' => 'DT_CREATE',
            'id_good' => 'ID_GOOD',
            'id_act' => 'ID_ACT',
            'qtyu_out' => 'QTYU_OUT',
        ];
    }

    /**
     * @param array{q?:string,id_act?:int|null,id_good?:int|null,id_stock?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_good_sticker_ins')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(PIN LIKE ? OR HASH LIKE ? OR DESCR LIKE ? OR CAST(ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        foreach (['id_act' => 'ID_ACT', 'id_good' => 'ID_GOOD', 'id_stock' => 'ID_STOCK'] as $key => $col) {
            if (!empty($opts[$key])) {
                $where[] = $col . ' = ?';
                $params[] = (int)$opts[$key];
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_good_sticker_ins WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT ID AS id, ID_USER_CREATE AS id_user_create, ID_ACT AS id_act,
                       ID_GOOD AS id_good, ID_STOCK AS id_stock, ID_SUPPLIER AS id_supplier,
                       DT_CREATE AS dt_create, DT_EXPIRE AS dt_expire, DT_PRINT AS dt_print,
                       PIN AS pin, HASH AS hash, PRICE AS price, QTY AS qty,
                       QTYU_IN AS qtyu_in, QTYU_OUT AS qtyu_out, QTY_QTYU_RATE AS qty_qtyu_rate,
                       DESCR AS descr, DESCR_STOCK AS descr_stock, WEIGHT_START AS weight_start
                FROM base_good_sticker_ins WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_good_sticker_ins')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER_CREATE AS id_user_create, ID_ACT AS id_act,
                    ID_GOOD AS id_good, ID_STOCK AS id_stock, ID_SUPPLIER AS id_supplier,
                    DT_CREATE AS dt_create, DT_EXPIRE AS dt_expire, DT_REVISION AS dt_revision,
                    DT_MOVEMENT AS dt_movement, DT_PRINT AS dt_print, DT_REPRINT AS dt_reprint,
                    PIN AS pin, HASH AS hash, LOG AS log, PRICE AS price, QTY AS qty,
                    QTYU_IN AS qtyu_in, QTYU_OUT AS qtyu_out, QTY_QTYU_RATE AS qty_qtyu_rate,
                    DESCR AS descr, DESCR_STOCK AS descr_stock, IS_STRANGE AS is_strange,
                    WEIGHT_START AS weight_start, TOKEN AS token
             FROM base_good_sticker_ins WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function remaining(array $row): float
    {
        return round((float)$row['qtyu_in'] - (float)$row['qtyu_out'], 3);
    }

    /**
     * Resolve sticker from bot input: numeric id, "id pin", pin, or hash.
     */
    public static function findByBotToken(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || !Database::tableExists('base_good_sticker_ins')) {
            return null;
        }
        if (preg_match('/^(\d+)\s+(\S+)$/', $raw, $m)) {
            $row = self::find((int)$m[1]);
            if ($row === null) {
                return null;
            }
            $pin = trim((string)$m[2]);
            if ($pin !== '' && (string)($row['pin'] ?? '') !== $pin && (string)($row['hash'] ?? '') !== $pin) {
                return null;
            }
            return $row;
        }
        if (ctype_digit($raw)) {
            $byId = self::find((int)$raw);
            if ($byId !== null) {
                return $byId;
            }
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_good_sticker_ins WHERE PIN = ? OR HASH = ? ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$raw, $raw]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id) : null;
    }

    public static function randPin(int $len = 4): string
    {
        $digits = '';
        for ($i = 0; $i < $len; $i++) {
            $digits .= (string)random_int(0, 9);
        }
        return $digits;
    }

    public static function makeHash(string $pin, int $salt = 0): string
    {
        return md5($pin . ($salt > 0 ? (string)$salt : (string)time()));
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $idAct = (int)($post['id_act'] ?? 0);
        $idGood = (int)($post['id_good'] ?? 0);
        if ($idAct < 1) {
            throw new InvalidArgumentException(t('stickers.err.act_required'));
        }
        if ($idGood < 1) {
            throw new InvalidArgumentException(t('stickers.err.good_required'));
        }
        $act = Acts2InRepository::find($idAct);
        if (!$act) {
            throw new InvalidArgumentException(t('stickers.err.act_not_found', ['id' => $idAct]));
        }
        if (!empty($act['is_signed']) && !Auth::capability('SUPERADMIN_ACCESS')) {
            throw new InvalidArgumentException(t('stickers.err.act_signed_locked'));
        }
        $qty = max(1, (int)($post['qty'] ?? 1));
        $rate = (float)str_replace(',', '.', (string)($post['qty_qtyu_rate'] ?? '1'));
        if ($rate <= 0) {
            throw new InvalidArgumentException(t('stickers.err.rate_required'));
        }
        $price = (float)str_replace(',', '.', (string)($post['price'] ?? '0'));
        return [
            'id_act' => $idAct,
            'id_good' => $idGood,
            'id_stock' => (int)$act['id_stock'],
            'id_supplier' => (int)$act['id_supplier'],
            'qty' => $qty,
            'qty_qtyu_rate' => $rate,
            'qtyu_in' => $rate,
            'price' => $price,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'descr_stock' => mb_substr(trim((string)($post['descr_stock'] ?? '')), 0, 255),
            'dt_expire' => max(0, (int)($post['dt_expire'] ?? 0)),
            'weight_start' => (float)str_replace(',', '.', (string)($post['weight_start'] ?? '0')),
            'activate' => !empty($post['activate']),
        ];
    }

    /**
     * Create twin stickers for QTY units (oper parity).
     *
     * @param array<string,mixed> $data
     * @return list<int> created ids
     */
    public static function createBatch(array $data, int $userId): array
    {
        $pdo = Database::pdo();
        $ids = [];
        $now = time();
        $qty = (int)$data['qty'];
        $dtPrint = !empty($data['activate']) ? $now : 0;
        $sql = 'INSERT INTO base_good_sticker_ins
                (ID_USER_CREATE, ID_ACT, ID_GOOD, ID_STOCK, ID_SUPPLIER, DT_CREATE, DT_EXPIRE,
                 DT_REVISION, DT_MOVEMENT, DT_PRINT, DT_REPRINT, PIN, HASH, LOG, PRICE, QTY,
                 QTYU_IN, QTYU_OUT, QTY_QTYU_RATE, DESCR_STOCK, DESCR, IS_STRANGE, WEIGHT_START, TOKEN)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, \'\', ?, \'\')';
        $st = $pdo->prepare($sql);
        for ($i = 0; $i < $qty; $i++) {
            $pin = self::randPin(4);
            $hash = self::makeHash($pin, $now + $i);
            $st->execute([
                $userId,
                $data['id_act'],
                $data['id_good'],
                $data['id_stock'],
                $data['id_supplier'],
                $now,
                $data['dt_expire'],
                $dtPrint,
                $pin,
                $hash,
                '',
                $data['price'],
                $qty,
                $data['qtyu_in'],
                $data['qty_qtyu_rate'],
                $data['descr_stock'],
                $data['descr'],
                $data['weight_start'],
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        Acts2InRepository::recalcSumm((int)$data['id_act']);
        return $ids;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new InvalidArgumentException(t('stickers.err.sticker_not_found', ['id' => $id]));
        }
        if ((float)$row['qtyu_out'] > 0 && !Auth::capability('SUPERADMIN_ACCESS')) {
            throw new InvalidArgumentException(t('stickers.err.sticker_used_locked'));
        }
        if ((int)$data['qty'] !== (int)$row['qty']) {
            throw new InvalidArgumentException(t('stickers.err.qty_locked'));
        }
        $dtPrint = (int)$row['dt_print'];
        if (!empty($data['activate']) && $dtPrint < 1) {
            $dtPrint = time();
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET
             ID_GOOD = ?, PRICE = ?, QTYU_IN = ?, QTY_QTYU_RATE = ?,
             DESCR = ?, DESCR_STOCK = ?, DT_EXPIRE = ?, WEIGHT_START = ?, DT_PRINT = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['id_good'],
            $data['price'],
            $data['qtyu_in'],
            $data['qty_qtyu_rate'],
            $data['descr'],
            $data['descr_stock'],
            $data['dt_expire'],
            $data['weight_start'],
            $dtPrint,
            $id,
        ]);
        // Sync twin batch fields
        Database::pdo()->prepare(
            'UPDATE base_good_sticker_ins SET
             PRICE = ?, QTY_QTYU_RATE = ?, QTYU_IN = ?, DESCR = ?, DESCR_STOCK = ?
             WHERE ID_ACT = ? AND ID_GOOD = ? AND QTY = ? AND QTYU_OUT = 0'
        )->execute([
            $data['price'],
            $data['qty_qtyu_rate'],
            $data['qtyu_in'],
            $data['descr'],
            $data['descr_stock'],
            (int)$row['id_act'],
            (int)$row['id_good'],
            (int)$row['qty'],
        ]);
        Acts2InRepository::recalcSumm((int)$row['id_act']);
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<int>}
     */
    public static function deleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        $actIds = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = self::find($id);
            if (!$row) {
                continue;
            }
            if ((float)$row['qtyu_out'] > 0) {
                $skipped[] = $id;
                continue;
            }
            Database::pdo()->prepare('DELETE FROM base_good_sticker_ins WHERE ID = ?')->execute([$id]);
            $actIds[(int)$row['id_act']] = true;
            $done++;
        }
        foreach (array_keys($actIds) as $actId) {
            Acts2InRepository::recalcSumm((int)$actId);
        }
        return ['done' => $done, 'skipped' => $skipped];
    }
}
