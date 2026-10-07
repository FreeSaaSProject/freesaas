<?php
declare(strict_types=1);

/** CRUD + counters for base_records_llm_studies / results. */
final class StudiesRepository
{
    public static function tablesReady(): bool
    {
        return Database::tableExists('base_records_llm_studies')
            && Database::tableExists('base_records_llm_study_results');
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tablesReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_records_llm_studies WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array{items:list<array>,total:int}
     */
    public static function list(string $kind = 'regular', int $limit = 50, int $offset = 0): array
    {
        if (!self::tablesReady()) {
            return ['items' => [], 'total' => 0];
        }
        if ($kind === 'cyclic') {
            $wh = 'IS_CYCLIC = 1';
        } elseif ($kind === 'spawned') {
            $wh = 'ID_CYCLIC_PARENT > 0';
        } else {
            $wh = 'IS_CYCLIC = 0 AND ID_CYCLIC_PARENT = 0';
        }
        $pdo = Database::pdo();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM base_records_llm_studies WHERE {$wh}")->fetchColumn();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $st = $pdo->query(
            "SELECT * FROM base_records_llm_studies WHERE {$wh} ORDER BY DT_CREATE DESC LIMIT {$limit} OFFSET {$offset}"
        );
        return ['items' => $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [], 'total' => $total];
    }

    /** @return list<array> */
    public static function listEnabledCyclic(): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $st = Database::pdo()->query(
            'SELECT * FROM base_records_llm_studies WHERE IS_CYCLIC = 1 AND CYCLIC_ENABLED = 1 ORDER BY ID ASC'
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function findChild(int $parentId, int $procedureId): ?array
    {
        if ($parentId < 1 || $procedureId < 1 || !self::tablesReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_records_llm_studies WHERE ID_CYCLIC_PARENT = ? AND ID_PROCEDURE = ? LIMIT 1'
        );
        $st->execute([$parentId, $procedureId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{id_admin?:int,dt_range_start?:int,dt_range_end?:int,record_ids_raw?:string} $params
     * @param array{id_cyclic_parent?:int,id_procedure?:int} $extra
     * @return array{ok:bool,id:int,error:string,total:int}
     */
    public static function create(string $title, string $mode, array $params, string $prompt, int $createdBy, array $extra = []): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['ok' => false, 'id' => 0, 'error' => t('audio_llm.err.prompt'), 'total' => 0];
        }
        if (!self::tablesReady()) {
            return ['ok' => false, 'id' => 0, 'error' => 'Tables missing', 'total' => 0];
        }
        $resolved = self::resolveRecordIds($mode, $params);
        if ($resolved['error'] !== '') {
            return ['ok' => false, 'id' => 0, 'error' => $resolved['error'], 'total' => 0];
        }
        $recordIds = $resolved['ids'];
        $total = count($recordIds);
        $title = trim($title);
        if ($title === '') {
            $title = mb_substr(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt, 0, 120, 'UTF-8');
            if (mb_strlen($prompt, 'UTF-8') > 120) {
                $title .= '…';
            }
        }
        $now = time();
        $idAdmin = ($mode === 'admin_range') ? (int)($params['id_admin'] ?? 0) : 0;
        $dtStart = ($mode === 'admin_range') ? (int)($params['dt_range_start'] ?? 0) : 0;
        $dtEnd = ($mode === 'admin_range') ? (int)($params['dt_range_end'] ?? 0) : 0;
        $recordIdsRaw = ($mode === 'record_ids') ? (string)($params['record_ids_raw'] ?? '') : '';
        if ($mode === 'record_ids' && $recordIdsRaw === '' && $recordIds !== []) {
            $recordIdsRaw = implode(',', $recordIds);
        }
        $parent = (int)($extra['id_cyclic_parent'] ?? 0);
        $proc = (int)($extra['id_procedure'] ?? 0);

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO base_records_llm_studies SET
                TITLE=?, SELECTION_MODE=?, ID_ADMIN=?, DT_RANGE_START=?, DT_RANGE_END=?, RECORD_IDS_RAW=?,
                PROMPT=?, IS_CYCLIC=0, CYCLIC_ENABLED=0, TRIGGER_GOOD_ID=0, AMOUNT_POSITIVE=0, AMOUNT_NEGATIVE=0,
                ID_CYCLIC_PARENT=?, ID_PROCEDURE=?, STATUS=0, TOTAL_RECORDS=?, DONE_RECORDS=0, MEANINGFUL_COUNT=0,
                LLM_SUMMARY=\'\', DT_SUMMARY=0, ID_CREATED_BY=?, DT_CREATE=?, DT_UPDATE=?, DT_FINISHED=0'
        );
        $st->execute([
            $title, $mode, $idAdmin, $dtStart, $dtEnd, $recordIdsRaw, $prompt,
            $parent, $proc, $total, $createdBy, $now, $now,
        ]);
        $studyId = (int)$pdo->lastInsertId();
        if ($studyId < 1) {
            return ['ok' => false, 'id' => 0, 'error' => 'Insert failed', 'total' => 0];
        }

        $adminByRecord = [];
        if ($recordIds !== []) {
            $in = implode(',', array_map('intval', $recordIds));
            $rs = $pdo->query("SELECT ID, ID_ADMIN FROM base_records WHERE ID IN ({$in})");
            if ($rs) {
                while ($r = $rs->fetch(PDO::FETCH_ASSOC)) {
                    $adminByRecord[(int)$r['ID']] = (int)$r['ID_ADMIN'];
                }
            }
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_records_llm_study_results SET
                ID_STUDY=?, ID_RECORD=?, ID_ADMIN=?, STATUS=0, RESULT_TEXT=\'\', ERROR_TEXT=\'\', DT_PROCESSED=0'
        );
        foreach ($recordIds as $rid) {
            $ins->execute([$studyId, $rid, $adminByRecord[$rid] ?? 0]);
        }
        return ['ok' => true, 'id' => $studyId, 'error' => '', 'total' => $total];
    }

    /**
     * @return array{ok:bool,id:int,error:string}
     */
    public static function createCyclic(
        string $title,
        string $prompt,
        int $triggerGoodId,
        int $amountPositive,
        int $amountNegative,
        bool $enabled,
        int $createdBy
    ): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['ok' => false, 'id' => 0, 'error' => t('audio_llm.err.prompt')];
        }
        if ($triggerGoodId < 1) {
            return ['ok' => false, 'id' => 0, 'error' => t('audio_llm.err.trigger')];
        }
        if (!self::tablesReady()) {
            return ['ok' => false, 'id' => 0, 'error' => 'Tables missing'];
        }
        $title = trim($title);
        if ($title === '') {
            $title = mb_substr(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt, 0, 120, 'UTF-8');
        }
        $now = time();
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO base_records_llm_studies SET
                TITLE=?, SELECTION_MODE=\'record_ids\', ID_ADMIN=0, DT_RANGE_START=0, DT_RANGE_END=0, RECORD_IDS_RAW=\'\',
                PROMPT=?, IS_CYCLIC=1, CYCLIC_ENABLED=?, TRIGGER_GOOD_ID=?, AMOUNT_POSITIVE=?, AMOUNT_NEGATIVE=?,
                ID_CYCLIC_PARENT=0, ID_PROCEDURE=0, STATUS=2, TOTAL_RECORDS=0, DONE_RECORDS=0, MEANINGFUL_COUNT=0,
                LLM_SUMMARY=\'\', DT_SUMMARY=0, ID_CREATED_BY=?, DT_CREATE=?, DT_UPDATE=?, DT_FINISHED=?'
        );
        $st->execute([
            $title, $prompt, $enabled ? 1 : 0, $triggerGoodId, $amountPositive, $amountNegative,
            $createdBy, $now, $now, $now,
        ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'error' => ''];
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public static function updateCyclic(
        int $id,
        string $title,
        string $prompt,
        int $triggerGoodId,
        int $amountPositive,
        int $amountNegative,
        bool $enabled
    ): array {
        $study = self::find($id);
        if (!$study || (int)$study['IS_CYCLIC'] !== 1) {
            return ['ok' => false, 'error' => t('audio_llm.err.not_found')];
        }
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['ok' => false, 'error' => t('audio_llm.err.prompt')];
        }
        if ($triggerGoodId < 1) {
            return ['ok' => false, 'error' => t('audio_llm.err.trigger')];
        }
        $title = trim($title);
        if ($title === '') {
            $title = (string)$study['TITLE'];
        }
        Database::pdo()->prepare(
            'UPDATE base_records_llm_studies SET
                TITLE=?, PROMPT=?, TRIGGER_GOOD_ID=?, AMOUNT_POSITIVE=?, AMOUNT_NEGATIVE=?,
                CYCLIC_ENABLED=?, DT_UPDATE=?
             WHERE ID=?'
        )->execute([
            $title, $prompt, $triggerGoodId, $amountPositive, $amountNegative,
            $enabled ? 1 : 0, time(), $id,
        ]);
        return ['ok' => true, 'error' => ''];
    }

    public static function delete(int $id): bool
    {
        if ($id < 1 || !self::tablesReady()) {
            return false;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_records_llm_study_results WHERE ID_STUDY = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM base_records_llm_studies WHERE ID = ?')->execute([$id]);
        return true;
    }

    /** @return list<array> */
    public static function results(int $studyId): array
    {
        if ($studyId < 1 || !self::tablesReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT r.*, br.DT_RECORDED
             FROM base_records_llm_study_results r
             LEFT JOIN base_records br ON br.ID = r.ID_RECORD
             WHERE r.ID_STUDY = ?
             ORDER BY br.DT_RECORDED ASC, r.ID_RECORD ASC'
        );
        $st->execute([$studyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<int> */
    public static function recordIdsForStudy(int $studyId): array
    {
        if ($studyId < 1 || !self::tablesReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_RECORD FROM base_records_llm_study_results WHERE ID_STUDY = ? ORDER BY ID ASC'
        );
        $st->execute([$studyId]);
        $ids = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$r['ID_RECORD'];
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @return list<int> */
    public static function findRecordIdsInWindow(int $adminId, int $dtStart, int $dtEnd): array
    {
        if ($adminId < 1 || $dtStart <= 0 || $dtEnd < $dtStart || !Database::tableExists('base_records')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED < 1)
             ORDER BY DT_RECORDED ASC, ID ASC'
        );
        $st->execute([$adminId, $dtStart, $dtEnd]);
        $ids = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (int)$r['ID'];
        }
        return $ids;
    }

    public static function refreshCounters(int $studyId): void
    {
        if ($studyId < 1 || !self::tablesReady()) {
            return;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN STATUS IN (2,3,4) THEN 1 ELSE 0 END) AS done_n,
                SUM(CASE WHEN STATUS = 0 THEN 1 ELSE 0 END) AS pending_n,
                COUNT(*) AS total_n
             FROM base_records_llm_study_results WHERE ID_STUDY = ?'
        );
        $st->execute([$studyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $done = (int)($c['done_n'] ?? 0);
        $pending = (int)($c['pending_n'] ?? 0);
        $total = (int)($c['total_n'] ?? 0);

        $meaningful = 0;
        $stM = $pdo->prepare('SELECT RESULT_TEXT FROM base_records_llm_study_results WHERE ID_STUDY = ? AND STATUS = 2');
        $stM->execute([$studyId]);
        while ($row = $stM->fetch(PDO::FETCH_ASSOC)) {
            if (self::isMeaningfulResult((string)$row['RESULT_TEXT'])) {
                $meaningful++;
            }
        }

        $status = 0;
        $finished = 0;
        if ($total > 0 && $pending === 0) {
            $status = 2;
            $finished = time();
        } elseif ($done > 0 || $pending < $total) {
            $status = 1;
        }
        $pdo->prepare(
            'UPDATE base_records_llm_studies SET
                TOTAL_RECORDS=?, DONE_RECORDS=?, MEANINGFUL_COUNT=?, STATUS=?,
                DT_UPDATE=?, DT_FINISHED=IF(? > 0, ?, DT_FINISHED)
             WHERE ID=?'
        )->execute([$total, $done, $meaningful, $status, time(), $finished, $finished, $studyId]);
    }

    /**
     * @param array{id_admin?:int,dt_range_start?:int,dt_range_end?:int,record_ids_raw?:string} $params
     * @return array{ids:list<int>,error:string}
     */
    public static function resolveRecordIds(string $mode, array $params): array
    {
        if ($mode === 'admin_range') {
            $adminId = (int)($params['id_admin'] ?? 0);
            $dtStart = (int)($params['dt_range_start'] ?? 0);
            $dtEnd = (int)($params['dt_range_end'] ?? 0);
            if ($adminId <= 0) {
                return ['ids' => [], 'error' => t('audio_llm.err.admin')];
            }
            if ($dtStart <= 0 || $dtEnd <= 0 || $dtEnd < $dtStart) {
                return ['ids' => [], 'error' => t('audio_llm.err.range')];
            }
            $ids = self::findRecordIdsInWindow($adminId, $dtStart, $dtEnd);
            if ($ids === []) {
                return ['ids' => [], 'error' => t('audio_llm.err.none')];
            }
            return ['ids' => $ids, 'error' => ''];
        }
        if ($mode === 'record_ids') {
            $raw = trim((string)($params['record_ids_raw'] ?? ''));
            if ($raw === '') {
                return ['ids' => [], 'error' => t('audio_llm.err.ids')];
            }
            $parts = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $want = [];
            foreach ($parts as $p) {
                $id = (int)$p;
                if ($id > 0) {
                    $want[$id] = $id;
                }
            }
            if ($want === []) {
                return ['ids' => [], 'error' => t('audio_llm.err.ids')];
            }
            $in = implode(',', array_map('intval', array_values($want)));
            $st = Database::pdo()->query(
                "SELECT ID FROM base_records WHERE ID IN ({$in}) ORDER BY DT_RECORDED ASC, ID ASC"
            );
            $found = [];
            if ($st) {
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $found[] = (int)$r['ID'];
                }
            }
            if ($found === []) {
                return ['ids' => [], 'error' => t('audio_llm.err.none')];
            }
            return ['ids' => $found, 'error' => ''];
        }
        return ['ids' => [], 'error' => 'Unknown mode'];
    }

    public static function isMeaningfulResult(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        $found = CyclicHelpers::parseFound($t);
        if ($found === false) {
            return false;
        }
        if ($found === true) {
            return true;
        }
        $lower = mb_strtolower($t, 'UTF-8');
        $deny = [
            'нет', '—', '-', 'n/a', 'na', 'пусто', 'не найдено', 'отсутствует', 'null',
            'none', 'no', 'ничего', 'не обнаружено', 'не выявлено',
        ];
        if (in_array($lower, $deny, true)) {
            return false;
        }
        return mb_strlen($lower, 'UTF-8') > 2;
    }

    public static function parseDatetime($value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:[ T](\d{1,2}):(\d{2}))?$/u', $value, $m)) {
            $ts = mktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), 0, (int)$m[2], (int)$m[1], (int)$m[3]);
            return $ts > 0 ? $ts : 0;
        }
        $ts = strtotime($value);
        return ($ts !== false && $ts > 0) ? (int)$ts : 0;
    }
}
