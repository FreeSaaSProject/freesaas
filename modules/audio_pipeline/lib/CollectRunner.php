<?php
declare(strict_types=1);

/**
 * Script collection builder from base_records_instances.
 * Port of oper recordCollector + rating gates (DIVERSITY / INSTANCES_MIN / metric COND_*).
 */
final class CollectRunner
{
    /** Allowed COND_TYPE values on metric parts. */
    private const COND_TYPES = [
        'intersect', 'no_intersect',
        'volume_gt', 'volume_lt',
        'strings_gt', 'strings_lt',
    ];

    /**
     * @param list<int>|null $onlyAdminIds when set, rebuild only these admins (day-scoped reparse)
     * @param array{start?:int,end?:int,time_budget_sec?:int}|null $window optional DT window
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function run(bool $fullRebuild = false, ?array $onlyAdminIds = null, ?array $window = null): array
    {
        JobRunner::applyTimeLimit(isset($window['time_budget_sec']) ? (int)$window['time_budget_sec'] : null);
        $deadline = JobRunner::deadline(isset($window['time_budget_sec']) ? (int)$window['time_budget_sec'] : null);

        if (!Database::tableExists('base_records_instances')
            || !Database::tableExists('base_records_collections')
            || !Database::tableExists('base_records_scripts')
        ) {
            return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'message' => 'tables_missing'];
        }

        $pdo = Database::pdo();
        $winStart = max(0, (int)($window['start'] ?? 0));
        $winEnd = max(0, (int)($window['end'] ?? 0));
        $hasWindow = ($winStart > 0 && $winEnd >= $winStart);

        // Full-table orphan sweep is expensive on large instances (~1M+ rows).
        // Only on explicit full rebuild — never on incremental stem/cron collect
        // (shared hosting often kills long DELETE with MySQL 1317 → HTTP 500 HTML).
        if ($fullRebuild && !$hasWindow) {
            $pdo->exec(
                'DELETE FROM base_records_instances
                 WHERE ID_RECORD > 0 AND ID_RECORD NOT IN (SELECT ID FROM base_records)'
            );
        }

        $scripts = self::loadScripts();
        if ($scripts['by_id'] === []) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'no_scripts'];
        }

        $adminIds = [];
        if ($onlyAdminIds !== null) {
            foreach ($onlyAdminIds as $aid) {
                $aid = (int)$aid;
                if ($aid > 0) {
                    $adminIds[] = $aid;
                }
            }
            $adminIds = array_values(array_unique($adminIds));
        } elseif ($fullRebuild) {
            $st = $pdo->query('SELECT DISTINCT ID_ADMIN FROM base_records_instances WHERE ID_ADMIN > 0');
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $aid) {
                $adminIds[] = (int)$aid;
            }
        } else {
            $sql = 'SELECT i.ID_ADMIN AS id_admin,
                           MAX(i.DT_START) AS max_i,
                           COALESCE(MAX(c.DT_START), 0) AS max_c
                    FROM base_records_instances i
                    LEFT JOIN base_records_collections c ON c.ID_ADMIN = i.ID_ADMIN
                    WHERE i.ID_ADMIN > 0
                    GROUP BY i.ID_ADMIN
                    HAVING max_i > max_c
                    LIMIT 50';
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $adminIds[] = (int)$row['id_admin'];
            }
        }

        $processed = 0;
        $collections = 0;
        $offersTotal = 0;
        foreach ($adminIds as $adminId) {
            if (JobRunner::timedOut($deadline)) {
                break;
            }
            if ($hasWindow) {
                self::deleteCollectionsInWindow($pdo, $adminId, $winStart, $winEnd);
            } else {
                $ids = $pdo->prepare('SELECT ID FROM base_records_collections WHERE ID_ADMIN = ?');
                $ids->execute([$adminId]);
                $colIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if ($colIds !== []) {
                    $in = implode(',', $colIds);
                    $pdo->exec("DELETE FROM base_records_collections_datas WHERE ID_COLLECTION IN ($in)");
                    $pdo->prepare('DELETE FROM base_records_collections WHERE ID_ADMIN = ?')->execute([$adminId]);
                }
            }
            $built = self::rebuildAdmin(
                $adminId,
                $scripts,
                $hasWindow ? $winStart : 0,
                $hasWindow ? $winEnd : 0
            );
            $collections += (int)($built['collections'] ?? 0);
            $offersTotal += (int)($built['offers'] ?? 0);

            // Soft metrics (e.g. likespa bracelet scans after openings) before SCORE.
            EventBus::emit('audio_pipeline.collect.after_admin', [
                'admin_id' => $adminId,
                'window_start' => $hasWindow ? $winStart : 0,
                'window_end' => $hasWindow ? $winEnd : 0,
                'collections' => (int)($built['collections'] ?? 0),
            ]);

            // Derived record SCORE from rated collections.
            if (class_exists('RatingAggregates', false)) {
                RatingAggregates::applyRecordScores(
                    $adminId,
                    $hasWindow ? $winStart : 0,
                    $hasWindow ? $winEnd : 0
                );
            }
            $processed++;
        }

        return [
            'ok' => true,
            'processed' => $processed,
            'skipped' => 0,
            'message' => 'collect_done',
            'extra' => [
                'collections' => $collections,
                'admins' => count($adminIds),
                'window' => $hasWindow,
                'offers' => $offersTotal,
            ],
        ];
    }

    private static function deleteCollectionsInWindow(PDO $pdo, int $adminId, int $winStart, int $winEnd): void
    {
        $st = $pdo->prepare(
            'SELECT ID FROM base_records_collections
             WHERE ID_ADMIN = ?
               AND DT_START <= ?
               AND (DT_END = 0 OR DT_END >= ?)'
        );
        $st->execute([$adminId, $winEnd, $winStart]);
        $colIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($colIds === []) {
            return;
        }
        $in = implode(',', $colIds);
        $pdo->exec("DELETE FROM base_records_collections_datas WHERE ID_COLLECTION IN ($in)");
        $pdo->exec("DELETE FROM base_records_collections WHERE ID IN ($in)");
    }

    /**
     * @return array{
     *   by_id:array<int,array<string,mixed>>,
     *   roots:array<int,array<string,mixed>>,
     *   key_to_parts:array<int,list<array{script:int,part:int}>>,
     *   metric_parts:array<int,list<array<string,mixed>>>
     * }
     */
    private static function loadScripts(): array
    {
        $byId = [];
        $roots = [];
        $keyToParts = [];
        $metricParts = [];

        $hasRating = Database::columnExists('base_records_scripts', 'RATING_ENABLED');
        $hasScoreRate = Database::columnExists('base_records_scripts', 'SCORE_RATE');
        $hasKpi = Database::columnExists('base_records_scripts', 'KPI');
        $hasCond = Database::columnExists('base_records_scripts', 'COND_TYPE');
        $hasStartGate = Database::columnExists('base_records_scripts', 'START_GATE');
        $hasExtendTimeout = Database::columnExists('base_records_scripts', 'EXTEND_TIMEOUT');

        $hasPosition = Database::columnExists('base_records_scripts', 'ID_POSITION');
        $extra = '';
        if ($hasRating) {
            $extra .= ', RATING_ENABLED, IS_HIDDEN, LEGACY_TYPE_ID';
        }
        if ($hasScoreRate) {
            $extra .= ', SCORE_RATE';
        }
        if ($hasKpi) {
            $extra .= ', KPI';
        }
        if ($hasCond) {
            $extra .= ', COND_TYPE, COND_VALUE';
        }
        if ($hasStartGate) {
            $extra .= ', START_GATE';
        }
        if ($hasExtendTimeout) {
            $extra .= ', EXTEND_TIMEOUT';
        }
        if ($hasPosition) {
            $extra .= ', ID_POSITION';
        }

        $rows = Database::pdo()->query(
            "SELECT ID, PID, NAME, DISABLED, IS_REQ, FINISH, SCORE, `BREAK`, TIMEOUT,
                    DIVERSITY, INSTANCES_MIN, COLLECTION_KILLER, IDS_DICTONARYS
                    {$extra}
             FROM base_records_scripts WHERE DISABLED < 1"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $id = (int)$row['ID'];
            $pid = (int)$row['PID'];
            $meta = [
                'id' => $id,
                'pid' => $pid,
                'name' => (string)($row['NAME'] ?? ''),
                'is_req' => (int)$row['IS_REQ'],
                'finish' => (int)$row['FINISH'],
                'score' => (int)$row['SCORE'],
                'score_rate' => $hasScoreRate ? (int)$row['SCORE_RATE'] : 0,
                'break' => (int)$row['BREAK'],
                'timeout' => (int)$row['TIMEOUT'],
                'diversity' => (int)$row['DIVERSITY'],
                'instances_min' => (int)$row['INSTANCES_MIN'],
                'killer' => (int)$row['COLLECTION_KILLER'],
                'kpi' => $hasKpi ? (int)$row['KPI'] : 0,
                'cond_type' => $hasCond ? trim((string)($row['COND_TYPE'] ?? '')) : '',
                'cond_value' => $hasCond ? (int)($row['COND_VALUE'] ?? 0) : 0,
                'start_gate' => $hasStartGate ? max(0, min(2, (int)($row['START_GATE'] ?? 0))) : 0,
                // Missing column → legacy: every hit extends timeout.
                'extend_timeout' => $hasExtendTimeout ? ((int)($row['EXTEND_TIMEOUT'] ?? 1) > 0 ? 1 : 0) : 1,
                'ids_dictonarys' => (string)($row['IDS_DICTONARYS'] ?? ''),
                'id_position' => $hasPosition ? (string)($row['ID_POSITION'] ?? '') : '',
                'rating_enabled' => $hasRating ? (int)($row['RATING_ENABLED'] ?? 0) : 0,
                'is_hidden' => $hasRating ? (int)($row['IS_HIDDEN'] ?? 0) : 0,
            ];
            $byId[$id] = $meta;
            if ($pid === 0) {
                $roots[$id] = $meta;
                $metricParts[$id] = [];
            }
        }

        // Metric parts (no dictionaries, has COND_TYPE) hang under their root.
        foreach ($byId as $id => $meta) {
            $pid = (int)$meta['pid'];
            if ($pid < 1) {
                continue;
            }
            $cond = (string)$meta['cond_type'];
            $dicts = self::parseIds((string)$meta['ids_dictonarys']);
            if ($cond !== '' && in_array($cond, self::COND_TYPES, true) && $dicts === []) {
                if (!isset($metricParts[$pid])) {
                    $metricParts[$pid] = [];
                }
                $metricParts[$pid][] = $meta;
            }
        }

        if (Database::tableExists('base_records_keys')) {
            $keys = Database::pdo()->query(
                'SELECT ID, ID_DICTONARY FROM base_records_keys WHERE DISABLED < 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $keysByDict = [];
            foreach ($keys as $k) {
                $did = (int)$k['ID_DICTONARY'];
                if ($did > 0) {
                    $keysByDict[$did][] = (int)$k['ID'];
                }
            }
            foreach ($rows as $row) {
                $partId = (int)$row['ID'];
                if ((int)$row['PID'] === 0) {
                    continue;
                }
                $cond = $hasCond ? trim((string)($row['COND_TYPE'] ?? '')) : '';
                $dicts = self::parseIds((string)($row['IDS_DICTONARYS'] ?? ''));
                // Metric-only parts are not keyed.
                if ($cond !== '' && $dicts === []) {
                    continue;
                }
                foreach ($dicts as $did) {
                    foreach ($keysByDict[$did] ?? [] as $keyId) {
                        $keyToParts[$keyId][] = ['script' => (int)$row['PID'], 'part' => $partId];
                    }
                }
            }
        }

        return [
            'by_id' => $byId,
            'roots' => $roots,
            'key_to_parts' => $keyToParts,
            'metric_parts' => $metricParts,
        ];
    }

    /** @return list<int> */
    private static function parseIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[#,\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Script roots allowed for this staff member by root ID_POSITION.
     * Empty / "0" on the root = all positions (legacy).
     *
     * @param array<int,array<string,mixed>> $roots
     * @return array<int,true> scriptId => true
     */
    private static function scriptIdsAllowedForAdmin(int $adminId, array $roots): array
    {
        $adminPos = self::adminPositionId($adminId);
        $posIds = [$adminPos];
        if ($adminPos > 0 && class_exists('SummariesRepository', false)) {
            $eq = SummariesRepository::equivalentAudioPositionIds($adminPos);
            if ($eq !== []) {
                $posIds = $eq;
            }
        }
        $posSet = [];
        foreach ($posIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) {
                $posSet[$pid] = true;
            }
        }

        $allowed = [];
        foreach ($roots as $sid => $root) {
            $sid = (int)$sid;
            if ($sid < 1) {
                continue;
            }
            $posRaw = trim((string)($root['id_position'] ?? ''));
            if ($posRaw === '' || $posRaw === '0') {
                $allowed[$sid] = true;
                continue;
            }
            if ($posSet === []) {
                // Staff has no status — only global scripts apply.
                continue;
            }
            $scriptPos = self::parseIds($posRaw);
            // Also accept plain "12" without # delimiters (parseIds handles it).
            if ($scriptPos === [] && ctype_digit($posRaw)) {
                $scriptPos = [(int)$posRaw];
            }
            foreach ($scriptPos as $spid) {
                if (isset($posSet[$spid])) {
                    $allowed[$sid] = true;
                    break;
                }
            }
        }

        return $allowed;
    }

    private static function adminPositionId(int $adminId): int
    {
        if ($adminId < 1 || !Database::tableExists('base_users')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT ID_STATUS FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$adminId]);
        return (int)$st->fetchColumn();
    }

    /**
     * @param array{by_id:array,roots:array,key_to_parts:array,metric_parts:array} $scripts
     * @return array{collections:int,offers:int}
     */
    private static function rebuildAdmin(int $adminId, array $scripts, int $winStart = 0, int $winEnd = 0): array
    {
        if ($winStart > 0 && $winEnd >= $winStart) {
            $st = Database::pdo()->prepare(
                'SELECT ID, DT_START, DT_END, ID_KEY, ID_RECORD
                 FROM base_records_instances
                 WHERE ID_ADMIN = ? AND DT_START >= ? AND DT_START <= ?
                 ORDER BY DT_START ASC, ID ASC'
            );
            $st->execute([$adminId, $winStart, $winEnd]);
        } else {
            $st = Database::pdo()->prepare(
                'SELECT ID, DT_START, DT_END, ID_KEY, ID_RECORD
                 FROM base_records_instances
                 WHERE ID_ADMIN = ?
                 ORDER BY DT_START ASC, ID ASC'
            );
            $st->execute([$adminId]);
        }
        $instances = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($instances === []) {
            return ['collections' => 0, 'offers' => 0];
        }

        // Root ID_POSITION: empty/0 = all roles; else only matching staff (incl. ID_AUDIO_POSITION peers).
        $allowedScripts = self::scriptIdsAllowedForAdmin($adminId, $scripts['roots'] ?? []);

        /** @var array<int, array{save:int,finish:int,timeout:int,last:int,kpi_last:int,parts:array<int,list<array>>,offers:int}> $open */
        $open = [];
        $saved = 0;
        $offersTotal = 0;

        foreach ($instances as $inst) {
            $keyId = (int)$inst['ID_KEY'];
            $dtStart = (int)$inst['DT_START'];
            $dtEnd = (int)$inst['DT_END'] > 0 ? (int)$inst['DT_END'] : $dtStart;
            $matches = $scripts['key_to_parts'][$keyId] ?? [];
            if ($matches === []) {
                continue;
            }

            foreach ($matches as $m) {
                $scriptId = (int)$m['script'];
                if (!isset($allowedScripts[$scriptId])) {
                    continue;
                }
                $partId = (int)$m['part'];
                $partMeta = $scripts['by_id'][$partId] ?? null;
                if ($partMeta === null) {
                    continue;
                }

                if (!isset($open[$scriptId])) {
                    $open[$scriptId] = [
                        'save' => 0,
                        'finish' => 0,
                        'timeout' => max(0, (int)$partMeta['timeout']),
                        'last' => $dtEnd,
                        'kpi_last' => 0,
                        'parts' => [],
                        'offers' => 0,
                    ];
                } else {
                    $sinse = $dtStart - $open[$scriptId]['last'];
                    $limit = $open[$scriptId]['timeout'];
                    if ($limit > 0 && $sinse > $limit) {
                        $flushed = self::flushOpen($adminId, $scriptId, $open[$scriptId], $scripts);
                        $saved += $flushed['saved'];
                        $offersTotal += $flushed['offers'];
                        unset($open[$scriptId]);
                        $open[$scriptId] = [
                            'save' => 0,
                            'finish' => 0,
                            'timeout' => max(0, (int)$partMeta['timeout']),
                            'last' => $dtEnd,
                            'kpi_last' => 0,
                            'parts' => [],
                            'offers' => 0,
                        ];
                    }
                    // BREAK reopen: new reception after mid content / repeat greeting.
                    // Do NOT reopen while still gathering primary START_GATE=1 parts
                    // (должность + представиться must share one collection).
                    if (self::shouldBreakReopen($scriptId, $open[$scriptId], $partMeta, $scripts)) {
                        $flushed = self::flushOpen($adminId, $scriptId, $open[$scriptId], $scripts);
                        $saved += $flushed['saved'];
                        $offersTotal += $flushed['offers'];
                        unset($open[$scriptId]);
                        $open[$scriptId] = [
                            'save' => 0,
                            'finish' => 0,
                            'timeout' => max(0, (int)$partMeta['timeout']),
                            'last' => $dtEnd,
                            'kpi_last' => 0,
                            'parts' => [],
                            'offers' => 0,
                        ];
                    }
                }

                if (!empty($partMeta['killer'])) {
                    // Conversion already reached (FINISH part met): persist, then close.
                    // Without conversion: abort — drop open session without writing.
                    if (!empty($open[$scriptId]['finish']) && !empty($open[$scriptId]['save'])) {
                        $flushed = self::flushOpen($adminId, $scriptId, $open[$scriptId], $scripts);
                        $saved += $flushed['saved'];
                        $offersTotal += $flushed['offers'];
                    }
                    unset($open[$scriptId]);
                    continue;
                }

                // KPI gap (seconds on part.KPI): skip hit if too close to previous KPI hit.
                $kpiGap = max(0, (int)($partMeta['kpi'] ?? 0));
                $isKpi = $kpiGap > 0;
                if ($isKpi && $open[$scriptId]['kpi_last'] > 0) {
                    if ($dtStart - $open[$scriptId]['kpi_last'] < $kpiGap) {
                        continue;
                    }
                }

                $open[$scriptId]['parts'][$partId][] = [
                    'id' => (int)$inst['ID'],
                    'dt_start' => $dtStart,
                    'dt_end' => $dtEnd,
                    'id_key' => $keyId,
                    'id_record' => (int)$inst['ID_RECORD'],
                    'score' => (int)$partMeta['score'],
                    'score_rate' => (int)$partMeta['score_rate'],
                ];
                // Only refresh idle TIMEOUT when part opts in (EXTEND_TIMEOUT).
                if (!empty($partMeta['extend_timeout'])) {
                    $open[$scriptId]['timeout'] = max(0, (int)$partMeta['timeout']);
                }
                $open[$scriptId]['last'] = $dtEnd;

                if ($isKpi) {
                    $open[$scriptId]['kpi_last'] = $dtEnd;
                    $open[$scriptId]['offers']++;
                }

                // FINISH always saves when limits met.
                if (!empty($partMeta['finish'])
                    && self::partLimitsMet($partMeta, $open[$scriptId]['parts'][$partId] ?? [])
                ) {
                    $open[$scriptId]['finish'] = 1;
                    $open[$scriptId]['save'] = 1;
                } else {
                    $open[$scriptId]['save'] = self::evaluateSaveGate($scriptId, $open[$scriptId], $scripts);
                }
            }
        }

        foreach ($open as $scriptId => $state) {
            $flushed = self::flushOpen($adminId, $scriptId, $state, $scripts);
            $saved += $flushed['saved'];
            $offersTotal += $flushed['offers'];
        }

        // Propagate OFFERS onto overlapping records (sum of KPI hits from collections built).
        if ($offersTotal > 0 && $winStart > 0) {
            self::bumpOffersOnRecords($adminId, $winStart, $winEnd, $offersTotal);
        }

        return ['collections' => $saved, 'offers' => $offersTotal];
    }

    /**
     * @param array<string,mixed> $partMeta
     * @param list<array<string,mixed>> $instances
     */
    private static function partLimitsMet(array $partMeta, array $instances): bool
    {
        // No hits → not met (important for START_GATE=1 AND: missing primary
        // must not pass when DIVERSITY/INSTANCES_MIN are both 0).
        if ($instances === []) {
            return false;
        }
        $divReq = (int)($partMeta['diversity'] ?? 0);
        $instReq = (int)($partMeta['instances_min'] ?? 0);
        if ($divReq < 1 && $instReq < 1) {
            return true;
        }
        $keys = [];
        foreach ($instances as $inst) {
            $kid = (int)($inst['id_key'] ?? 0);
            if ($kid > 0) {
                $keys[$kid] = true;
            }
        }
        $diversity = count($keys);
        $count = count($instances);
        if ($divReq > 0 && $diversity < $divReq) {
            return false;
        }
        if ($instReq > 0 && $count < $instReq) {
            return false;
        }
        return true;
    }

    /**
     * Whether a BREAK part should flush the open collection before recording the hit.
     *
     * Reopen when:
     * - this same part already present (repeat greeting → new session), or
     * - open already has non-primary (START_GATE≠1) parts (mid/finish), or
     * - all primary START_GATE=1 parts are already met (session started; new greeting → new), or
     * - script has no primary gates (legacy: any parts → reopen).
     *
     * Stay open when only other primary-start parts are present (dual-start AND).
     *
     * @param array{save:int,finish:int,timeout:int,last:int,kpi_last?:int,parts:array,offers?:int} $state
     * @param array<string,mixed> $partMeta
     * @param array{by_id:array} $scripts
     */
    private static function shouldBreakReopen(int $scriptId, array $state, array $partMeta, array $scripts): bool
    {
        if (empty($partMeta['break']) || empty($state['parts'])) {
            return false;
        }
        $partId = (int)($partMeta['id'] ?? 0);
        if ($partId > 0 && isset($state['parts'][$partId])) {
            return true;
        }
        $byId = $scripts['by_id'] ?? [];
        $primaryIds = [];
        foreach ($byId as $p) {
            if ((int)($p['pid'] ?? 0) !== $scriptId) {
                continue;
            }
            if ((int)($p['start_gate'] ?? 0) === 1) {
                $primaryIds[] = (int)$p['id'];
            }
        }
        $hasNonPrimary = false;
        foreach ($state['parts'] as $pid => $_) {
            $meta = $byId[(int)$pid] ?? null;
            $gate = $meta !== null ? (int)($meta['start_gate'] ?? 0) : 0;
            if ($gate !== 1) {
                $hasNonPrimary = true;
                break;
            }
        }
        if ($hasNonPrimary) {
            return true;
        }
        if ($primaryIds === []) {
            return true;
        }
        foreach ($primaryIds as $pid) {
            $meta = $byId[$pid] ?? null;
            if ($meta === null || !self::partLimitsMet($meta, $state['parts'][$pid] ?? [])) {
                // Still collecting primary AND — keep open.
                return false;
            }
        }
        // Primary gate already complete → another BREAK greeting starts a new session.
        return true;
    }

    /**
     * Dual-start save gate:
     * - START_GATE=1 (primary AND): all such parts must meet limits
     * - START_GATE=2 (alt OR): any one meeting limits is enough
     * - no START_GATE>0 on script: legacy any IS_REQ → save
     *
     * @param array{save:int,finish:int,timeout:int,last:int,kpi_last?:int,parts:array,offers?:int} $state
     * @param array{by_id:array} $scripts
     */
    private static function evaluateSaveGate(int $scriptId, array $state, array $scripts): int
    {
        if (!empty($state['finish'])) {
            return 1;
        }
        $byId = $scripts['by_id'] ?? [];
        $primaryIds = [];
        $altIds = [];
        foreach ($byId as $part) {
            if ((int)($part['pid'] ?? 0) !== $scriptId) {
                continue;
            }
            $gate = (int)($part['start_gate'] ?? 0);
            if ($gate === 1) {
                $primaryIds[] = (int)$part['id'];
            } elseif ($gate === 2) {
                $altIds[] = (int)$part['id'];
            }
        }

        if ($primaryIds === [] && $altIds === []) {
            foreach ($state['parts'] as $partId => $instances) {
                $meta = $byId[(int)$partId] ?? null;
                if ($meta !== null
                    && !empty($meta['is_req'])
                    && self::partLimitsMet($meta, $instances)
                ) {
                    return 1;
                }
            }
            return 0;
        }

        foreach ($altIds as $partId) {
            $meta = $byId[$partId] ?? null;
            if ($meta !== null && self::partLimitsMet($meta, $state['parts'][$partId] ?? [])) {
                return 1;
            }
        }

        if ($primaryIds !== []) {
            foreach ($primaryIds as $partId) {
                $meta = $byId[$partId] ?? null;
                if ($meta === null || !self::partLimitsMet($meta, $state['parts'][$partId] ?? [])) {
                    return 0;
                }
            }
            return 1;
        }

        return 0;
    }

    /**
     * @param array{save:int,finish:int,timeout:int,last:int,kpi_last?:int,parts:array,offers?:int} $state
     * @param array{by_id:array,roots:array,metric_parts:array} $scripts
     * @return array{saved:int,offers:int}
     */
    private static function flushOpen(int $adminId, int $scriptId, array $state, array $scripts): array
    {
        if (empty($state['save']) || empty($state['parts'])) {
            return ['saved' => 0, 'offers' => 0];
        }
        $score = 0;
        $scoreRateKey = 0;
        $dtStart = 0;
        $dtEnd = 0;
        $datas = [];
        $recordIds = [];
        foreach ($state['parts'] as $partId => $instances) {
            $partScore = 0;
            $partRate = null;
            foreach ($instances as $inst) {
                $partScore = max($partScore, (int)$inst['score']);
                $r = (int)($inst['score_rate'] ?? 0);
                if ($partRate === null || abs($r) > abs((int)$partRate)) {
                    $partRate = $r;
                }
                if ($dtStart === 0 || $inst['dt_start'] < $dtStart) {
                    $dtStart = (int)$inst['dt_start'];
                }
                $iend = (int)($inst['dt_end'] ?? $inst['dt_start']);
                if ($iend > $dtEnd) {
                    $dtEnd = $iend;
                }
                if ((int)($inst['id_record'] ?? 0) > 0) {
                    $recordIds[(int)$inst['id_record']] = true;
                }
                $datas[] = [
                    'part' => (int)$partId,
                    'instance' => (int)$inst['id'],
                    'key' => (int)$inst['id_key'],
                    'dt' => (int)$inst['dt_start'],
                ];
            }
            $score += $partScore;
            $scoreRateKey += (int)$partRate;
        }
        if ($dtStart < 1) {
            return ['saved' => 0, 'offers' => 0];
        }
        if ($dtEnd < $dtStart) {
            $dtEnd = $dtStart;
        }

        $root = $scripts['roots'][$scriptId] ?? ($scripts['by_id'][$scriptId] ?? []);
        $ratingEnabled = (int)($root['rating_enabled'] ?? 0) > 0;
        $metricParts = $scripts['metric_parts'][$scriptId] ?? [];

        $scoreRate = $scoreRateKey;
        $rated = $ratingEnabled ? 1 : 0;
        $gateReason = '';

        // Skip heavy metrics query when script has no metric conditions.
        $metrics = $metricParts !== []
            ? self::loadWindowMetrics($adminId, $dtStart, $dtEnd, array_keys($recordIds))
            : [
                'intersect' => 0,
                'volume_max' => 0,
                'volume_min' => 0,
                'strings' => 0,
                'records' => 0,
            ];

        foreach ($metricParts as $mp) {
            $condMet = self::evalMetricCondition(
                (string)$mp['cond_type'],
                (int)$mp['cond_value'],
                $metrics
            );
            $isReq = !empty($mp['is_req']);
            $isKiller = !empty($mp['killer']);
            $mpRate = (int)$mp['score_rate'];

            if ($isKiller && $condMet) {
                $rated = 0;
                $gateReason = 'metric_killer:' . (string)$mp['cond_type'];
                continue;
            }
            if ($isReq && !$condMet) {
                $rated = 0;
                $gateReason = 'metric_req:' . (string)$mp['cond_type'];
                continue;
            }
            // Bonus / penalty when condition holds (and not used only as gate).
            if ($condMet && !$isKiller && (!$isReq || $condMet)) {
                if (!$isReq || $condMet) {
                    $scoreRate += $mpRate;
                }
            }
        }

        // If rating disabled, still store collection for funnel UI, but RATED=0.
        if (!$ratingEnabled) {
            $rated = 0;
            if ($gateReason === '') {
                $gateReason = '';
            }
        }

        $pdo = Database::pdo();
        $hasScoreRate = Database::columnExists('base_records_collections', 'SCORE_RATE');
        $hasRated = Database::columnExists('base_records_collections', 'RATED');

        if ($hasScoreRate && $hasRated) {
            $pdo->prepare(
                'INSERT INTO base_records_collections
                 (DT_START, DT_END, ID_ADMIN, ID_SCRIPT, SCORE, SCORE_RATE, RATED, GATE_REASON)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $dtStart, $dtEnd, $adminId, $scriptId, $score,
                $scoreRate, $rated, mb_substr($gateReason, 0, 32),
            ]);
        } elseif ($hasScoreRate) {
            $pdo->prepare(
                'INSERT INTO base_records_collections
                 (DT_START, DT_END, ID_ADMIN, ID_SCRIPT, SCORE, SCORE_RATE)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$dtStart, $dtEnd, $adminId, $scriptId, $score, $scoreRate]);
        } else {
            $pdo->prepare(
                'INSERT INTO base_records_collections (DT_START, DT_END, ID_ADMIN, ID_SCRIPT, SCORE)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$dtStart, $dtEnd, $adminId, $scriptId, $score]);
        }
        $colId = (int)$pdo->lastInsertId();
        if ($colId < 1) {
            return ['saved' => 0, 'offers' => 0];
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_records_collections_datas
             (DT_START, ID_COLLECTION, ID_PART, ID_INSTANCE, ID_KEY) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($datas as $d) {
            $ins->execute([$d['dt'], $colId, $d['part'], $d['instance'], $d['key']]);
        }
        return ['saved' => 1, 'offers' => (int)($state['offers'] ?? 0)];
    }

    /**
     * @param list<int> $recordIds
     * @return array{intersect:int,volume_max:int,volume_min:int,strings:int,records:int}
     */
    private static function loadWindowMetrics(int $adminId, int $dtStart, int $dtEnd, array $recordIds): array
    {
        $out = [
            'intersect' => 0,
            'volume_max' => 0,
            'volume_min' => 0,
            'strings' => 0,
            'records' => 0,
        ];
        if (!Database::tableExists('base_records')) {
            return $out;
        }
        $pdo = Database::pdo();
        $ids = array_values(array_filter(array_map('intval', $recordIds), static fn ($i) => $i > 0));
        $rows = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            $rows = $pdo->query(
                "SELECT ID, VOLUME, STRINGS, STRINGS_NOK, INTERSECTIONS, DT_RECORDED, PLAYTIME
                 FROM base_records WHERE ID IN ($in)"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        // Overlap via DT_RECORDED range (index-friendly). Expression
        // (DT_RECORDED+PLAYTIME) forces a near-full partition scan (~0.6s each).
        $pad = 4 * 3600;
        $st = $pdo->prepare(
            'SELECT ID, VOLUME, STRINGS, STRINGS_NOK, INTERSECTIONS, DT_RECORDED, PLAYTIME
             FROM base_records
             WHERE ID_ADMIN = ?
               AND DT_RECORDED >= ?
               AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED < 1)
             LIMIT 200'
        );
        $st->execute([$adminId, max(0, $dtStart - $pad), $dtEnd + 60]);
        $seen = [];
        foreach (array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rid = (int)$row['ID'];
            if (isset($seen[$rid])) {
                continue;
            }
            $rStart = (int)($row['DT_RECORDED'] ?? 0);
            $rEnd = $rStart + max(0, (int)($row['PLAYTIME'] ?? 0));
            // True time overlap with collection window (pad only for fetch).
            if ($rEnd < $dtStart || $rStart > $dtEnd) {
                // Keep explicit ID list rows even if outside pad overlap.
                if ($ids === [] || !in_array($rid, $ids, true)) {
                    continue;
                }
            }
            $seen[$rid] = true;
            $out['records']++;
            $vol = (int)($row['VOLUME'] ?? 0);
            if ($out['records'] === 1) {
                $out['volume_min'] = $vol;
                $out['volume_max'] = $vol;
            } else {
                if ($vol > $out['volume_max']) {
                    $out['volume_max'] = $vol;
                }
                if ($vol < $out['volume_min']) {
                    $out['volume_min'] = $vol;
                }
            }
            $strings = (int)($row['STRINGS'] ?? 0);
            if ($strings < 1) {
                $strings = (int)($row['STRINGS_NOK'] ?? 0);
            }
            $out['strings'] += $strings;
            $rawInter = (string)($row['INTERSECTIONS'] ?? '');
            if ($rawInter !== '') {
                $un = @unserialize($rawInter, ['allowed_classes' => false]);
                if (is_array($un) && $un !== []) {
                    $out['intersect'] = 1;
                }
            }
        }
        return $out;
    }

    /**
     * @param array{intersect:int,volume_max:int,volume_min:int,strings:int,records:int} $metrics
     */
    private static function evalMetricCondition(string $condType, int $condValue, array $metrics): bool
    {
        switch ($condType) {
            case 'intersect':
                return (int)$metrics['intersect'] > 0;
            case 'no_intersect':
                return (int)$metrics['intersect'] < 1;
            case 'volume_gt':
                return (int)$metrics['volume_max'] > $condValue;
            case 'volume_lt':
                return (int)$metrics['records'] > 0 && (int)$metrics['volume_min'] < $condValue;
            case 'strings_gt':
                return (int)$metrics['strings'] > $condValue;
            case 'strings_lt':
                return (int)$metrics['strings'] < $condValue;
            default:
                return false;
        }
    }

    private static function bumpOffersOnRecords(int $adminId, int $winStart, int $winEnd, int $offers): void
    {
        // OFFERS is per-record; CollectRunner accumulates KPI hits into collections.
        // Stem still writes OFFERS from type KPI; we leave record OFFERS alone here
        // to avoid double-counting until types are retired. No-op for now.
        unset($adminId, $winStart, $winEnd, $offers);
    }
}
