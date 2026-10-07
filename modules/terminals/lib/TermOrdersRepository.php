<?php
declare(strict_types=1);

final class TermOrdersRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt_create' => 'DT_CREATE',
            'dt_visit' => 'DT_VISIT',
            'phone' => 'PHONE',
            'total_summ' => 'TOTAL_SUMM',
        ];
    }

    /**
     * @param array{day?:string,from?:string,to?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_term_orders')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $day = trim((string)($opts['day'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $from = (int)strtotime($day . ' 00:00:00');
            $where[] = 'DT_VISIT BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $from + 86399;
        } else {
            $fromS = trim((string)($opts['from'] ?? ''));
            $toS = trim((string)($opts['to'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromS)) {
                $where[] = 'DT_VISIT >= ?';
                $params[] = (int)strtotime($fromS . ' 00:00:00');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toS)) {
                $where[] = 'DT_VISIT <= ?';
                $params[] = (int)strtotime($toS . ' 23:59:59');
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_term_orders WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DT_CREATE AS dt_create, DT_VISIT AS dt_visit,
                       DAYS_BEFORE AS days_before, HOUR_VISIT AS hour_visit, PHONE AS phone,
                       ADULTS AS adults, CHILDREN AS children, ELDERS AS elders,
                       TOTAL AS total, SERT_SUMM AS sert_summ, TOTAL_SUMM AS total_summ
                FROM base_term_orders WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function lastId(): int
    {
        if (!Database::tableExists('base_term_orders')) {
            return 0;
        }
        $v = Database::pdo()->query('SELECT ID FROM base_term_orders ORDER BY ID DESC LIMIT 1')->fetchColumn();
        return (int)$v;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function insertRow(array $row): int
    {
        $id = (int)($row['id'] ?? 0);
        $vals = [
            (int)($row['dt_create'] ?? 0),
            (int)($row['dt_visit'] ?? 0),
            (int)($row['days_before'] ?? 0),
            (int)($row['hour_visit'] ?? 0),
            substr((string)($row['phone'] ?? ''), 0, 11),
            (int)($row['adults'] ?? 0),
            (int)($row['children'] ?? 0),
            (int)($row['elders'] ?? 0),
            (int)($row['total'] ?? 0),
            (int)($row['sert_summ'] ?? 0),
            (int)($row['total_summ'] ?? 0),
        ];
        if ($id > 0) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_term_orders
                 (ID, DT_CREATE, DT_VISIT, DAYS_BEFORE, HOUR_VISIT, PHONE,
                  ADULTS, CHILDREN, ELDERS, TOTAL, SERT_SUMM, TOTAL_SUMM)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   DT_CREATE = VALUES(DT_CREATE), DT_VISIT = VALUES(DT_VISIT),
                   DAYS_BEFORE = VALUES(DAYS_BEFORE), HOUR_VISIT = VALUES(HOUR_VISIT),
                   PHONE = VALUES(PHONE), ADULTS = VALUES(ADULTS), CHILDREN = VALUES(CHILDREN),
                   ELDERS = VALUES(ELDERS), TOTAL = VALUES(TOTAL), SERT_SUMM = VALUES(SERT_SUMM),
                   TOTAL_SUMM = VALUES(TOTAL_SUMM)'
            );
            $st->execute(array_merge([$id], $vals));
            return $id;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_term_orders
             (DT_CREATE, DT_VISIT, DAYS_BEFORE, HOUR_VISIT, PHONE,
              ADULTS, CHILDREN, ELDERS, TOTAL, SERT_SUMM, TOTAL_SUMM)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute($vals);
        return (int)Database::pdo()->lastInsertId();
    }
}
