<?php
declare(strict_types=1);

/**
 * Surface A (/agent/audio_pipeline/*) — day diagnose, transcripts, collections,
 * instances, reparse via agent_token. See docs/agents/audio-scripts-debug.md.
 */
final class AudioPipelineAgentOps
{
    public static function register(): void
    {
        $ops = [
            ['GET', '/agent/audio_pipeline/day-diag', 'audio_pipeline.day_diag', [self::class, 'dayDiag'], [
                'description' => 'Counts + script_32 fingerprint for admin+date',
                'query' => ['admin', 'date'],
            ]],
            ['GET', '/agent/audio_pipeline/day-records', 'audio_pipeline.day_records', [self::class, 'dayRecords'], [
                'description' => 'Cabin records for admin+date; optional transcription=1 (truncated)',
                'query' => ['admin', 'date', 'transcription', 'limit', 'offset'],
            ]],
            ['GET', '/agent/audio_pipeline/record', 'audio_pipeline.record_get', [self::class, 'recordGet'], [
                'description' => 'One record + transcription + instances; query id=',
                'query' => ['id', 'instances_limit'],
            ]],
            ['GET', '/agent/audio_pipeline/collections', 'audio_pipeline.collections_list', [self::class, 'collectionsList'], [
                'description' => 'Script collections for admin+date; optional script=',
                'query' => ['admin', 'date', 'script', 'limit'],
            ]],
            ['GET', '/agent/audio_pipeline/collection', 'audio_pipeline.collection_get', [self::class, 'collectionGet'], [
                'description' => 'One collection + collections_datas hits; query id=',
                'query' => ['id', 'datas_limit'],
            ]],
            ['GET', '/agent/audio_pipeline/instances', 'audio_pipeline.instances_list', [self::class, 'instancesList'], [
                'description' => 'Key instances (вхождения) for admin+date; optional record=, key=',
                'query' => ['admin', 'date', 'record', 'key', 'limit', 'offset'],
            ]],
            ['POST', '/agent/audio_pipeline/day-reparse', 'audio_pipeline.day_reparse', [self::class, 'dayReparse'], [
                'description' => 'Reset+stem+collect for admin+date (confirm=1); optional time_budget_sec',
                'body' => ['admin', 'date', 'confirm', 'reset', 'time_budget_sec'],
            ]],
            ['POST', '/agent/audio_pipeline/backfill-playtime', 'audio_pipeline.backfill_playtime', [self::class, 'backfillPlaytime'], [
                'description' => 'Set PLAYTIME from transcript cue end where PLAYTIME=0 (confirm=1; dry_run=1 default)',
                'body' => ['admin', 'date', 'confirm', 'dry_run', 'limit'],
            ]],
        ];
        foreach ($ops as [$method, $path, $opId, $handler, $meta]) {
            AgentOps::registerModuleOp($method, $path, $opId, $handler, $meta);
        }

        $routes = [
            '/agent/audio_pipeline/day-diag' => ['GET'],
            '/agent/audio_pipeline/day-records' => ['GET'],
            '/agent/audio_pipeline/record' => ['GET'],
            '/agent/audio_pipeline/collections' => ['GET'],
            '/agent/audio_pipeline/collection' => ['GET'],
            '/agent/audio_pipeline/instances' => ['GET'],
            '/agent/audio_pipeline/day-reparse' => ['POST'],
            '/agent/audio_pipeline/backfill-playtime' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'audio_pipeline',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function dayDiag(): array
    {
        $bounds = self::parseAdminDate();
        if (isset($bounds['error'])) {
            return $bounds;
        }
        [$admin, $date, $from, $to] = [$bounds['admin'], $bounds['date'], $bounds['from'], $bounds['to']];
        $pdo = Database::pdo();
        $out = [
            'ok' => true,
            'admin' => $admin,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'module_versions' => self::moduleVersions(),
            'records' => null,
            'instances' => 0,
            'collections' => [],
            'collections_total' => 0,
            'script_roots' => [],
            'http' => 200,
        ];

        if (Database::tableExists('base_records')) {
            $st = $pdo->prepare(
                'SELECT COUNT(*) AS n,
                        SUM(LENGTH(COALESCE(TRANSCRIPTION, \'\')) > 10) AS with_tr,
                        SUM(DT_PARSED > 0) AS parsed,
                        COALESCE(SUM(PLAYTIME), 0) AS play
                 FROM base_records
                 WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
                   AND (DISABLED IS NULL OR DISABLED < 1)'
            );
            $st->execute([$admin, $from, $to]);
            $out['records'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (Database::tableExists('base_records_instances')) {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM base_records_instances
                 WHERE ID_ADMIN = ? AND DT_START >= ? AND DT_START <= ?'
            );
            $st->execute([$admin, $from, $to]);
            $out['instances'] = (int)$st->fetchColumn();
        }

        if (Database::tableExists('base_records_collections')) {
            $hasGate = Database::columnExists('base_records_collections', 'GATE_REASON');
            $sql = 'SELECT c.ID_SCRIPT AS id_script, COUNT(*) AS n,
                           COALESCE(SUM(c.SCORE_RATE), 0) AS score_rate_sum,
                           COALESCE(SUM(c.RATED), 0) AS rated_sum'
                . ($hasGate ? ', SUM(CASE WHEN c.GATE_REASON IS NOT NULL AND c.GATE_REASON != \'\' THEN 1 ELSE 0 END) AS gated' : '')
                . ' FROM base_records_collections c
                 WHERE c.ID_ADMIN = ? AND c.DT_START >= ? AND c.DT_START <= ?
                 GROUP BY c.ID_SCRIPT ORDER BY n DESC';
            $st = $pdo->prepare($sql);
            $st->execute([$admin, $from, $to]);
            $total = 0;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int)$row['id_script'];
                $n = (int)$row['n'];
                $total += $n;
                $out['collections'][] = [
                    'id_script' => $sid,
                    'name' => self::scriptName($sid),
                    'n' => $n,
                    'score_rate_sum' => (float)$row['score_rate_sum'],
                    'rated_sum' => (int)$row['rated_sum'],
                    'gated' => (int)($row['gated'] ?? 0),
                ];
            }
            $out['collections_total'] = $total;
        }

        if (Database::tableExists('base_records_scripts')) {
            $st = $pdo->query(
                'SELECT ID AS id, NAME AS name, DISABLED AS disabled
                 FROM base_records_scripts WHERE PID IS NULL OR PID = 0 ORDER BY ID'
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out['script_roots'][] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'disabled' => (int)$row['disabled'],
                ];
            }
            $probe = (int)(AgentOps::requestInput()['script'] ?? 32);
            if ($probe < 1) {
                $probe = 32;
            }
            $fp = self::scriptFingerprint($probe);
            $out['script_probe_id'] = $probe;
            $out['script_probe_parts'] = $fp['parts'];
            $out['script_probe_hash'] = $fp['hash'];
            // Keep legacy keys for existing callers.
            if ($probe === 32) {
                $out['script_32_parts'] = $fp['parts'];
                $out['script_32_parts_hash'] = $fp['hash'];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function dayRecords(): array
    {
        $bounds = self::parseAdminDate();
        if (isset($bounds['error'])) {
            return $bounds;
        }
        [$admin, $date, $from, $to] = [$bounds['admin'], $bounds['date'], $bounds['from'], $bounds['to']];
        $in = AgentOps::requestInput();
        $withTr = !empty($in['transcription'])
            && !in_array(strtolower((string)$in['transcription']), ['0', 'false', 'no'], true);
        $limit = max(1, min(500, (int)($in['limit'] ?? 200)));
        $offset = max(0, (int)($in['offset'] ?? 0));
        if (!Database::tableExists('base_records')) {
            return ['ok' => false, 'error' => 'no_records_table', 'http' => 500];
        }
        $pdo = Database::pdo();
        $stc = $pdo->prepare(
            'SELECT COUNT(*) FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?'
        );
        $stc->execute([$admin, $from, $to]);
        $total = (int)$stc->fetchColumn();
        $cols = 'ID AS id, ID_ADMIN AS id_admin, ID_DICTOPHONE AS badge, DT_RECORDED AS dt,
                 PLAYTIME AS playtime, SCORE AS score, DISABLED AS disabled, DT_PARSED AS dt_parsed,
                 LENGTH(COALESCE(TRANSCRIPTION, \'\')) AS tr_len';
        if ($withTr) {
            $cols .= ', TRANSCRIPTION AS transcription';
        }
        $st = $pdo->prepare(
            "SELECT $cols FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
             ORDER BY DT_RECORDED ASC LIMIT $limit OFFSET $offset"
        );
        $st->execute([$admin, $from, $to]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $item = [
                'id' => (int)$row['id'],
                'id_admin' => (int)$row['id_admin'],
                'badge' => (string)$row['badge'],
                'dt' => (int)$row['dt'],
                'dt_iso' => date('Y-m-d H:i:s', (int)$row['dt']),
                'playtime' => (int)$row['playtime'],
                'score' => (int)$row['score'],
                'disabled' => (int)$row['disabled'],
                'dt_parsed' => (int)$row['dt_parsed'],
                'tr_len' => (int)$row['tr_len'],
            ];
            if ($withTr) {
                $tr = (string)($row['transcription'] ?? '');
                if (mb_strlen($tr) > 2000) {
                    $tr = mb_substr($tr, 0, 2000) . '…';
                }
                $item['transcription'] = $tr;
            }
            $items[] = $item;
        }
        return [
            'ok' => true,
            'admin' => $admin,
            'date' => $date,
            'total' => $total,
            'items' => $items,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function recordGet(): array
    {
        $in = AgentOps::requestInput();
        $id = (int)($in['id'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        if (!Database::tableExists('base_records')) {
            return ['ok' => false, 'error' => 'no_records_table', 'http' => 500];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ADMIN AS id_admin, ID_DICTOPHONE AS badge, DT_RECORDED AS dt,
                    DT_PARSED AS dt_parsed, PLAYTIME AS playtime, SCORE AS score, DISABLED AS disabled,
                    QUALITY AS quality, FILENAME AS filename, TRANSCRIPTION AS transcription,
                    KEY_TYPES AS key_types
             FROM base_records WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        $instLimit = max(1, min(1000, (int)($in['instances_limit'] ?? 400)));
        $instances = [];
        if (Database::tableExists('base_records_instances')) {
            $si = Database::pdo()->prepare(
                'SELECT i.ID AS id, i.DT_START AS dt_start, i.DT_END AS dt_end, i.ID_KEY AS id_key,
                        i.STRING_NUM AS string_num, i.STRING_POSITION AS string_position, i.LENGTH AS length,
                        k.NAME AS key_name
                 FROM base_records_instances i
                 LEFT JOIN base_records_keys k ON k.ID = i.ID_KEY
                 WHERE i.ID_RECORD = ?
                 ORDER BY i.DT_START ASC
                 LIMIT ' . $instLimit
            );
            $si->execute([$id]);
            foreach ($si->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ir) {
                $instances[] = [
                    'id' => (int)$ir['id'],
                    'dt_start' => (int)$ir['dt_start'],
                    'dt_end' => (int)$ir['dt_end'],
                    'id_key' => (int)$ir['id_key'],
                    'key_name' => (string)($ir['key_name'] ?? ''),
                    'string_num' => (int)$ir['string_num'],
                    'string_position' => (int)$ir['string_position'],
                    'length' => (int)$ir['length'],
                ];
            }
        }
        return [
            'ok' => true,
            'record' => [
                'id' => (int)$row['id'],
                'id_admin' => (int)$row['id_admin'],
                'badge' => (string)$row['badge'],
                'dt' => (int)$row['dt'],
                'dt_iso' => date('Y-m-d H:i:s', (int)$row['dt']),
                'dt_parsed' => (int)$row['dt_parsed'],
                'playtime' => (int)$row['playtime'],
                'score' => (int)$row['score'],
                'disabled' => (int)$row['disabled'],
                'quality' => (int)$row['quality'],
                'filename' => (string)$row['filename'],
                'key_types' => (string)($row['key_types'] ?? ''),
                'transcription' => (string)($row['transcription'] ?? ''),
            ],
            'instances' => $instances,
            'instances_count' => count($instances),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function collectionsList(): array
    {
        $bounds = self::parseAdminDate();
        if (isset($bounds['error'])) {
            return $bounds;
        }
        [$admin, $date, $from, $to] = [$bounds['admin'], $bounds['date'], $bounds['from'], $bounds['to']];
        $in = AgentOps::requestInput();
        $script = (int)($in['script'] ?? 0);
        $limit = max(1, min(500, (int)($in['limit'] ?? 200)));
        if (!Database::tableExists('base_records_collections')) {
            return ['ok' => false, 'error' => 'no_collections_table', 'http' => 500];
        }
        $where = ['c.ID_ADMIN = ?', 'c.DT_START >= ?', 'c.DT_START <= ?'];
        $params = [$admin, $from, $to];
        if ($script > 0) {
            $where[] = 'c.ID_SCRIPT = ?';
            $params[] = $script;
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_collections c WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $hasGate = Database::columnExists('base_records_collections', 'GATE_REASON');
        $st = $pdo->prepare(
            'SELECT c.ID AS id, c.ID_ADMIN AS id_admin, c.ID_SCRIPT AS id_script,
                    c.DT_START AS dt_start, c.DT_END AS dt_end, c.SCORE AS score,
                    c.SCORE_RATE AS score_rate, c.RATED AS rated'
            . ($hasGate ? ', c.GATE_REASON AS gate_reason' : '')
            . " FROM base_records_collections c
             WHERE $sqlWhere
             ORDER BY c.DT_START ASC
             LIMIT $limit"
        );
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $sid = (int)$row['id_script'];
            $cid = (int)$row['id'];
            $datasN = 0;
            if (Database::tableExists('base_records_collections_datas')) {
                $ds = $pdo->prepare('SELECT COUNT(*) FROM base_records_collections_datas WHERE ID_COLLECTION = ?');
                $ds->execute([$cid]);
                $datasN = (int)$ds->fetchColumn();
            }
            $items[] = [
                'id' => $cid,
                'id_admin' => (int)$row['id_admin'],
                'id_script' => $sid,
                'script_name' => self::scriptName($sid),
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
                'dt_start_iso' => date('Y-m-d H:i:s', (int)$row['dt_start']),
                'dt_end_iso' => date('Y-m-d H:i:s', (int)$row['dt_end']),
                'score' => (int)$row['score'],
                'score_rate' => (float)$row['score_rate'],
                'rated' => (int)$row['rated'],
                'gate_reason' => (string)($row['gate_reason'] ?? ''),
                'datas_count' => $datasN,
            ];
        }
        return [
            'ok' => true,
            'admin' => $admin,
            'date' => $date,
            'script' => $script,
            'total' => $total,
            'items' => $items,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function collectionGet(): array
    {
        $in = AgentOps::requestInput();
        $id = (int)($in['id'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        if (!Database::tableExists('base_records_collections')) {
            return ['ok' => false, 'error' => 'no_collections_table', 'http' => 500];
        }
        $hasGate = Database::columnExists('base_records_collections', 'GATE_REASON');
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ADMIN AS id_admin, ID_SCRIPT AS id_script,
                    DT_START AS dt_start, DT_END AS dt_end, SCORE AS score,
                    SCORE_RATE AS score_rate, RATED AS rated'
            . ($hasGate ? ', GATE_REASON AS gate_reason' : '')
            . ' FROM base_records_collections WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        $datasLimit = max(1, min(1000, (int)($in['datas_limit'] ?? 400)));
        $datas = [];
        if (Database::tableExists('base_records_collections_datas')) {
            $sd = Database::pdo()->prepare(
                'SELECT d.ID AS id, d.DT_START AS dt_start, d.ID_PART AS id_part, d.ID_INSTANCE AS id_instance,
                        d.ID_KEY AS id_key, p.NAME AS part_name, k.NAME AS key_name
                 FROM base_records_collections_datas d
                 LEFT JOIN base_records_scripts p ON p.ID = d.ID_PART
                 LEFT JOIN base_records_keys k ON k.ID = d.ID_KEY
                 WHERE d.ID_COLLECTION = ?
                 ORDER BY d.DT_START ASC
                 LIMIT ' . $datasLimit
            );
            $sd->execute([$id]);
            foreach ($sd->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dr) {
                $datas[] = [
                    'id' => (int)$dr['id'],
                    'dt_start' => (int)$dr['dt_start'],
                    'dt_iso' => date('Y-m-d H:i:s', (int)$dr['dt_start']),
                    'id_part' => (int)$dr['id_part'],
                    'part_name' => (string)($dr['part_name'] ?? ''),
                    'id_instance' => (int)$dr['id_instance'],
                    'id_key' => (int)$dr['id_key'],
                    'key_name' => (string)($dr['key_name'] ?? ''),
                ];
            }
        }
        $sid = (int)$row['id_script'];
        return [
            'ok' => true,
            'collection' => [
                'id' => (int)$row['id'],
                'id_admin' => (int)$row['id_admin'],
                'id_script' => $sid,
                'script_name' => self::scriptName($sid),
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
                'dt_start_iso' => date('Y-m-d H:i:s', (int)$row['dt_start']),
                'dt_end_iso' => date('Y-m-d H:i:s', (int)$row['dt_end']),
                'score' => (int)$row['score'],
                'score_rate' => (float)$row['score_rate'],
                'rated' => (int)$row['rated'],
                'gate_reason' => (string)($row['gate_reason'] ?? ''),
            ],
            'datas' => $datas,
            'datas_count' => count($datas),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function instancesList(): array
    {
        $bounds = self::parseAdminDate();
        if (isset($bounds['error'])) {
            return $bounds;
        }
        [$admin, $date, $from, $to] = [$bounds['admin'], $bounds['date'], $bounds['from'], $bounds['to']];
        $in = AgentOps::requestInput();
        $record = (int)($in['record'] ?? 0);
        $key = (int)($in['key'] ?? 0);
        $limit = max(1, min(1000, (int)($in['limit'] ?? 200)));
        $offset = max(0, (int)($in['offset'] ?? 0));
        if (!Database::tableExists('base_records_instances')) {
            return ['ok' => false, 'error' => 'no_instances_table', 'http' => 500];
        }
        $where = ['i.ID_ADMIN = ?', 'i.DT_START >= ?', 'i.DT_START <= ?'];
        $params = [$admin, $from, $to];
        if ($record > 0) {
            $where[] = 'i.ID_RECORD = ?';
            $params[] = $record;
        }
        if ($key > 0) {
            $where[] = 'i.ID_KEY = ?';
            $params[] = $key;
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_instances i WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $st = $pdo->prepare(
            "SELECT i.ID AS id, i.ID_RECORD AS id_record, i.ID_KEY AS id_key, i.ID_ADMIN AS id_admin,
                    i.DT_START AS dt_start, i.DT_END AS dt_end, i.STRING_NUM AS string_num,
                    i.STRING_POSITION AS string_position, i.LENGTH AS length,
                    k.NAME AS key_name
             FROM base_records_instances i
             LEFT JOIN base_records_keys k ON k.ID = i.ID_KEY
             WHERE $sqlWhere
             ORDER BY i.DT_START ASC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'id_record' => (int)$row['id_record'],
                'id_key' => (int)$row['id_key'],
                'key_name' => (string)($row['key_name'] ?? ''),
                'id_admin' => (int)$row['id_admin'],
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
                'dt_iso' => date('Y-m-d H:i:s', (int)$row['dt_start']),
                'string_num' => (int)$row['string_num'],
                'string_position' => (int)$row['string_position'],
                'length' => (int)$row['length'],
            ];
        }
        // Compact key histogram for the same filter (top 40).
        $hist = [];
        $sh = $pdo->prepare(
            "SELECT i.ID_KEY AS id_key, COUNT(*) AS n, k.NAME AS key_name
             FROM base_records_instances i
             LEFT JOIN base_records_keys k ON k.ID = i.ID_KEY
             WHERE $sqlWhere
             GROUP BY i.ID_KEY
             ORDER BY n DESC
             LIMIT 40"
        );
        $sh->execute($params);
        foreach ($sh->fetchAll(PDO::FETCH_ASSOC) ?: [] as $hr) {
            $hist[] = [
                'id_key' => (int)$hr['id_key'],
                'key_name' => (string)($hr['key_name'] ?? ''),
                'n' => (int)$hr['n'],
            ];
        }
        return [
            'ok' => true,
            'admin' => $admin,
            'date' => $date,
            'record' => $record,
            'key' => $key,
            'total' => $total,
            'items' => $items,
            'by_key' => $hist,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function dayReparse(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        $bounds = self::parseAdminDate($in);
        if (isset($bounds['error'])) {
            return $bounds;
        }
        [$admin, $date, $from, $to] = [$bounds['admin'], $bounds['date'], $bounds['from'], $bounds['to']];
        $doReset = !isset($in['reset']) || !in_array(strtolower((string)$in['reset']), ['0', 'false', 'no'], true);
        $budget = max(30, min(300, (int)($in['time_budget_sec'] ?? 180)));

        if (!class_exists('StemRunner', false) || !class_exists('CollectRunner', false)) {
            return ['ok' => false, 'error' => 'pipeline_runners_missing', 'http' => 500];
        }
        if (!class_exists('JobLock', false)) {
            return ['ok' => false, 'error' => 'job_lock_missing', 'http' => 500];
        }
        $lock = JobLock::tryAcquire('stem');
        if ($lock === null) {
            return ['ok' => false, 'error' => 'busy', 'message' => 'stem lock held', 'http' => 423];
        }
        $out = [
            'ok' => true,
            'admin' => $admin,
            'date' => $date,
            'reset' => null,
            'stem' => null,
            'collect' => null,
            'pending_after' => null,
            'http' => 200,
        ];
        try {
            StemRunner::invalidateCaches();
            if ($doReset) {
                $out['reset'] = StemRunner::resetDay($from, $to, $admin, 0);
            }
            $deadline = microtime(true) + $budget;
            $proc = 0;
            $skip = 0;
            $batches = 0;
            $pending = StemRunner::countPendingForDay($date);
            while ($pending > 0 && microtime(true) < $deadline) {
                $batches++;
                $remain = max(15, (int)ceil($deadline - microtime(true)));
                $stem = StemRunner::run([
                    'day' => $date,
                    'admin' => $admin,
                    'unparsed_only' => true,
                    'collect' => false,
                    'limit' => 700,
                    'time_budget_sec' => $remain,
                ]);
                $proc += (int)($stem['processed'] ?? 0);
                $skip += (int)($stem['skipped'] ?? 0);
                $pending = StemRunner::countPendingForDay($date);
                if ((int)($stem['processed'] ?? 0) < 1 && (int)($stem['skipped'] ?? 0) < 1) {
                    break;
                }
            }
            $out['stem'] = [
                'batches' => $batches,
                'processed' => $proc,
                'skipped' => $skip,
                'pending_after' => $pending,
            ];
            $out['pending_after'] = $pending;
            $collBudget = max(20, (int)ceil($deadline - microtime(true)));
            $out['collect'] = CollectRunner::run(false, [$admin], [
                'start' => $from,
                'end' => $to,
                'time_budget_sec' => $collBudget,
            ]);
            $diag = self::dayDiag();
            $out['after'] = [
                'instances' => $diag['instances'] ?? null,
                'collections_total' => $diag['collections_total'] ?? null,
                'collections' => $diag['collections'] ?? [],
            ];
            if ($pending > 0) {
                $out['ok'] = true;
                $out['message'] = 'reparse_partial_pending';
            } else {
                $out['message'] = 'reparse_done';
            }
        } catch (Throwable $e) {
            $out = ['ok' => false, 'error' => $e->getMessage(), 'http' => 500];
        } finally {
            $lock->release();
        }
        return $out;
    }

    /**
     * Backfill PLAYTIME from transcript cue ends where PLAYTIME is 0.
     *
     * @return array<string,mixed>
     */
    public static function backfillPlaytime(): array
    {
        $in = AgentOps::requestInput();
        $dry = !isset($in['dry_run']) || !in_array(strtolower((string)$in['dry_run']), ['0', 'false', 'no'], true);
        if (!$dry && !self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required when dry_run=0', 'http' => 400];
        }
        $admin = isset($in['admin']) ? (int)$in['admin'] : null;
        if ($admin !== null && $admin < 1) {
            $admin = null;
        }
        $date = trim((string)($in['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return ['ok' => false, 'error' => 'date=YYYY-MM-DD', 'http' => 400];
        }
        if ($date === '') {
            $date = null;
        }
        $limit = max(1, min(50000, (int)($in['limit'] ?? 5000)));
        if (!class_exists('AsrService', false)) {
            return ['ok' => false, 'error' => 'AsrService missing', 'http' => 500];
        }
        $res = AsrService::backfillPlaytimeFromTranscripts($date, $admin, $dry, $limit);
        $res['http'] = !empty($res['ok']) ? 200 : 500;

        return $res;
    }

    /**
     * @param array<string,mixed>|null $in
     * @return array{admin:int,date:string,from:int,to:int}|array{ok:false,error:string,http:int}
     */
    private static function parseAdminDate(?array $in = null): array
    {
        $in = $in ?? AgentOps::requestInput();
        $admin = (int)($in['admin'] ?? 0);
        $date = trim((string)($in['date'] ?? ''));
        if ($admin < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'error' => 'admin (int) and date=YYYY-MM-DD required', 'http' => 400];
        }
        $from = strtotime($date . ' 00:00:00');
        $to = strtotime($date . ' 23:59:59');
        if ($from === false || $to === false) {
            return ['ok' => false, 'error' => 'bad date', 'http' => 400];
        }
        return ['admin' => $admin, 'date' => $date, 'from' => $from, 'to' => $to];
    }

    /** @return array<string,string> */
    private static function moduleVersions(): array
    {
        $out = [];
        foreach (['core', 'audio', 'audio_dicts', 'audio_pipeline', 'audio_reports'] as $id) {
            $path = base_path('modules/' . $id . '/module.json');
            if (!is_file($path)) {
                continue;
            }
            $j = json_decode((string)file_get_contents($path), true);
            $out[$id] = is_array($j) ? (string)($j['version'] ?? '') : '';
        }
        return $out;
    }

    private static function scriptName(int $id): string
    {
        if ($id < 1 || !Database::tableExists('base_records_scripts')) {
            return '';
        }
        $st = Database::pdo()->prepare('SELECT NAME FROM base_records_scripts WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        return (string)($st->fetchColumn() ?: '');
    }

    /** @return array{parts:list<array<string,mixed>>,hash:string} */
    private static function scriptFingerprint(int $scriptId): array
    {
        $parts = [];
        if ($scriptId < 1 || !Database::tableExists('base_records_scripts')) {
            return ['parts' => [], 'hash' => ''];
        }
        $hasGap = Database::columnExists('base_records_scripts', 'BREAK_GAP');
        $st = Database::pdo()->prepare(
            'SELECT ID, PID, NUM, NAME, `BREAK`, TIMEOUT, FINISH, START_GATE,
                    COLLECTION_KILLER, KPI, SCORE, DISABLED, IDS_DICTONARYS'
            . ($hasGap ? ', BREAK_GAP' : '')
            . ' FROM base_records_scripts
             WHERE ID = ? OR PID = ?
             ORDER BY PID ASC, NUM ASC, ID ASC
             LIMIT 120'
        );
        $st->execute([$scriptId, $scriptId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $parts[] = [
                'id' => (int)$row['ID'],
                'pid' => (int)$row['PID'],
                'num' => (int)$row['NUM'],
                'name' => (string)$row['NAME'],
                'break' => (int)$row['BREAK'],
                'timeout' => (int)$row['TIMEOUT'],
                'finish' => (int)$row['FINISH'],
                'start_gate' => (int)$row['START_GATE'],
                'killer' => (int)$row['COLLECTION_KILLER'],
                'kpi' => (int)$row['KPI'],
                'score' => (int)$row['SCORE'],
                'disabled' => (int)$row['DISABLED'],
                'ids_dictonarys' => (string)$row['IDS_DICTONARYS'],
                'break_gap' => $hasGap ? (int)$row['BREAK_GAP'] : null,
            ];
        }
        return ['parts' => $parts, 'hash' => substr(sha1(json_encode($parts) ?: ''), 0, 12)];
    }

    /** @param array<string,mixed> $in */
    private static function confirmed(array $in): bool
    {
        $c = $in['confirm'] ?? null;
        if ($c === true || $c === 1 || $c === '1') {
            return true;
        }
        return is_string($c) && in_array(strtolower($c), ['1', 'true', 'yes'], true);
    }
}
