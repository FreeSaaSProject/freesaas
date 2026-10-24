<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Persistence for script-coach runs, phase artifacts, and proposals. */
final class CoachRunsRepository
{
    public const STATUS_PENDING = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_READY = 2;
    public const STATUS_ERROR = 3;

    public const PROP_PENDING = 0;
    public const PROP_ACCEPTED = 1;
    public const PROP_REJECTED = 2;

    public static function tablesReady(): bool
    {
        return Database::tableExists('base_script_coach_runs')
            && Database::tableExists('base_script_coach_artifacts')
            && Database::tableExists('base_script_coach_proposals');
    }

    /**
     * @param array{q?:string,admin?:int,day?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tablesReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $params = [];
        $adminId = (int)($opts['admin'] ?? 0);
        if ($adminId > 0) {
            $where[] = 'r.ID_ADMIN = ?';
            $params[] = $adminId;
        }
        $day = trim((string)($opts['day'] ?? ''));
        if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $where[] = 'r.DAY = ?';
            $params[] = $day;
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_script_coach_runs r WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $limit = max(1, min(100, (int)($opts['limit'] ?? 50)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $depthCol = Database::columnExists('base_script_coach_runs', 'DEPTH')
            ? ', r.DEPTH AS depth' : ", 'normal' AS depth";
        $sql = "SELECT r.ID AS id, r.ID_ADMIN AS id_admin, r.DAY AS day, r.STATUS AS status,
                       r.PHASE AS phase, r.ERROR_TEXT AS error_text, r.ID_CREATED_BY AS id_created_by,
                       r.DT_CREATE AS dt_create, r.DT_UPDATE AS dt_update
                       {$depthCol},
                       u.name AS admin_name
                FROM base_script_coach_runs r
                LEFT JOIN base_users u ON u.id = r.ID_ADMIN
                WHERE $sqlWhere
                ORDER BY r.ID DESC
                LIMIT $limit OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tablesReady()) {
            return null;
        }
        $depthCol = Database::columnExists('base_script_coach_runs', 'DEPTH')
            ? ', r.DEPTH AS depth' : ", 'normal' AS depth";
        $st = Database::pdo()->prepare(
            "SELECT r.ID AS id, r.ID_ADMIN AS id_admin, r.DAY AS day, r.STATUS AS status,
                    r.PHASE AS phase, r.ERROR_TEXT AS error_text, r.ID_CREATED_BY AS id_created_by,
                    r.DT_CREATE AS dt_create, r.DT_UPDATE AS dt_update
                    {$depthCol},
                    u.name AS admin_name
             FROM base_script_coach_runs r
             LEFT JOIN base_users u ON u.id = r.ID_ADMIN
             WHERE r.ID = ? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['depth'] = self::normalizeDepth((string)($row['depth'] ?? 'normal'));

        return $row;
    }

    public static function normalizeDepth(string $depth): string
    {
        $depth = strtolower(trim($depth));

        return $depth === 'hard' ? 'hard' : 'normal';
    }

    public static function setDepth(int $runId, string $depth): void
    {
        if ($runId < 1 || !Database::columnExists('base_script_coach_runs', 'DEPTH')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_script_coach_runs SET DEPTH=?, DT_UPDATE=? WHERE ID=?'
        );
        $st->execute([self::normalizeDepth($depth), time(), $runId]);
    }

    /** @return array<string,mixed>|null */
    public static function findLatestForDay(int $adminId, string $day): ?array
    {
        if ($adminId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !self::tablesReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id FROM base_script_coach_runs
             WHERE ID_ADMIN = ? AND DAY = ?
             ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$adminId, $day]);
        $id = (int)$st->fetchColumn();

        return $id > 0 ? self::find($id) : null;
    }

    public static function create(int $adminId, string $day, int $createdBy, string $depth = 'normal'): int
    {
        if (!self::tablesReady()) {
            throw new RuntimeException(t('audio_script_coach.err.tables'));
        }
        $depth = self::normalizeDepth($depth);
        $now = time();
        if (Database::columnExists('base_script_coach_runs', 'DEPTH')) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_script_coach_runs
                 (ID_ADMIN, DAY, STATUS, PHASE, DEPTH, ERROR_TEXT, ID_CREATED_BY, DT_CREATE, DT_UPDATE)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $adminId,
                $day,
                self::STATUS_PENDING,
                '',
                $depth,
                '',
                $createdBy,
                $now,
                $now,
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_script_coach_runs
                 (ID_ADMIN, DAY, STATUS, PHASE, ERROR_TEXT, ID_CREATED_BY, DT_CREATE, DT_UPDATE)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $adminId,
                $day,
                self::STATUS_PENDING,
                '',
                '',
                $createdBy,
                $now,
                $now,
            ]);
        }

        return (int)Database::pdo()->lastInsertId();
    }

    public static function setPhase(int $runId, string $phase, int $status): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_script_coach_runs SET PHASE=?, STATUS=?, DT_UPDATE=? WHERE ID=?'
        );
        $st->execute([$phase, $status, time(), $runId]);
    }

    public static function setError(int $runId, string $error): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_script_coach_runs SET STATUS=?, ERROR_TEXT=?, DT_UPDATE=? WHERE ID=?'
        );
        $st->execute([self::STATUS_ERROR, mb_substr($error, 0, 500), time(), $runId]);
    }

    /** @param array<string,mixed> $payload */
    public static function saveArtifact(int $runId, string $phase, array $payload): void
    {
        $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO base_script_coach_artifacts (ID_RUN, PHASE, PAYLOAD_JSON, DT_CREATE)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE PAYLOAD_JSON=VALUES(PAYLOAD_JSON), DT_CREATE=VALUES(DT_CREATE)'
        );
        $st->execute([$runId, $phase, $json, time()]);
    }

    /** @return array<string,mixed>|null */
    public static function artifact(int $runId, string $phase): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT PAYLOAD_JSON FROM base_script_coach_artifacts WHERE ID_RUN=? AND PHASE=? LIMIT 1'
        );
        $st->execute([$runId, $phase]);
        $raw = $st->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array{phase:string,dt_create:int}>
     */
    public static function artifactPhases(int $runId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT PHASE AS phase, DT_CREATE AS dt_create
             FROM base_script_coach_artifacts WHERE ID_RUN=? ORDER BY ID ASC'
        );
        $st->execute([$runId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{
     *   id_script?:int,id_part?:int,action:string,id_dict?:int,new_dict_name?:string,
     *   phrases:list<string>,rationale?:string,confidence?:float,link_part?:int
     * } $row
     */
    public static function insertProposal(int $runId, array $row): int
    {
        $phrases = array_values(array_filter(array_map(
            static fn ($p) => mb_substr(trim((string)$p), 0, 255),
            is_array($row['phrases'] ?? null) ? $row['phrases'] : []
        ), static fn ($p) => $p !== ''));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_script_coach_proposals
             (ID_RUN, ID_SCRIPT, ID_PART, ACTION, ID_DICT, NEW_DICT_NAME, PHRASES_JSON,
              RATIONALE, CONFIDENCE, LINK_PART, STATUS, DT_CREATE)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $runId,
            max(0, (int)($row['id_script'] ?? 0)),
            max(0, (int)($row['id_part'] ?? 0)),
            mb_substr((string)($row['action'] ?? ''), 0, 32),
            max(0, (int)($row['id_dict'] ?? 0)),
            mb_substr(trim((string)($row['new_dict_name'] ?? '')), 0, 100),
            (string)json_encode($phrases, JSON_UNESCAPED_UNICODE),
            mb_substr(trim((string)($row['rationale'] ?? '')), 0, 500),
            max(0.0, min(1.0, (float)($row['confidence'] ?? 0))),
            !empty($row['link_part']) ? 1 : 0,
            self::PROP_PENDING,
            time(),
        ]);

        return (int)Database::pdo()->lastInsertId();
    }

    public static function clearProposals(int $runId): void
    {
        $st = Database::pdo()->prepare('DELETE FROM base_script_coach_proposals WHERE ID_RUN=?');
        $st->execute([$runId]);
    }

    /** Drop phase artifacts so the run can be rebuilt from catalog. */
    public static function clearArtifacts(int $runId): void
    {
        if ($runId < 1 || !self::tablesReady()) {
            return;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_script_coach_artifacts WHERE ID_RUN=?');
        $st->execute([$runId]);
    }

    /** Reset a finished/errored run to pending (artifacts + pending proposals cleared). */
    public static function resetForRebuild(int $runId): void
    {
        if ($runId < 1 || !self::tablesReady()) {
            return;
        }
        self::clearArtifacts($runId);
        self::clearProposals($runId);
        $st = Database::pdo()->prepare(
            'UPDATE base_script_coach_runs
             SET STATUS=?, PHASE=?, ERROR_TEXT=?, DT_UPDATE=?
             WHERE ID=?'
        );
        $st->execute([self::STATUS_PENDING, '', '', time(), $runId]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function proposalsForRun(int $runId, ?int $status = null): array
    {
        $sql = 'SELECT ID AS id, ID_RUN AS id_run, ID_SCRIPT AS id_script, ID_PART AS id_part,
                       ACTION AS action, ID_DICT AS id_dict, NEW_DICT_NAME AS new_dict_name,
                       PHRASES_JSON AS phrases_json, RATIONALE AS rationale, CONFIDENCE AS confidence,
                       LINK_PART AS link_part, STATUS AS status, ID_DECIDED_BY AS id_decided_by,
                       DT_DECIDE AS dt_decide, APPLY_META_JSON AS apply_meta_json, DT_CREATE AS dt_create
                FROM base_script_coach_proposals WHERE ID_RUN=?';
        $params = [$runId];
        if ($status !== null) {
            $sql .= ' AND STATUS=?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['phrases_json'] ?? ''), true);
            $row['phrases'] = is_array($decoded) ? $decoded : [];
            $meta = json_decode((string)($row['apply_meta_json'] ?? ''), true);
            $row['apply_meta'] = is_array($meta) ? $meta : null;
        }
        unset($row);

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function findProposal(int $id): ?array
    {
        if ($id < 1 || !self::tablesReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_RUN AS id_run, ID_SCRIPT AS id_script, ID_PART AS id_part,
                    ACTION AS action, ID_DICT AS id_dict, NEW_DICT_NAME AS new_dict_name,
                    PHRASES_JSON AS phrases_json, RATIONALE AS rationale, CONFIDENCE AS confidence,
                    LINK_PART AS link_part, STATUS AS status, ID_DECIDED_BY AS id_decided_by,
                    DT_DECIDE AS dt_decide, APPLY_META_JSON AS apply_meta_json, DT_CREATE AS dt_create
             FROM base_script_coach_proposals WHERE ID=? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string)($row['phrases_json'] ?? ''), true);
        $row['phrases'] = is_array($decoded) ? $decoded : [];
        $meta = json_decode((string)($row['apply_meta_json'] ?? ''), true);
        $row['apply_meta'] = is_array($meta) ? $meta : null;

        return $row;
    }

    /** @param array<string,mixed>|null $applyMeta */
    public static function decideProposal(int $id, int $status, int $userId, ?array $applyMeta = null): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_script_coach_proposals
             SET STATUS=?, ID_DECIDED_BY=?, DT_DECIDE=?, APPLY_META_JSON=?
             WHERE ID=? AND STATUS=?'
        );
        $st->execute([
            $status,
            $userId,
            time(),
            $applyMeta !== null ? (string)json_encode($applyMeta, JSON_UNESCAPED_UNICODE) : null,
            $id,
            self::PROP_PENDING,
        ]);
    }

    /**
     * Keep proposal pending after a partial phrase accept.
     *
     * @param list<string> $remainingPhrases
     * @param array<string,mixed> $applyMeta
     */
    public static function updateProposalProgress(int $id, array $remainingPhrases, array $applyMeta): void
    {
        $phrases = array_values(array_filter(array_map(
            static fn ($p) => mb_substr(trim((string)$p), 0, 255),
            $remainingPhrases
        ), static fn ($p) => $p !== ''));
        $st = Database::pdo()->prepare(
            'UPDATE base_script_coach_proposals
             SET PHRASES_JSON=?, APPLY_META_JSON=?
             WHERE ID=? AND STATUS=?'
        );
        $st->execute([
            (string)json_encode($phrases, JSON_UNESCAPED_UNICODE),
            (string)json_encode($applyMeta, JSON_UNESCAPED_UNICODE),
            $id,
            self::PROP_PENDING,
        ]);
    }
}
