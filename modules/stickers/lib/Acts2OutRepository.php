<?php
declare(strict_types=1);

final class Acts2OutRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt' => 'DT',
            'id_stock' => 'ID_STOCK',
            'id_user' => 'ID_USER',
            'summ_real' => 'SUMM_REAL',
        ];
    }

    /**
     * @param array{q?:string,id_stock?:int|null,id_user?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_acts2_out')) {
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
        if (!empty($opts['id_user'])) {
            $where[] = 'ID_USER = ?';
            $params[] = (int)$opts['id_user'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_acts2_out WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_EVENT AS id_event, ID_PROC AS id_proc, ID_GOOD AS id_good,
                       ID_STOCK AS id_stock, ID_USER AS id_user, DESCR AS descr, DT AS dt,
                       SUMM_DEFAULT AS summ_default, SUMM_REAL AS summ_real,
                       ID_USER_SIGNED AS id_user_signed, IS_REVI AS is_revi,
                       THEREIS_INCOME AS thereis_income, IS_STRANGE AS is_strange
                FROM base_acts2_out WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_acts2_out')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_EVENT AS id_event, ID_PROC AS id_proc, ID_GOOD AS id_good,
                    ID_STOCK AS id_stock, ID_USER AS id_user, DESCR AS descr, DT AS dt,
                    DEFAULT_WARNING AS default_warning, ID_USER_SIGNED AS id_user_signed,
                    DT_USER_SIGNED AS dt_user_signed, SUMM_DEFAULT AS summ_default,
                    SUMM_REAL AS summ_real, IS_REVI AS is_revi, THEREIS_INCOME AS thereis_income,
                    IS_STRANGE AS is_strange
             FROM base_acts2_out WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Free daily writeoff act for operator+stock (oper bot parity).
     */
    public static function getOrCreateFreeAct(int $userId, int $stockId): int
    {
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00'));
        $dayEnd = (int)strtotime(date('Y-m-d 23:59:59'));
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_acts2_out
             WHERE ID_USER = ? AND ID_STOCK = ? AND ID_EVENT = 0 AND ID_PROC = 0
               AND DT BETWEEN ? AND ? AND ID_USER_SIGNED = 0
             ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$userId, $stockId, $dayStart, $dayEnd]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $ins = Database::pdo()->prepare(
            'INSERT INTO base_acts2_out
             (ID_EVENT, ID_PROC, ID_GOOD, ID_STOCK, ID_USER, DESCR, DT,
              DEFAULT_WARNING, ID_USER_SIGNED, DT_USER_SIGNED, SUMM_DEFAULT, SUMM_REAL,
              ID_COSM_CONNECTED, ID_COSM_SIGNED, DT_COSM_SIGNED, IS_REVI, THEREIS_INCOME, IS_STRANGE)
             VALUES (0, 0, 0, ?, ?, ?, ?, \'\', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)'
        );
        $ins->execute([
            $stockId,
            $userId,
            t('stickers.writeoff.auto_act_descr'),
            time(),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $post */
    public static function dataFromPost(array $post): array
    {
        $idStock = (int)($post['id_stock'] ?? 0);
        if ($idStock < 1) {
            throw new InvalidArgumentException(t('stickers.err.stock_required'));
        }
        return [
            'id_stock' => $idStock,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 2000),
            'id_event' => max(0, (int)($post['id_event'] ?? 0)),
            'id_proc' => max(0, (int)($post['id_proc'] ?? 0)),
            'id_good' => max(0, (int)($post['id_good'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, int $userId): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_acts2_out
             (ID_EVENT, ID_PROC, ID_GOOD, ID_STOCK, ID_USER, DESCR, DT,
              DEFAULT_WARNING, ID_USER_SIGNED, DT_USER_SIGNED, SUMM_DEFAULT, SUMM_REAL,
              ID_COSM_CONNECTED, ID_COSM_SIGNED, DT_COSM_SIGNED, IS_REVI, THEREIS_INCOME, IS_STRANGE)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'\', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)'
        );
        $st->execute([
            $data['id_event'],
            $data['id_proc'],
            $data['id_good'],
            $data['id_stock'],
            $userId,
            $data['descr'],
            time(),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_acts2_out SET ID_STOCK = ?, DESCR = ?, ID_EVENT = ?, ID_PROC = ?, ID_GOOD = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['id_stock'],
            $data['descr'],
            $data['id_event'],
            $data['id_proc'],
            $data['id_good'],
            $id,
        ]);
    }
}
