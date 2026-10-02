<?php
declare(strict_types=1);

final class FundMovementsRepository
{
    /** CAPUSE=-1, REFUND=+1, REVAL=-1, DEVAL=+1, DESTROY=+1 */
    public const TYPE_CAPUSE = 1;
    public const TYPE_REFUND = 2;
    public const TYPE_REVAL = 3;
    public const TYPE_DEVAL = 4;
    public const TYPE_DESTROY = 5;

    /** @return array<int,int> type => direction */
    public static function typeDirections(): array
    {
        return [
            self::TYPE_CAPUSE => -1,
            self::TYPE_REFUND => 1,
            self::TYPE_REVAL => -1,
            self::TYPE_DEVAL => 1,
            self::TYPE_DESTROY => 1,
        ];
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'fund' => 'ID_FUND', 'type' => 'ID_TYPE', 'sum' => 'SUMM'];
    }

    public static function fundBalance(int $fundId): float
    {
        if ($fundId < 1 || !Database::tableExists('base_fund_movements')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(DIRECTION * SUMM), 0) FROM base_fund_movements WHERE ID_FUND = ?'
        );
        $st->execute([$fundId]);
        return (float)$st->fetchColumn();
    }

    /**
     * @param array{
     *   q?:string,fund_id?:int,type_id?:int,fund_type?:int,
     *   year?:int,month?:int,year_e?:int,month_e?:int,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_fund_movements')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(fm.NAME LIKE ? OR fm.DESCR LIKE ? OR CAST(fm.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $fundId = (int)($opts['fund_id'] ?? 0);
        if ($fundId > 0) {
            $where[] = 'fm.ID_FUND = ?';
            $params[] = $fundId;
        }
        $typeId = (int)($opts['type_id'] ?? 0);
        if ($typeId > 0) {
            $where[] = 'fm.ID_TYPE = ?';
            $params[] = $typeId;
        }
        $fundType = (int)($opts['fund_type'] ?? 0);
        $join = '';
        if ($fundType > 0 && Database::tableExists('base_funds')) {
            $join = ' INNER JOIN base_funds f ON f.ID = fm.ID_FUND ';
            $where[] = 'f.TYPE = ?';
            $params[] = $fundType;
        }
        $year = (int)($opts['year'] ?? 0);
        $month = (int)($opts['month'] ?? 0);
        $yearE = (int)($opts['year_e'] ?? $year);
        $monthE = (int)($opts['month_e'] ?? $month);
        if ($year > 0) {
            if ($month > 0) {
                $start = mktime(0, 0, 0, $month, 1, $year) ?: 0;
            } else {
                $start = mktime(0, 0, 0, 1, 1, $year) ?: 0;
            }
            if ($yearE > 0 && $monthE > 0) {
                $end = mktime(23, 59, 59, $monthE + 1, 0, $yearE) ?: 0;
            } elseif ($yearE > 0) {
                $end = mktime(23, 59, 59, 12, 31, $yearE) ?: 0;
            } elseif ($month > 0) {
                $end = mktime(23, 59, 59, $month + 1, 0, $year) ?: 0;
            } else {
                $end = mktime(23, 59, 59, 12, 31, $year) ?: 0;
            }
            $where[] = 'fm.DT_AMORT_START >= ? AND fm.DT_AMORT_START <= ?';
            $params[] = $start;
            $params[] = $end;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_fund_movements fm $join WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'fm.ID DESC';
        // qualify bare column order from ListQuery map
        if ($order !== '' && strpos($order, '.') === false) {
            $order = preg_replace('/\b(ID|ID_FUND|ID_TYPE|SUMM)\b/', 'fm.$1', $order) ?: $order;
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT fm.ID AS id, fm.ID_FUND AS fund_id, fm.ID_TYPE AS type_id, fm.DIRECTION AS direction,
                       fm.SUMM AS summ, fm.NAME AS name, fm.DESCR AS descr, fm.DT_CREATE AS dt_create,
                       fm.ID_USER_CREATE AS user_create
                FROM base_fund_movements fm $join WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fund_movements')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_FUND AS fund_id, ID_TYPE AS type_id, DIRECTION AS direction,
                    SUMM AS summ, SUMM_OUT_CAP AS summ_out_cap, SUMM_OUT_FUND AS summ_out_fund,
                    SUMM_OUT_INFL AS summ_out_infl, SUMM_OUT_DESTROY AS summ_out_destroy,
                    NAME AS name, DESCR AS descr, DT_CREATE AS dt_create, DT_AMORT_START AS dt_amort_start,
                    AMORT_MONTHS AS amort_months, ID_USER_CREATE AS user_create
             FROM base_fund_movements WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $fundId = (int)($post['fund_id'] ?? 0);
        if ($fundId < 1) {
            throw new InvalidArgumentException(t('funds.moves.err.fund'));
        }
        $typeId = (int)($post['type_id'] ?? 0);
        $dirs = self::typeDirections();
        if (!isset($dirs[$typeId])) {
            throw new InvalidArgumentException(t('funds.moves.err.type'));
        }
        $sum = (float)str_replace(',', '.', (string)($post['summ'] ?? '0'));
        if ($sum <= 0) {
            throw new InvalidArgumentException(t('funds.moves.err.sum'));
        }
        $sumOutCap = (float)str_replace(',', '.', (string)($post['summ_out_cap'] ?? '0'));
        $sumOutFund = (float)str_replace(',', '.', (string)($post['summ_out_fund'] ?? '0'));
        $sumOutInfl = (float)str_replace(',', '.', (string)($post['summ_out_infl'] ?? '0'));
        $sumOutDestroy = (float)str_replace(',', '.', (string)($post['summ_out_destroy'] ?? '0'));
        if ($typeId === self::TYPE_REVAL && $sumOutInfl <= 0) {
            $sumOutInfl = $sum;
        }
        if ($typeId === self::TYPE_DESTROY && $sumOutDestroy <= 0) {
            $sumOutDestroy = $sum;
        }
        $amortStart = trim((string)($post['dt_amort_start'] ?? ''));
        $dtAmort = $amortStart !== '' ? (strtotime($amortStart) ?: time()) : time();
        return [
            'fund_id' => $fundId,
            'type_id' => $typeId,
            'direction' => $dirs[$typeId],
            'summ' => $sum,
            'summ_out_cap' => max(0.0, $sumOutCap),
            'summ_out_fund' => max(0.0, $sumOutFund),
            'summ_out_infl' => max(0.0, $sumOutInfl),
            'summ_out_destroy' => max(0.0, $sumOutDestroy),
            'name' => trim((string)($post['name'] ?? '')),
            'descr' => trim((string)($post['descr'] ?? '')),
            'dt_amort_start' => $dtAmort,
            'amort_months' => (int)($post['amort_months'] ?? 0),
            'dt_create' => time(),
            'user_create' => Auth::userId(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_fund_movements
             (ID_FUND, ID_TYPE, DIRECTION, SUMM, SUMM_OUT_CAP, SUMM_OUT_FUND, SUMM_OUT_INFL, SUMM_OUT_DESTROY,
              NAME, DESCR, DT_AMORT_START, DT_CREATE, ID_USER_CREATE, AMORT_MONTHS)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['fund_id'], $data['type_id'], $data['direction'], $data['summ'],
            $data['summ_out_cap'], $data['summ_out_fund'], $data['summ_out_infl'], $data['summ_out_destroy'],
            $data['name'], $data['descr'], $data['dt_amort_start'], $data['dt_create'], $data['user_create'],
            $data['amort_months'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_fund_movements SET
             ID_FUND = ?, ID_TYPE = ?, DIRECTION = ?, SUMM = ?,
             SUMM_OUT_CAP = ?, SUMM_OUT_FUND = ?, SUMM_OUT_INFL = ?, SUMM_OUT_DESTROY = ?,
             NAME = ?, DESCR = ?, DT_AMORT_START = ?, AMORT_MONTHS = ?,
             ID_USER_UPDATE = ?, DT_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['fund_id'], $data['type_id'], $data['direction'], $data['summ'],
            $data['summ_out_cap'], $data['summ_out_fund'], $data['summ_out_infl'], $data['summ_out_destroy'],
            $data['name'], $data['descr'], $data['dt_amort_start'], $data['amort_months'],
            Auth::userId(), time(), $id,
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
        $st = Database::pdo()->prepare("DELETE FROM base_fund_movements WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    public static function typeLabel(int $type): string
    {
        $key = 'funds.moves.type.' . $type;
        $label = t($key);
        return $label !== $key ? $label : ('#' . $type);
    }
}
