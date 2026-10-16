<?php
declare(strict_types=1);

final class FullstatsRepository
{
    /** @var array<string,list<array<string,mixed>>> */
    private static $forDayCache = [];

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dtstart' => 'DTSTART',
            'dtend' => 'DTEND',
            'ticket_type' => 'TICKET_TYPE',
            'id_type' => 'ID_TYPE',
        ];
    }

    /**
     * @param array{day?:string,from?:string,to?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_fullstats')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        [$from, $to] = self::rangeFromOpts($opts);
        if ($from > 0) {
            $where[] = 'DTSTART >= ?';
            $params[] = $from;
        }
        if ($to > 0) {
            $where[] = 'DTSTART <= ?';
            $params[] = $to;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_fullstats WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'DTSTART DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DTCREATE AS dtcreate, DTSTART AS dtstart, DTEND AS dtend,
                       DURATION AS duration, ID1C_USER AS id1c_user, TITLE_FULL AS title_full,
                       TICKET_TYPE AS ticket_type, ID_TYPE AS id_type,
                       IS_LIMITED AS is_limited, IS_PAYER AS is_payer
                FROM base_fullstats WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /**
     * All ticket visits for a calendar day (no LIMIT).
     *
     * @return list<array<string,mixed>>
     */
    public static function forDay(string $day): array
    {
        $day = trim($day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !Database::tableExists('base_fullstats')) {
            return [];
        }
        if (isset(self::$forDayCache[$day])) {
            return self::$forDayCache[$day];
        }
        $res = self::list([
            'day' => $day,
            'limit' => 0,
            'order' => 'DTSTART ASC',
        ]);
        return self::$forDayCache[$day] = $res['items'];
    }

    /**
     * Prefetch ticket visits for many days in one query (week enrich).
     *
     * @param list<string> $days Y-m-d
     * @return array<string,list<array<string,mixed>>>
     */
    public static function forDays(array $days): array
    {
        $normalized = [];
        foreach ($days as $d) {
            $d = trim((string)$d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $normalized[$d] = $d;
            }
        }
        $normalized = array_values($normalized);
        if ($normalized === [] || !Database::tableExists('base_fullstats')) {
            return [];
        }
        sort($normalized);
        $missing = [];
        foreach ($normalized as $d) {
            if (!isset(self::$forDayCache[$d])) {
                $missing[] = $d;
            }
        }
        if ($missing !== []) {
            $from = $missing[0];
            $to = $missing[count($missing) - 1];
            $res = self::list([
                'from' => $from,
                'to' => $to,
                'limit' => 0,
                'order' => 'DTSTART ASC',
            ]);
            $byDay = [];
            foreach ($res['items'] as $row) {
                $ts = (int)($row['dtstart'] ?? 0);
                if ($ts < 1) {
                    continue;
                }
                $d = date('Y-m-d', $ts);
                $byDay[$d][] = $row;
            }
            foreach ($missing as $d) {
                self::$forDayCache[$d] = $byDay[$d] ?? [];
            }
        }
        $out = [];
        foreach ($normalized as $d) {
            $out[$d] = self::$forDayCache[$d] ?? [];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_fullstats')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DTCREATE AS dtcreate, DTSTART AS dtstart, DTEND AS dtend,
                    DURATION AS duration, ID1C_USER AS id1c_user, TITLE_FULL AS title_full,
                    TICKET_TYPE AS ticket_type, ID_TYPE AS id_type,
                    IS_LIMITED AS is_limited, IS_PAYER AS is_payer
             FROM base_fullstats WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Classify this row and propagate flags to all rows with the same TICKET_TYPE.
     *
     * @param array<string,mixed> $post
     */
    public static function classify(int $id, array $post): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new InvalidArgumentException(t('terminals.err.fullstat_not_found', ['id' => $id]));
        }
        $idType = max(0, (int)($post['id_type'] ?? 0));
        $isLimited = !empty($post['is_limited']) ? 1 : 0;
        $isPayer = !empty($post['is_payer']) ? 1 : 0;
        $ticketType = (string)$row['ticket_type'];
        $st = Database::pdo()->prepare(
            'UPDATE base_fullstats SET ID_TYPE = ?, IS_LIMITED = ?, IS_PAYER = ?
             WHERE TICKET_TYPE = ?'
        );
        $st->execute([$idType, $isLimited, $isPayer, $ticketType]);
    }

    /**
     * Replace today's imported rows (oper tickets1c day wipe + insert).
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function replaceDay(int $dayStart, array $rows): int
    {
        if (!Database::tableExists('base_fullstats')) {
            return 0;
        }
        $dayEnd = $dayStart + 86400 - 1;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM base_fullstats WHERE DTSTART BETWEEN ? AND ?');
            $del->execute([$dayStart, $dayEnd]);
            $ins = $pdo->prepare(
                'INSERT INTO base_fullstats
                 (DTCREATE, DTSTART, DTEND, DURATION, ID1C_USER, TITLE_FULL, TICKET_TYPE, ID_TYPE, IS_LIMITED, IS_PAYER)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $n = 0;
            $now = time();
            foreach ($rows as $r) {
                $ins->execute([
                    (int)($r['dtcreate'] ?? $now),
                    (int)($r['dtstart'] ?? 0),
                    (int)($r['dtend'] ?? 0),
                    (int)($r['duration'] ?? 0),
                    (string)($r['id1c_user'] ?? ''),
                    (string)($r['title_full'] ?? ''),
                    (string)($r['ticket_type'] ?? ''),
                    (int)($r['id_type'] ?? 0),
                    (int)($r['is_limited'] ?? 0),
                    (int)($r['is_payer'] ?? 0),
                ]);
                $n++;
            }
            $pdo->commit();
            return $n;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array{day?:string,from?:string,to?:string} $opts
     * @return array{0:int,1:int}
     */
    private static function rangeFromOpts(array $opts): array
    {
        $day = trim((string)($opts['day'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $from = (int)strtotime($day . ' 00:00:00');
            return [$from, $from + 86399];
        }
        $fromS = trim((string)($opts['from'] ?? ''));
        $toS = trim((string)($opts['to'] ?? ''));
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromS) ? (int)strtotime($fromS . ' 00:00:00') : 0;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toS) ? (int)strtotime($toS . ' 23:59:59') : 0;
        return [$from, $to];
    }
}
