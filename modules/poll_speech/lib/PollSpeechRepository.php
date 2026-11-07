<?php
declare(strict_types=1);

/** Queries for oral speech fill + list UI. */
final class PollSpeechRepository
{
    /**
     * All oral marks (for recreate), newest last.
     *
     * @return list<array<string,mixed>>
     */
    public static function listAllOralMarks(): array
    {
        if (!Database::tableExists('base_poll_invites')) {
            return [];
        }
        $join = Database::tableExists('base_procedures')
            ? 'LEFT JOIN base_procedures p ON p.ID = i.ID_PROC'
            : '';
        $procCols = $join !== ''
            ? ', p.DTSTART AS proc_dtstart, p.DTEND AS proc_dtend, p.ID_OPERATOR AS proc_operator, p.ID_ADMIN AS proc_admin, p.ID_USER AS proc_user, p.SUMM AS proc_summ, p.ID_EVENT_PARENT AS proc_event_parent'
            : '';
        $st = Database::pdo()->query(
            "SELECT i.ID AS id, i.ID_POLL AS id_poll, i.ID_USER AS id_user, i.ID_PROC AS id_proc,
                    i.ID_EVENT AS id_event, i.ID_OPERATOR_LINKED AS id_operator_linked,
                    i.ID_OPERATOR_CREATE AS id_operator_create, i.RESP_USERS AS resp_users,
                    i.DATA_OPTS AS data_opts, i.DATA_TEXT AS data_text,
                    i.DT_COMPLETED AS dt_completed, i.DT_CREATE AS dt_create,
                    i.DT_AVAIL AS dt_avail, i.DT_EXPIRE AS dt_expire,
                    i.CODE AS code, i.LOG AS log, i.BONUS_AUTO AS bonus_auto, i.BONUS_VALUE AS bonus_value,
                    i.IS_SPECIAL AS is_special, i.IS_SPECIAL_FRIEND AS is_special_friend, i.IS_RESULT AS is_result
                    $procCols
             FROM base_poll_invites i
             $join
             WHERE i.ID_PROC > 0
               AND (i.CODE = '' OR i.LOG LIKE '%[ORAL_ASKED]%' OR i.LOG LIKE '%[POLL_SPEECH]%')
             ORDER BY i.ID ASC"
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Open oral invites (empty CODE / ORAL_ASKED) with procedure on day.
     *
     * @return list<array<string,mixed>>
     */
    public static function openOralInvitesForDay(int $dayStart, int $dayEnd): array
    {
        if ($dayStart < 1 || $dayEnd < $dayStart || !Database::tableExists('base_poll_invites')) {
            return [];
        }
        if (!Database::tableExists('base_procedures')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT i.ID AS id, i.ID_POLL AS id_poll, i.ID_USER AS id_user, i.ID_PROC AS id_proc,
                    i.ID_EVENT AS id_event, i.ID_OPERATOR_LINKED AS id_operator_linked,
                    i.ID_OPERATOR_CREATE AS id_operator_create, i.RESP_USERS AS resp_users,
                    i.DATA_OPTS AS data_opts, i.DT_COMPLETED AS dt_completed, i.CODE AS code,
                    i.LOG AS log, i.DT_CREATE AS dt_create,
                    p.DTSTART AS proc_dtstart, p.DTEND AS proc_dtend,
                    p.ID_OPERATOR AS proc_operator, p.ID_ADMIN AS proc_admin
             FROM base_poll_invites i
             INNER JOIN base_procedures p ON p.ID = i.ID_PROC
             WHERE i.ID_PROC > 0
               AND p.DTSTART >= ? AND p.DTSTART <= ?
               AND (i.CODE = '' OR i.LOG LIKE '%[ORAL_ASKED]%')
             ORDER BY i.ID ASC"
        );
        $st->execute([$dayStart, $dayEnd]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Cabin records in window (for transcript fallback before stem hits exist).
     *
     * @param list<int> $adminIds
     * @return list<array{id:int,id_admin:int,dt:int,transcription:string}>
     */
    public static function recordsInWindow(array $adminIds, int $winFrom, int $winTo): array
    {
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        if ($adminIds === [] || !Database::tableExists('base_records')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_ADMIN AS id_admin, DT_RECORDED AS dt, TRANSCRIPTION AS transcription
             FROM base_records
             WHERE ID_ADMIN IN ($ph)
               AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION <> ''
             ORDER BY DT_RECORDED ASC, ID ASC"
        );
        $st->execute(array_merge($adminIds, [$winFrom, $winTo]));
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Latest instance hit for score keys in window.
     *
     * @param list<int> $adminIds
     * @param list<int> $keyIds
     * @return array{id_key:int,id_record:int,id_admin:int,dt_start:int}|null
     */
    /**
     * @return list<array{id_key:int,id_record:int,id_admin:int,dt_start:int}>
     */
    public static function scoreHitsInWindow(
        array $adminIds,
        int $winFrom,
        int $winTo,
        int $dictId,
        array $keyIds
    ): array {
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        $keyIds = array_values(array_unique(array_filter(array_map('intval', $keyIds))));
        if ($adminIds === [] || $keyIds === [] || $dictId < 1
            || !Database::tableExists('base_records_instances')
            || !Database::tableExists('base_records_keys')
        ) {
            return [];
        }
        $phA = implode(',', array_fill(0, count($adminIds), '?'));
        $phK = implode(',', array_fill(0, count($keyIds), '?'));
        $sql = "SELECT i.ID_KEY AS id_key, i.ID_RECORD AS id_record, i.ID_ADMIN AS id_admin,
                       i.DT_START AS dt_start
                FROM base_records_instances i
                INNER JOIN base_records_keys k ON k.ID = i.ID_KEY
                WHERE i.ID_ADMIN IN ($phA)
                  AND i.DT_START >= ? AND i.DT_START <= ?
                  AND k.ID_DICTONARY = ?
                  AND i.ID_KEY IN ($phK)
                  AND k.DISABLED < 1
                ORDER BY i.DT_START ASC, i.ID ASC";
        $params = array_merge($adminIds, [$winFrom, $winTo, $dictId], $keyIds);
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id_key' => (int)$row['id_key'],
                'id_record' => (int)$row['id_record'],
                'id_admin' => (int)$row['id_admin'],
                'dt_start' => (int)$row['dt_start'],
            ];
        }
        return $out;
    }

    public static function latestScoreHit(
        array $adminIds,
        int $winFrom,
        int $winTo,
        int $dictId,
        array $keyIds
    ): ?array {
        $hits = self::scoreHitsInWindow($adminIds, $winFrom, $winTo, $dictId, $keyIds);
        if ($hits === []) {
            return null;
        }
        return $hits[count($hits) - 1];
    }

    /**
     * Speech-filled oral invites for a procedure day (for snippet backfill).
     *
     * @return list<array<string,mixed>>
     */
    public static function speechFilledInvitesForDay(int $dayStart, int $dayEnd): array
    {
        if ($dayStart < 1 || $dayEnd < $dayStart || !Database::tableExists('base_poll_invites')) {
            return [];
        }
        if (!Database::tableExists('base_procedures')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT i.ID AS id, i.ID_POLL AS id_poll, i.ID_USER AS id_user, i.ID_PROC AS id_proc,
                    i.ID_OPERATOR_LINKED AS id_operator_linked, i.ID_OPERATOR_CREATE AS id_operator_create,
                    i.RESP_USERS AS resp_users, i.DATA_OPTS AS data_opts, i.DATA_TEXT AS data_text,
                    i.DT_COMPLETED AS dt_completed, i.DT_CREATE AS dt_create, i.LOG AS log
             FROM base_poll_invites i
             INNER JOIN base_procedures p ON p.ID = i.ID_PROC
             WHERE i.ID_PROC > 0
               AND p.DTSTART >= ? AND p.DTSTART <= ?
               AND i.LOG LIKE '%[SPEECH]%'
             ORDER BY i.ID ASC"
        );
        $st->execute([$dayStart, $dayEnd]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{day?:string,progress?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function listOral(array $opts = []): array
    {
        if (!Database::tableExists('base_poll_invites')) {
            return ['items' => [], 'total' => 0];
        }
        $where = ["(i.CODE = '' OR i.LOG LIKE '%[ORAL_ASKED]%')", 'i.ID_PROC > 0'];
        $params = [];
        $day = trim((string)($opts['day'] ?? ''));
        if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
            && Database::tableExists('base_procedures')
        ) {
            $from = (int)strtotime($day . ' 00:00:00');
            $to = (int)strtotime($day . ' 23:59:59');
            $where[] = 'p.DTSTART >= ? AND p.DTSTART <= ?';
            $params[] = $from;
            $params[] = $to;
        }
        $progress = (string)($opts['progress'] ?? '');
        if ($progress === 'open') {
            $where[] = 'i.DT_COMPLETED < 1';
        } elseif ($progress === 'done') {
            $where[] = 'i.DT_COMPLETED > 0';
        } elseif ($progress === 'speech') {
            $where[] = "i.LOG LIKE '%[SPEECH]%'";
        }
        $sqlWhere = implode(' AND ', $where);
        $join = Database::tableExists('base_procedures')
            ? 'LEFT JOIN base_procedures p ON p.ID = i.ID_PROC'
            : '';
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_poll_invites i $join WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $scoreCols = Database::columnExists('base_poll_invites', 'ID_PROC_OPERATOR')
            ? 'i.ID_PROC_OPERATOR AS id_proc_operator, i.ID_PROC_ADMIN AS id_proc_admin,
               i.SCORE_STAFF AS score_staff, i.SCORE_SERVICE AS score_service,'
            : '0 AS id_proc_operator, 0 AS id_proc_admin,
               NULL AS score_staff, NULL AS score_service,';
        $sql = "SELECT i.ID AS id, i.ID_POLL AS id_poll, i.ID_USER AS id_user, i.ID_PROC AS id_proc,
                       i.ID_OPERATOR_LINKED AS id_operator_linked, i.RESP_USERS AS resp_users,
                       i.DATA_OPTS AS data_opts, i.DATA_TEXT AS data_text,
                       i.DT_COMPLETED AS dt_completed, i.DT_CREATE AS dt_create,
                       i.LOG AS log,
                       {$scoreCols}
                       p.ID_OPERATOR AS proc_operator, p.ID_ADMIN AS proc_admin,
                       p.DTSTART AS proc_dtstart, p.DTEND AS proc_dtend
                FROM base_poll_invites i
                $join
                WHERE $sqlWhere
                ORDER BY i.ID DESC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }
}
