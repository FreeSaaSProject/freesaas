<?php
declare(strict_types=1);

final class FullstatsRepository
{
    /** @var array<string,list<array<string,mixed>>> */
    private static $forDayCache = [];

    /** @var bool|null */
    private static $hasOasis = null;

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

    public static function hasOasisColumn(): bool
    {
        if (self::$hasOasis !== null) {
            return self::$hasOasis;
        }
        if (!Database::tableExists('base_fullstats')) {
            return self::$hasOasis = false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute(['base_fullstats', 'IS_OASIS']);
        return self::$hasOasis = (bool)$st->fetchColumn();
    }

    /**
     * @param array{day?:string,from?:string,to?:string,mark?:string,q?:string,order?:string,limit?:int,offset?:int} $opts
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
        $mark = trim((string)($opts['mark'] ?? ''));
        // IS_OASIS=0 is provisional "not oasis" (mass 0→2 is chunked Cron/LongJob);
        // do not treat it as unclassified — otherwise every row stays dirty until backfill.
        if ($mark === 'unclassified') {
            $where[] = '(ID_TYPE = 0 OR IS_LIMITED = 0 OR IS_PAYER = 0)';
        } elseif ($mark === 'classified') {
            $where[] = '(ID_TYPE > 0 AND IS_LIMITED > 0 AND IS_PAYER > 0)';
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(TICKET_TYPE LIKE ? OR TITLE_FULL LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
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
        $oasisSel = $hasOasis ? ', IS_OASIS AS is_oasis' : ', 0 AS is_oasis';
        $sql = "SELECT ID AS id, DTCREATE AS dtcreate, DTSTART AS dtstart, DTEND AS dtend,
                       DURATION AS duration, ID1C_USER AS id1c_user, TITLE_FULL AS title_full,
                       TICKET_TYPE AS ticket_type, ID_TYPE AS id_type,
                       IS_LIMITED AS is_limited, IS_PAYER AS is_payer
                       $oasisSel
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
        $oasisSel = self::hasOasisColumn() ? ', IS_OASIS AS is_oasis' : ', 0 AS is_oasis';
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DTCREATE AS dtcreate, DTSTART AS dtstart, DTEND AS dtend,
                    DURATION AS duration, ID1C_USER AS id1c_user, TITLE_FULL AS title_full,
                    TICKET_TYPE AS ticket_type, ID_TYPE AS id_type,
                    IS_LIMITED AS is_limited, IS_PAYER AS is_payer
                    ' . $oasisSel . '
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
        self::classifyByType(
            (string)$row['ticket_type'],
            (int)($post['id_type'] ?? 0),
            (int)($post['is_limited'] ?? 0),
            (int)($post['is_payer'] ?? 0),
            (int)($post['is_oasis'] ?? 0)
        );
    }

    /**
     * Apply classification to every row with the given TICKET_TYPE.
     *
     * @return int rows affected
     */
    public static function classifyByType(
        string $ticketType,
        int $idType,
        int $isLimited,
        int $isPayer,
        int $isOasis = 0
    ): int {
        $ticketType = trim($ticketType);
        if ($ticketType === '' || !Database::tableExists('base_fullstats')) {
            return 0;
        }
        $idType = TicketTypeEnums::normalizeIdType($idType);
        $isLimited = TicketTypeEnums::normalizeIsLimited($isLimited);
        $isPayer = TicketTypeEnums::normalizeIsPayer($isPayer);
        $isOasis = TicketTypeEnums::normalizeIsOasis($isOasis);
        // Child → never payer; adult/senior → always payer.
        if ($idType === 1) {
            $isPayer = 2;
        } elseif ($idType === 2 || $idType === 3) {
            $isPayer = 1;
        }
        if (self::hasOasisColumn()) {
            // Oasis=1 only inside Sep–Nov 2026 window; outside always "not oasis".
            if ($isOasis === 1) {
                $st = Database::pdo()->prepare(
                    'UPDATE base_fullstats
                     SET ID_TYPE = ?, IS_LIMITED = ?, IS_PAYER = ?,
                         IS_OASIS = IF(DTSTART >= ? AND DTSTART < ?, 1, 2)
                     WHERE TICKET_TYPE = ?'
                );
                $st->execute([
                    $idType,
                    $isLimited,
                    $isPayer,
                    TicketTypeEnums::oasisWindowStart(),
                    TicketTypeEnums::oasisWindowEndExclusive(),
                    $ticketType,
                ]);
            } else {
                $st = Database::pdo()->prepare(
                    'UPDATE base_fullstats SET ID_TYPE = ?, IS_LIMITED = ?, IS_PAYER = ?, IS_OASIS = ?
                     WHERE TICKET_TYPE = ?'
                );
                $st->execute([$idType, $isLimited, $isPayer, $isOasis, $ticketType]);
            }
        } else {
            $st = Database::pdo()->prepare(
                'UPDATE base_fullstats SET ID_TYPE = ?, IS_LIMITED = ?, IS_PAYER = ?
                 WHERE TICKET_TYPE = ?'
            );
            $st->execute([$idType, $isLimited, $isPayer, $ticketType]);
        }
        self::clearDayCache();
        return $st->rowCount();
    }

    /**
     * Unique unclassified ticket types (oper-style group for classify wizard).
     *
     * @return list<array{ticket_type:string,title_sample:string,cnt:int,sample_id:int,avg_min:float,max_min:int}>
     */
    public static function unclassifiedTypes(string $q = ''): array
    {
        if (!Database::tableExists('base_fullstats')) {
            return [];
        }
        $visitMin = 'CASE
            WHEN DURATION > 0 AND DURATION <= 1440 THEN DURATION
            WHEN DTEND > DTSTART THEN ROUND((DTEND - DTSTART) / 60)
            ELSE NULL
        END';
        // Oasis 0 = provisional not-oasis; classify only on type/limit/payer.
        $where = ['(ID_TYPE = 0 OR IS_LIMITED = 0 OR IS_PAYER = 0)', "TICKET_TYPE <> ''"];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(TICKET_TYPE LIKE ? OR TITLE_FULL LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $sql = "SELECT TICKET_TYPE AS ticket_type,
                       MIN(TITLE_FULL) AS title_sample,
                       COUNT(*) AS cnt,
                       MIN(ID) AS sample_id,
                       AVG($visitMin) AS avg_min,
                       MAX($visitMin) AS max_min
                FROM base_fullstats
                WHERE $sqlWhere
                GROUP BY TICKET_TYPE
                ORDER BY TICKET_TYPE ASC";
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ticket_type' => (string)($r['ticket_type'] ?? ''),
                'title_sample' => (string)($r['title_sample'] ?? ''),
                'cnt' => (int)($r['cnt'] ?? 0),
                'sample_id' => (int)($r['sample_id'] ?? 0),
                'avg_min' => round((float)($r['avg_min'] ?? 0), 1),
                'max_min' => (int)round((float)($r['max_min'] ?? 0)),
            ];
        }
        return $out;
    }

    /**
     * Replace imported rows for a calendar day (oper tickets1c wipe + insert).
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function replaceDay(int $dayStart, array $rows, bool $copyClassification = true): int
    {
        if (!Database::tableExists('base_fullstats') || $rows === []) {
            return 0;
        }
        $dayEnd = $dayStart + 86400 - 1;
        $pdo = Database::pdo();
        $hasOasis = self::hasOasisColumn();
        $classByType = [];
        if ($copyClassification) {
            $types = [];
            foreach ($rows as $r) {
                $t = trim((string)($r['ticket_type'] ?? ''));
                if ($t !== '') {
                    $types[$t] = true;
                }
            }
            foreach (array_keys($types) as $ticketType) {
                $classByType[$ticketType] = self::classificationForType($ticketType);
            }
        }
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM base_fullstats WHERE DTSTART BETWEEN ? AND ?');
            $del->execute([$dayStart, $dayEnd]);
            if ($hasOasis) {
                $ins = $pdo->prepare(
                    'INSERT INTO base_fullstats
                     (DTCREATE, DTSTART, DTEND, DURATION, ID1C_USER, TITLE_FULL, TICKET_TYPE, ID_TYPE, IS_LIMITED, IS_PAYER, IS_OASIS)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO base_fullstats
                     (DTCREATE, DTSTART, DTEND, DURATION, ID1C_USER, TITLE_FULL, TICKET_TYPE, ID_TYPE, IS_LIMITED, IS_PAYER)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
            }
            $n = 0;
            $now = time();
            foreach ($rows as $r) {
                $ticketType = (string)($r['ticket_type'] ?? '');
                $idType = (int)($r['id_type'] ?? 0);
                $isLimited = (int)($r['is_limited'] ?? 0);
                $isPayer = (int)($r['is_payer'] ?? 0);
                $isOasis = (int)($r['is_oasis'] ?? 0);
                if ($copyClassification && $ticketType !== '' && isset($classByType[$ticketType])) {
                    $c = $classByType[$ticketType];
                    if ($c !== null) {
                        $idType = (int)$c['id_type'];
                        $isLimited = (int)$c['is_limited'];
                        $isPayer = (int)$c['is_payer'];
                        $isOasis = (int)($c['is_oasis'] ?? 0);
                    }
                }
                $vals = [
                    (int)($r['dtcreate'] ?? $now),
                    (int)($r['dtstart'] ?? 0),
                    (int)($r['dtend'] ?? 0),
                    (int)($r['duration'] ?? 0),
                    (string)($r['id1c_user'] ?? ''),
                    (string)($r['title_full'] ?? ''),
                    $ticketType,
                    $idType,
                    $isLimited,
                    $isPayer,
                ];
                if ($hasOasis) {
                    // Fresh imports: unknown oasis → not oasis (chunked backfill same rule).
                    $vals[] = $isOasis > 0 ? $isOasis : 2;
                }
                $ins->execute($vals);
                $n++;
            }
            $pdo->commit();
            self::clearDayCache();
            return $n;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Prior classification flags for a ticket type, if any.
     *
     * @return array{id_type:int,is_limited:int,is_payer:int,is_oasis:int}|null
     */
    public static function classificationForType(string $ticketType): ?array
    {
        $ticketType = trim($ticketType);
        if ($ticketType === '' || !Database::tableExists('base_fullstats')) {
            return null;
        }
        $hasOasis = self::hasOasisColumn();
        $oasisSel = $hasOasis ? ', IS_OASIS AS is_oasis' : ', 0 AS is_oasis';
        $mark = $hasOasis
            ? '(ID_TYPE > 0 OR IS_LIMITED > 0 OR IS_PAYER > 0 OR IS_OASIS > 0)'
            : '(ID_TYPE > 0 OR IS_LIMITED > 0 OR IS_PAYER > 0)';
        $st = Database::pdo()->prepare(
            "SELECT ID_TYPE AS id_type, IS_LIMITED AS is_limited, IS_PAYER AS is_payer
                    $oasisSel
             FROM base_fullstats
             WHERE TICKET_TYPE = ?
               AND $mark
             ORDER BY ID DESC
             LIMIT 1"
        );
        $st->execute([$ticketType]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id_type' => (int)$row['id_type'],
            'is_limited' => (int)$row['is_limited'],
            'is_payer' => (int)$row['is_payer'],
            'is_oasis' => (int)($row['is_oasis'] ?? 0),
        ];
    }

    public static function clearDayCache(): void
    {
        self::$forDayCache = [];
        self::$hasOasis = null;
    }

    /**
     * Mark Sep–Nov 2026 visits with oasis in the name (cheap; uses DTSTART range).
     */
    public static function ensureOasisNamedInWindow(): int
    {
        if (!self::hasOasisColumn()) {
            return 0;
        }
        $upd = Database::pdo()->prepare(
            'UPDATE base_fullstats SET IS_OASIS = 1
             WHERE DTSTART >= ? AND DTSTART < ?
               AND IS_OASIS <> 1
               AND (
                 TICKET_TYPE LIKE ?
                 OR TICKET_TYPE LIKE ?
                 OR TITLE_FULL LIKE ?
                 OR TITLE_FULL LIKE ?
               )'
        );
        $upd->execute([
            TicketTypeEnums::oasisWindowStart(),
            TicketTypeEnums::oasisWindowEndExclusive(),
            '%оазис%',
            '%oasis%',
            '%оазис%',
            '%oasis%',
        ]);
        return (int)$upd->rowCount();
    }

    /**
     * One crumb of IS_OASIS 0→2 (not oasis). Safe for UI/Cron ticks (~25s budget).
     *
     * @return array{updated:int,remaining:int,named:int,done:bool}
     */
    public static function backfillOasisNotChunk(int $limit = 25000): array
    {
        if (!self::hasOasisColumn()) {
            return ['updated' => 0, 'remaining' => 0, 'named' => 0, 'done' => true];
        }
        $limit = max(1000, min(100000, $limit));
        $pdo = Database::pdo();
        // Prefer naming oasis rows before flipping remaining zeros to "not".
        $named = self::ensureOasisNamedInWindow();
        $updated = (int)$pdo->exec(
            'UPDATE base_fullstats SET IS_OASIS = 2
             WHERE IS_OASIS = 0
             LIMIT ' . $limit
        );
        $remaining = (int)$pdo->query(
            'SELECT COUNT(*) FROM base_fullstats WHERE IS_OASIS = 0'
        )->fetchColumn();
        return [
            'updated' => $updated,
            'remaining' => $remaining,
            'named' => $named,
            'done' => $remaining < 1,
        ];
    }

    /** Rows still pending IS_OASIS 0→2 backfill. */
    public static function oasisBackfillRemaining(): int
    {
        if (!self::hasOasisColumn()) {
            return 0;
        }
        return (int)Database::pdo()->query(
            'SELECT COUNT(*) FROM base_fullstats WHERE IS_OASIS = 0'
        )->fetchColumn();
    }

    /**
     * Per-day ticket buckets for calendar footer (one query for the range).
     * Oasis = IS_OASIS=1; adult/child = non-oasis + ID_TYPE 3/1.
     *
     * @param list<string> $days Y-m-d
     * @return array<string,array{oasis:int,adult:int,child:int}>
     */
    public static function dayTypeCounts(array $days): array
    {
        $normalized = [];
        foreach ($days as $d) {
            $d = trim((string)$d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $normalized[$d] = $d;
            }
        }
        $normalized = array_values($normalized);
        $out = [];
        foreach ($normalized as $d) {
            $out[$d] = ['oasis' => 0, 'adult' => 0, 'child' => 0];
        }
        if ($normalized === [] || !Database::tableExists('base_fullstats')) {
            return $out;
        }
        sort($normalized);
        $from = (int)strtotime($normalized[0] . ' 00:00:00');
        $toExcl = (int)strtotime($normalized[count($normalized) - 1] . ' 00:00:00') + 86400;
        if ($from < 1 || $toExcl <= $from) {
            return $out;
        }

        $hasOasis = self::hasOasisColumn();
        if ($hasOasis) {
            $sql = 'SELECT FROM_UNIXTIME(DTSTART, \'%Y-%m-%d\') AS d,
                           SUM(IS_OASIS = 1) AS oasis,
                           SUM(IS_OASIS <> 1 AND ID_TYPE = 3) AS adult,
                           SUM(IS_OASIS <> 1 AND ID_TYPE = 1) AS child
                    FROM base_fullstats
                    WHERE DTSTART >= ? AND DTSTART < ?
                    GROUP BY d';
        } else {
            $sql = 'SELECT FROM_UNIXTIME(DTSTART, \'%Y-%m-%d\') AS d,
                           0 AS oasis,
                           SUM(ID_TYPE = 3) AS adult,
                           SUM(ID_TYPE = 1) AS child
                    FROM base_fullstats
                    WHERE DTSTART >= ? AND DTSTART < ?
                    GROUP BY d';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$from, $toExcl]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $d = (string)($row['d'] ?? '');
            if ($d === '' || !isset($out[$d])) {
                continue;
            }
            $out[$d] = [
                'oasis' => (int)($row['oasis'] ?? 0),
                'adult' => (int)($row['adult'] ?? 0),
                'child' => (int)($row['child'] ?? 0),
            ];
        }
        return $out;
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
