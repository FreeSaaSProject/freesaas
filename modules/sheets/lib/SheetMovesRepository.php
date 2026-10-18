<?php
declare(strict_types=1);

final class SheetMovesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt' => 'DT',
            'summ' => 'SUMM',
            'id_account' => 'ID_ACCOUNT',
            'direction' => 'DIRECTION',
        ];
    }

    public static function balance(int $accountId): int
    {
        if ($accountId < 1 || !Database::tableExists('base_sheet_moves')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN DIRECTION > 0 THEN SUMM WHEN DIRECTION < 0 THEN -SUMM ELSE 0 END), 0)
             FROM base_sheet_moves WHERE ID_ACCOUNT = ? AND DELETED = 0'
        );
        $st->execute([$accountId]);
        return (int)$st->fetchColumn();
    }

    /**
     * @param array{id_account?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_sheet_moves')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['DELETED = 0'];
        $params = [];
        if (!empty($opts['id_account'])) {
            $where[] = 'ID_ACCOUNT = ?';
            $params[] = (int)$opts['id_account'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_sheet_moves WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_ACCOUNT AS id_account, ID_ACCOUNT_TO AS id_account_to,
                       ID_USER_CREATE AS id_user_create, ID_MOVEMENT_LINKED AS id_movement_linked,
                       DIRECTION AS direction, SUMM AS summ, DESCR AS descr, WEIGHT AS weight,
                       DT AS dt, DT_CREATE AS dt_create
                FROM base_sheet_moves WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_sheet_moves')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ACCOUNT AS id_account, ID_ACCOUNT_TO AS id_account_to,
                    ID_USER_CREATE AS id_user_create, ID_USER_UPDATE AS id_user_update,
                    ID_MOVEMENT_LINKED AS id_movement_linked, DIRECTION AS direction,
                    SUMM AS summ, DESCR AS descr, WEIGHT AS weight, DT AS dt,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update, DELETED AS deleted
             FROM base_sheet_moves WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $post */
    public static function dataFromPost(array $post): array
    {
        $idAccount = (int)($post['id_account'] ?? 0);
        if ($idAccount < 1) {
            throw new InvalidArgumentException(t('sheets.err.account_required'));
        }
        $direction = (int)($post['direction'] ?? 0);
        if (!in_array($direction, [-1, 0, 1], true)) {
            $direction = 0;
        }
        $summ = max(0, (int)($post['summ'] ?? 0));
        if ($summ < 1 && $direction !== 0) {
            throw new InvalidArgumentException(t('sheets.err.summ_required'));
        }
        return [
            'id_account' => $idAccount,
            'direction' => $direction,
            'summ' => $summ,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'weight' => (float)str_replace(',', '.', (string)($post['weight'] ?? '0')),
            'dt' => max(0, (int)($post['dt'] ?? time())) ?: time(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, int $userId): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_sheet_moves
             (ID_ACCOUNT, ID_ACCOUNT_TO, ID_USER_CREATE, ID_USER_UPDATE, ID_MOVEMENT_LINKED,
              DIRECTION, SUMM, DESCR, WEIGHT, DT, DT_CREATE, DT_UPDATE, DELETED)
             VALUES (?, 0, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $now = time();
        $st->execute([
            $data['id_account'],
            $userId,
            $userId,
            $data['direction'],
            $data['summ'],
            $data['descr'],
            $data['weight'],
            $data['dt'],
            $now,
            $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, int $userId): void
    {
        $existing = self::find($id);
        if ($existing && (int)($existing['id_movement_linked'] ?? 0) > 0) {
            throw new InvalidArgumentException(t('sheets.err.linked_use_pair'));
        }
        Database::pdo()->prepare(
            'UPDATE base_sheet_moves SET
             ID_ACCOUNT = ?, DIRECTION = ?, SUMM = ?, DESCR = ?, WEIGHT = ?, DT = ?,
             ID_USER_UPDATE = ?, DT_UPDATE = ?
             WHERE ID = ?'
        )->execute([
            $data['id_account'],
            $data['direction'],
            $data['summ'],
            $data['descr'],
            $data['weight'],
            $data['dt'],
            $userId,
            time(),
            $id,
        ]);
    }

    /**
     * @return array{from:array<string,mixed>,to:array<string,mixed>}|null
     */
    public static function findTransferPair(int $id): ?array
    {
        $row = self::find($id);
        if (!$row || (int)($row['deleted'] ?? 0) > 0) {
            return null;
        }
        $linked = (int)($row['id_movement_linked'] ?? 0);
        if ($linked < 1) {
            return null;
        }
        $twin = self::find($linked);
        if (!$twin || (int)($twin['deleted'] ?? 0) > 0) {
            return null;
        }
        if ((int)$row['direction'] < 0) {
            return ['from' => $row, 'to' => $twin];
        }
        if ((int)$twin['direction'] < 0) {
            return ['from' => $twin, 'to' => $row];
        }
        if ((int)$row['id'] <= (int)$twin['id']) {
            return ['from' => $row, 'to' => $twin];
        }
        return ['from' => $twin, 'to' => $row];
    }

    /**
     * Update both legs of a transfer (keeps pairing and matching SUMM).
     *
     * @param array{summ?:int,descr?:string,weight?:float,dt?:int,id_account?:int,id_account_to?:int} $data
     * @return array{from_id:int,to_id:int}
     */
    public static function updateTransferPair(int $eitherId, array $data, int $userId): array
    {
        $pair = self::findTransferPair($eitherId);
        if ($pair === null) {
            throw new InvalidArgumentException(t('sheets.err.not_found', ['id' => $eitherId]));
        }
        $from = $pair['from'];
        $to = $pair['to'];
        $fromAcc = (int)($data['id_account'] ?? $from['id_account']);
        $toAcc = (int)($data['id_account_to'] ?? ((int)$from['id_account_to'] > 0
            ? $from['id_account_to']
            : $to['id_account']));
        if ($fromAcc < 1 || $toAcc < 1) {
            throw new InvalidArgumentException(t('sheets.err.account_required'));
        }
        if ($fromAcc === $toAcc) {
            throw new InvalidArgumentException(t('sheets.err.accounts_same'));
        }
        $summ = max(1, (int)($data['summ'] ?? $from['summ']));
        $descr = array_key_exists('descr', $data)
            ? mb_substr((string)$data['descr'], 0, 255)
            : (string)$from['descr'];
        $weight = array_key_exists('weight', $data)
            ? (float)$data['weight']
            : (float)$from['weight'];
        $dt = max(0, (int)($data['dt'] ?? $from['dt'])) ?: time();
        $now = time();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE base_sheet_moves SET
                 ID_ACCOUNT = ?, ID_ACCOUNT_TO = ?, DIRECTION = -1, SUMM = ?, DESCR = ?, WEIGHT = ?, DT = ?,
                 ID_USER_UPDATE = ?, DT_UPDATE = ?
                 WHERE ID = ? AND DELETED = 0'
            )->execute([$fromAcc, $toAcc, $summ, $descr, $weight, $dt, $userId, $now, (int)$from['id']]);
            $pairDescr = t('sheets.transfer.pair_descr', ['id' => (int)$from['id'], 'descr' => $descr]);
            $pdo->prepare(
                'UPDATE base_sheet_moves SET
                 ID_ACCOUNT = ?, ID_ACCOUNT_TO = 0, DIRECTION = 1, SUMM = ?, DESCR = ?, WEIGHT = ?, DT = ?,
                 ID_USER_UPDATE = ?, DT_UPDATE = ?
                 WHERE ID = ? AND DELETED = 0'
            )->execute([
                $toAcc,
                $summ,
                mb_substr($pairDescr, 0, 255),
                $weight,
                $dt,
                $userId,
                $now,
                (int)$to['id'],
            ]);
            $pdo->commit();
            return ['from_id' => (int)$from['id'], 'to_id' => (int)$to['id']];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Transfer: debit source + credit destination (linked pair).
     *
     * @param array{id_account:int,id_account_to:int,summ:int,descr:string,weight:float,dt:int} $data
     */
    public static function createTransfer(array $data, int $userId): int
    {
        $from = (int)$data['id_account'];
        $to = (int)$data['id_account_to'];
        if ($from < 1 || $to < 1) {
            throw new InvalidArgumentException(t('sheets.err.account_required'));
        }
        if ($from === $to) {
            throw new InvalidArgumentException(t('sheets.err.accounts_same'));
        }
        $summ = max(1, (int)$data['summ']);
        $balance = self::balance($from);
        if ($summ > $balance) {
            throw new InvalidArgumentException(t('sheets.err.insufficient', ['left' => $balance]));
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $now = time();
            $dt = (int)($data['dt'] ?: $now);
            $descr = mb_substr((string)$data['descr'], 0, 255);
            $weight = (float)$data['weight'];
            $ins = $pdo->prepare(
                'INSERT INTO base_sheet_moves
                 (ID_ACCOUNT, ID_ACCOUNT_TO, ID_USER_CREATE, ID_USER_UPDATE, ID_MOVEMENT_LINKED,
                  DIRECTION, SUMM, DESCR, WEIGHT, DT, DT_CREATE, DT_UPDATE, DELETED)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            // Primary: debit from
            $ins->execute([$from, $to, $userId, $userId, -1, $summ, $descr, $weight, $dt, $now, $now]);
            $primaryId = (int)$pdo->lastInsertId();
            // Pair: credit to
            $pairDescr = t('sheets.transfer.pair_descr', ['id' => $primaryId, 'descr' => $descr]);
            $ins->execute([$to, 0, $userId, $userId, 1, $summ, mb_substr($pairDescr, 0, 255), $weight, $dt, $now, $now]);
            $pairId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE base_sheet_moves SET ID_MOVEMENT_LINKED = ? WHERE ID = ?')
                ->execute([$pairId, $primaryId]);
            $pdo->prepare('UPDATE base_sheet_moves SET ID_MOVEMENT_LINKED = ? WHERE ID = ?')
                ->execute([$primaryId, $pairId]);
            $pdo->commit();
            return $primaryId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Soft-delete move + linked pair. */
    public static function softDelete(int $id): void
    {
        $row = self::find($id);
        if (!$row) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE base_sheet_moves SET DELETED = 1 WHERE ID = ?')->execute([$id]);
        $linked = (int)$row['id_movement_linked'];
        if ($linked > 0) {
            $pdo->prepare('UPDATE base_sheet_moves SET DELETED = 1 WHERE ID = ?')->execute([$linked]);
        }
    }
}
