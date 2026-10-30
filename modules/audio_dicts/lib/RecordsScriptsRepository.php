<?php
declare(strict_types=1);

/**
 * Recognition scripts tree: root (PID=0, EL_TYPE=0) + parts (PID=root, EL_TYPE=1).
 */
final class RecordsScriptsRepository
{
    /**
     * Allowed COND_TYPE values on parts (collect activators + day-quality metrics).
     *
     * @return list<string>
     */
    public static function allowedCondTypes(): array
    {
        return [
            'intersect', 'no_intersect',
            'volume_gt', 'volume_lt',
            'strings_gt', 'strings_lt',
            'term_scan_unique',
            'proc_created',
        ];
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 's.ID', 'name' => 's.NAME', 'disabled' => 's.DISABLED', 'num' => 's.NUM'];
    }

    /**
     * @param array{q?:string,position?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function listRoots(array $opts = []): array
    {
        if (!Database::tableExists('base_records_scripts')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['s.PID = 0'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.NAME LIKE ? OR CAST(s.ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $q;
        }
        $positionId = (int)($opts['position'] ?? 0);
        if ($positionId > 0) {
            // Empty / 0 = all positions; else exact id or #id# token (joinIds / legacy)
            $where[] = '(s.ID_POSITION IS NULL OR TRIM(s.ID_POSITION) = \'\' OR s.ID_POSITION = \'0\'
                        OR s.ID_POSITION = ? OR s.ID_POSITION LIKE ?)';
            $params[] = (string)$positionId;
            $params[] = '%#' . $positionId . '#%';
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_scripts s WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 's.ID DESC';
        // Disabled scripts always last (unless the user sorts by disabled explicitly)
        if (!preg_match('/\bDISABLED\b/i', $order)) {
            $order = 's.DISABLED ASC, ' . $order;
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $ratingCols = Database::columnExists('base_records_scripts', 'RATING_ENABLED')
            ? ', s.RATING_ENABLED AS rating_enabled, s.IS_HIDDEN AS is_hidden, s.LEGACY_TYPE_ID AS legacy_type_id'
            : ', 0 AS rating_enabled, 0 AS is_hidden, 0 AS legacy_type_id';
        $dayCols = Database::columnExists('base_records_scripts', 'DAY_BONUS')
            ? ', s.DAY_BONUS AS day_bonus, s.DAY_BONUS_SUM AS day_bonus_sum'
            : ', 0 AS day_bonus, 0 AS day_bonus_sum';
        $sql = "SELECT s.ID AS id, s.DT_CREATE AS dt_create, s.NAME AS name, s.DISABLED AS disabled,
                       s.NUM AS num, s.ID_POSITION AS id_position
                       {$ratingCols}{$dayCols},
                       (SELECT COUNT(*) FROM base_records_scripts p WHERE p.PID = s.ID) AS parts_count
                FROM base_records_scripts s
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($items as &$row) {
            $row['position_ids'] = self::parseIds((string)($row['id_position'] ?? ''));
            $row['parts_count'] = (int)($row['parts_count'] ?? 0);
            $row['disabled'] = (int)($row['disabled'] ?? 0);
            $row['rating_enabled'] = (int)($row['rating_enabled'] ?? 0);
            $row['day_bonus'] = (int)($row['day_bonus'] ?? 0);
            $row['day_bonus_sum'] = (int)($row['day_bonus_sum'] ?? 0);
            $row['is_hidden'] = (int)($row['is_hidden'] ?? 0);
            $row['legacy_type_id'] = (int)($row['legacy_type_id'] ?? 0);
            $row['parts'] = [];
        }
        unset($row);

        $rootIds = array_map(static fn (array $r): int => (int)$r['id'], $items);
        $partsByRoot = self::partsSummaryForRoots($rootIds);
        foreach ($items as &$row) {
            $row['parts'] = $partsByRoot[(int)$row['id']] ?? [];
            $row['parts_count'] = count($row['parts']);
        }
        unset($row);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Compact parts for list UI: name + roles + timeouts/gates, ordered by NUM.
     *
     * @param list<int> $rootIds
     * @return array<int,list<array<string,mixed>>>
     */
    public static function partsSummaryForRoots(array $rootIds): array
    {
        $rootIds = array_values(array_filter(array_map('intval', $rootIds), static fn ($i) => $i > 0));
        $out = [];
        foreach ($rootIds as $rid) {
            $out[$rid] = [];
        }
        if ($rootIds === [] || !Database::tableExists('base_records_scripts')) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($rootIds), '?'));
        $rateCol = Database::columnExists('base_records_scripts', 'SCORE_RATE')
            ? ', SCORE_RATE AS score_rate' : ', 0 AS score_rate';
        $kpiCol = Database::columnExists('base_records_scripts', 'KPI')
            ? ', KPI AS kpi' : ', 0 AS kpi';
        $timeoutCol = Database::columnExists('base_records_scripts', 'TIMEOUT')
            ? ', TIMEOUT AS timeout' : ', 0 AS timeout';
        $extendCol = Database::columnExists('base_records_scripts', 'EXTEND_TIMEOUT')
            ? ', EXTEND_TIMEOUT AS extend_timeout' : ', 1 AS extend_timeout';
        $gateCol = Database::columnExists('base_records_scripts', 'GATE_RED_MAX')
            ? ', GATE_RED_MAX AS gate_red_max, GATE_YELLOW_MAX AS gate_yellow_max'
            : ', 0 AS gate_red_max, 0 AS gate_yellow_max';
        $closerCol = Database::columnExists('base_records_scripts', 'CLOSER')
            ? ', CLOSER AS closer' : ', 0 AS closer';
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, PID AS pid, NAME AS name, NUM AS num,
                    IS_REQ AS is_req, FINISH AS finish, `BREAK` AS break_flag,
                    COLLECTION_KILLER AS collection_killer, SCORE AS score
                    {$rateCol}{$kpiCol}{$timeoutCol}{$extendCol}{$gateCol}{$closerCol}
             FROM base_records_scripts
             WHERE PID IN ($in)
             ORDER BY PID ASC, NUM ASC, ID ASC"
        );
        $st->execute($rootIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pid = (int)$row['pid'];
            if (!isset($out[$pid])) {
                continue;
            }
            $out[$pid][] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'roles' => self::rolesFromFlags(
                    (int)$row['is_req'],
                    (int)$row['finish'],
                    (int)$row['break_flag'],
                    (int)$row['collection_killer'],
                    (int)($row['closer'] ?? 0)
                ),
                'score' => (int)$row['score'],
                'score_rate' => (int)($row['score_rate'] ?? 0),
                'timeout' => (int)($row['timeout'] ?? 0),
                'extend_timeout' => array_key_exists('extend_timeout', $row)
                    ? (!empty($row['extend_timeout']) ? 1 : 0)
                    : 1,
                'kpi' => (int)($row['kpi'] ?? 0),
                'gate_red_max' => (float)($row['gate_red_max'] ?? 0),
                'gate_yellow_max' => (float)($row['gate_yellow_max'] ?? 0),
            ];
        }
        return $out;
    }

    public static function setDisabled(int $id, bool $disabled): void
    {
        if ($id < 1 || !Database::tableExists('base_records_scripts')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_records_scripts SET DISABLED = ? WHERE ID = ? AND PID = 0'
        );
        $st->execute([$disabled ? 1 : 0, $id]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'scripts', 'id' => $id]);
    }

    /**
     * @return array{root:array<string,mixed>,parts:list<array<string,mixed>>}|null
     */
    public static function findWithParts(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_scripts')) {
            return null;
        }
        $root = self::findRow($id);
        if ($root === null || (int)$root['pid'] !== 0) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . self::selectColumnsSql() . '
             FROM base_records_scripts
             WHERE PID = ?
             ORDER BY NUM ASC, ID ASC'
        );
        $st->execute([$id]);
        $parts = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $parts[] = self::normalizePart($row);
        }
        return ['root' => self::normalizeRoot($root), 'parts' => $parts];
    }

    public static function find(int $id): ?array
    {
        $row = self::findRow($id);
        if ($row === null) {
            return null;
        }
        return (int)$row['pid'] === 0 ? self::normalizeRoot($row) : self::normalizePart($row);
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<array<string,mixed>> $parts
     */
    public static function create(array $meta, array $parts): int
    {
        $name = trim((string)($meta['name'] ?? ''));
        if ($name !== '' && self::rootExistsByName($name)) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.name_taken', ['name' => $name]));
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $rootId = 0;
        try {
            if (Database::columnExists('base_records_scripts', 'RATING_ENABLED')) {
                $hasDayBonus = Database::columnExists('base_records_scripts', 'DAY_BONUS');
                $hasDayGates = Database::columnExists('base_records_scripts', 'DAY_GATES');
                $st = $pdo->prepare(
                    'INSERT INTO base_records_scripts
                     (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
                      IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
                      COLLECTION_KILLER, ID_POSITION, RATING_ENABLED, IS_HIDDEN, LEGACY_TYPE_ID,
                      SCORE_RATE, KPI, COND_TYPE, COND_VALUE'
                    . ($hasDayBonus ? ', DAY_BONUS, DAY_BONUS_SUM, PROC_RATIO_MIN' : '')
                    . ($hasDayGates ? ', DAY_GATES' : '')
                    . ')
                     VALUES (0, 0, 0, ?, ?, ?, ?, 0, 0, \'\', 0, 0, 0, 0, 0, 0, ?, ?, ?, ?, 0, 0, \'\', 0'
                    . ($hasDayBonus ? ', ?, ?, ?' : '')
                    . ($hasDayGates ? ', ?' : '')
                    . ')'
                );
                $params = [
                    (int)($meta['num'] ?? 0),
                    $meta['name'],
                    time(),
                    (int)($meta['disabled'] ?? 0),
                    $meta['id_position'] ?? '',
                    (int)($meta['rating_enabled'] ?? 0),
                    (int)($meta['is_hidden'] ?? 0),
                    (int)($meta['legacy_type_id'] ?? 0),
                ];
                if ($hasDayBonus) {
                    $params[] = (int)($meta['day_bonus'] ?? 0);
                    $params[] = max(0, (int)($meta['day_bonus_sum'] ?? 0));
                    $params[] = self::legacyProcRatioMinFromMeta($meta);
                }
                if ($hasDayGates) {
                    $params[] = self::encodeDayGates(is_array($meta['day_gates'] ?? null) ? $meta['day_gates'] : []);
                }
                $st->execute($params);
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO base_records_scripts
                     (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
                      IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
                      COLLECTION_KILLER, ID_POSITION)
                     VALUES (0, 0, 0, ?, ?, ?, ?, 0, 0, \'\', 0, 0, 0, 0, 0, 0, ?)'
                );
                $st->execute([
                    (int)($meta['num'] ?? 0),
                    $meta['name'],
                    time(),
                    (int)($meta['disabled'] ?? 0),
                    $meta['id_position'] ?? '',
                ]);
            }
            $rootId = (int)$pdo->lastInsertId();
            if ($rootId < 1) {
                throw new RuntimeException(t('audio_dicts.scripts.err.create_failed'));
            }
            self::insertParts($pdo, $rootId, $parts);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        EventBus::emit('audio_dicts.changed', ['entity' => 'scripts', 'id' => $rootId]);
        return $rootId;
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<array<string,mixed>> $parts
     */
    public static function update(int $id, array $meta, array $parts): void
    {
        $tree = self::findWithParts($id);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $id]));
        }
        $name = trim((string)($meta['name'] ?? ''));
        if ($name !== '' && self::rootExistsByName($name, $id)) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.name_taken', ['name' => $name]));
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if (Database::columnExists('base_records_scripts', 'RATING_ENABLED')) {
                $hasDayBonus = Database::columnExists('base_records_scripts', 'DAY_BONUS');
                $hasDayGates = Database::columnExists('base_records_scripts', 'DAY_GATES');
                $st = $pdo->prepare(
                    'UPDATE base_records_scripts SET NAME=?, DISABLED=?, NUM=?, ID_POSITION=?,
                     RATING_ENABLED=?, IS_HIDDEN=?, LEGACY_TYPE_ID=?'
                    . ($hasDayBonus ? ', DAY_BONUS=?, DAY_BONUS_SUM=?, PROC_RATIO_MIN=?' : '')
                    . ($hasDayGates ? ', DAY_GATES=?' : '')
                    . ' WHERE ID=? AND PID=0'
                );
                $params = [
                    $meta['name'],
                    (int)($meta['disabled'] ?? 0),
                    (int)($meta['num'] ?? 0),
                    $meta['id_position'] ?? '',
                    (int)($meta['rating_enabled'] ?? 0),
                    (int)($meta['is_hidden'] ?? 0),
                    (int)($meta['legacy_type_id'] ?? 0),
                ];
                if ($hasDayBonus) {
                    $params[] = (int)($meta['day_bonus'] ?? 0);
                    $params[] = max(0, (int)($meta['day_bonus_sum'] ?? 0));
                    $params[] = self::legacyProcRatioMinFromMeta($meta);
                }
                if ($hasDayGates) {
                    $params[] = self::encodeDayGates(is_array($meta['day_gates'] ?? null) ? $meta['day_gates'] : []);
                }
                $params[] = $id;
                $st->execute($params);
            } else {
                $st = $pdo->prepare(
                    'UPDATE base_records_scripts SET NAME=?, DISABLED=?, NUM=?, ID_POSITION=? WHERE ID=? AND PID=0'
                );
                $st->execute([
                    $meta['name'],
                    (int)($meta['disabled'] ?? 0),
                    (int)($meta['num'] ?? 0),
                    $meta['id_position'] ?? '',
                    $id,
                ]);
            }

            $keep = [];
            $ordered = [];
            $existingIds = [];
            foreach ($tree['parts'] as $p) {
                $existingIds[(int)$p['id']] = true;
            }
            foreach (array_values($parts) as $i => $part) {
                $partId = (int)($part['id'] ?? 0);
                $num = $i + 1;
                $flags = self::flagsFromPart($part);
                $dicts = self::joinIds(is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : self::parseIds((string)($part['ids_dictonarys'] ?? '')));
                $useExisting = $partId > 0 && isset($existingIds[$partId]);
                if ($useExisting) {
                    self::updatePartRow($pdo, $partId, $id, $num, $part, $flags, $dicts);
                    $keep[] = $partId;
                    $ordered[] = $partId;
                } else {
                    $newId = self::insertPartRow($pdo, $id, $num, $part, $flags, $dicts);
                    $keep[] = $newId;
                    $ordered[] = $newId;
                }
            }

            // Delete removed parts
            $toDelete = array_diff(array_keys($existingIds), $keep);
            if ($toDelete !== []) {
                $in = implode(',', array_fill(0, count($toDelete), '?'));
                $del = $pdo->prepare("DELETE FROM base_records_scripts WHERE ID IN ($in) AND PID = ?");
                $del->execute(array_merge(array_values($toDelete), [$id]));
            }

            self::linkNextQids($pdo, $ordered);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        EventBus::emit('audio_dicts.changed', ['entity' => 'scripts', 'id' => $id]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if ($ids === [] || !Database::tableExists('base_records_scripts')) {
            return 0;
        }
        $pdo = Database::pdo();
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Only roots
        $st = $pdo->prepare("SELECT ID FROM base_records_scripts WHERE ID IN ($in) AND PID = 0");
        $st->execute($ids);
        $roots = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($roots === []) {
            return 0;
        }
        $rin = implode(',', array_fill(0, count($roots), '?'));
        $pdo->prepare("DELETE FROM base_records_scripts WHERE PID IN ($rin)")->execute($roots);
        $del = $pdo->prepare("DELETE FROM base_records_scripts WHERE ID IN ($rin) AND PID = 0");
        $del->execute($roots);
        EventBus::emit('audio_dicts.changed', ['entity' => 'scripts']);
        return $del->rowCount();
    }

    public static function copy(int $id): int
    {
        $tree = self::findWithParts($id);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $id]));
        }
        $root = $tree['root'];
        $name = mb_substr((string)$root['name'] . ' ' . t('audio_dicts.scripts.copy_suffix'), 0, 40);
        $meta = self::metaFromRoot($root);
        $meta['name'] = $name;
        $parts = [];
        foreach ($tree['parts'] as $p) {
            $parts[] = [
                'name' => (string)$p['name'],
                'disabled' => (int)$p['disabled'],
                'dict_ids' => $p['dict_ids'],
                'score' => (int)$p['score'],
                'score_rate' => (int)($p['score_rate'] ?? 0),
                'kpi' => (int)($p['kpi'] ?? 0),
                'cond_type' => (string)($p['cond_type'] ?? ''),
                'cond_value' => (int)($p['cond_value'] ?? 0),
                'start_gate' => max(0, min(2, (int)($p['start_gate'] ?? 0))),
                'extend_timeout' => array_key_exists('extend_timeout', $p)
                    ? (!empty($p['extend_timeout']) ? 1 : 0)
                    : 1,
                'timeout' => (int)$p['timeout'],
                'diversity' => (int)$p['diversity'],
                'instances_min' => (int)$p['instances_min'],
                'role' => (string)($p['roles'][0] ?? $p['role'] ?? 'ordinary'),
                'roles' => is_array($p['roles'] ?? null) ? $p['roles'] : self::rolesFromFlags(
                    (int)($p['is_req'] ?? 0),
                    (int)($p['finish'] ?? 0),
                    (int)($p['break_flag'] ?? 0),
                    (int)($p['collection_killer'] ?? 0),
                    (int)($p['closer'] ?? 0)
                ),
                'collection_killer' => (int)$p['collection_killer'],
                'id_position' => (string)($p['id_position'] ?? ''),
            ];
        }
        return self::create($meta, $parts);
    }

    /**
     * Create or update a single part; returns part row.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function savePart(int $scriptId, array $data): array
    {
        $tree = self::findWithParts($scriptId);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $scriptId]));
        }
        $partId = (int)($data['id'] ?? 0);
        $parts = $tree['parts'];
        $found = false;
        if ($partId > 0) {
            foreach ($parts as $i => $p) {
                if ((int)$p['id'] === $partId) {
                    $parts[$i] = array_merge($p, $data, ['id' => $partId]);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.err.part_not_found', ['id' => $partId]));
            }
        } else {
            $parts[] = $data;
        }
        self::update($scriptId, self::metaFromRoot($tree['root']), $parts);
        $fresh = self::findWithParts($scriptId);
        if ($partId > 0) {
            foreach ($fresh['parts'] as $p) {
                if ((int)$p['id'] === $partId) {
                    return $p;
                }
            }
        }
        // New part = last by num
        $last = $fresh['parts'][count($fresh['parts']) - 1] ?? null;
        if ($last === null) {
            throw new RuntimeException(t('audio_dicts.scripts.err.create_failed'));
        }
        return $last;
    }

    /** @param list<int> $orderedPartIds */
    public static function reorderParts(int $scriptId, array $orderedPartIds): void
    {
        $tree = self::findWithParts($scriptId);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $scriptId]));
        }
        $byId = [];
        foreach ($tree['parts'] as $p) {
            $byId[(int)$p['id']] = $p;
        }
        $ordered = [];
        foreach ($orderedPartIds as $pid) {
            $pid = (int)$pid;
            if (isset($byId[$pid])) {
                $ordered[] = $byId[$pid];
                unset($byId[$pid]);
            }
        }
        foreach ($byId as $p) {
            $ordered[] = $p;
        }
        self::update($scriptId, self::metaFromRoot($tree['root']), $ordered);
    }

    public static function deletePart(int $scriptId, int $partId): void
    {
        $tree = self::findWithParts($scriptId);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $scriptId]));
        }
        $parts = array_values(array_filter(
            $tree['parts'],
            static fn (array $p): bool => (int)$p['id'] !== $partId
        ));
        self::update($scriptId, self::metaFromRoot($tree['root']), $parts);
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function metaFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('audio_dicts.err.name_required'));
        }
        $posRaw = $post['id_position'] ?? $post['positions'] ?? [];
        if (!is_array($posRaw)) {
            $posRaw = self::parseIds((string)$posRaw);
        }
        return [
            'name' => mb_substr($name, 0, 40),
            'disabled' => !empty($post['disabled']) ? 1 : 0,
            'num' => max(0, (int)($post['num'] ?? 0)),
            'id_position' => self::joinIds($posRaw),
            'rating_enabled' => !empty($post['rating_enabled']) ? 1 : 0,
            'is_hidden' => !empty($post['is_hidden']) ? 1 : 0,
            'legacy_type_id' => max(0, (int)($post['legacy_type_id'] ?? 0)),
            'day_bonus' => !empty($post['day_bonus']) ? 1 : 0,
            'day_bonus_sum' => max(0, (int)($post['day_bonus_sum'] ?? 0)),
            'day_gates' => self::dayGatesFromPost($post),
            'proc_ratio_min' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return list<array{metric:string,op:string,threshold:float,label:string}>
     */
    public static function dayGatesFromPost(array $post): array
    {
        $raw = $post['day_gates'] ?? null;
        if (is_string($post['day_gates_json'] ?? null) && trim((string)$post['day_gates_json']) !== '') {
            $decoded = json_decode((string)$post['day_gates_json'], true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        return self::normalizeDayGates($raw);
    }

    /**
     * @param mixed $raw
     * @return list<array{metric:string,op:string,threshold:float,label:string}>
     */
    public static function normalizeDayGates($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ops = ['gt', 'gte', 'lt', 'lte'];
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $metric = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($row['metric'] ?? '')))) ?? '';
            if ($metric === '') {
                continue;
            }
            $op = strtolower(trim((string)($row['op'] ?? 'gte')));
            if (!in_array($op, $ops, true)) {
                $op = 'gte';
            }
            $threshold = (float)str_replace(',', '.', (string)($row['threshold'] ?? '0'));
            $label = mb_substr(trim((string)($row['label'] ?? '')), 0, 80);
            $out[] = [
                'metric' => $metric,
                'op' => $op,
                'threshold' => $threshold,
                'label' => $label,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $gates
     */
    public static function encodeDayGates(array $gates): string
    {
        $norm = self::normalizeDayGates($gates);
        if ($norm === []) {
            return '';
        }
        $json = json_encode($norm, JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }

    /**
     * Merge DAY_GATES JSON with legacy PROC_RATIO_MIN when gates empty.
     *
     * @param array<string,mixed> $row
     * @return list<array{metric:string,op:string,threshold:float,label:string}>
     */
    public static function resolveDayGatesFromRow(array $row): array
    {
        $gates = self::normalizeDayGates($row['day_gates'] ?? ($row['DAY_GATES'] ?? null));
        if ($gates !== []) {
            return $gates;
        }
        $min = (int)($row['proc_ratio_min'] ?? ($row['PROC_RATIO_MIN'] ?? 0));
        if ($min > 0) {
            return [[
                'metric' => 'proc_ratio',
                'op' => 'gte',
                'threshold' => (float)$min,
                'label' => '',
            ]];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function legacyProcRatioMinFromMeta(array $meta): int
    {
        $gates = is_array($meta['day_gates'] ?? null) ? $meta['day_gates'] : [];
        foreach ($gates as $g) {
            if (!is_array($g)) {
                continue;
            }
            if (($g['metric'] ?? '') !== 'proc_ratio') {
                continue;
            }
            $op = (string)($g['op'] ?? 'gte');
            if ($op === 'gte' || $op === 'gt') {
                return max(0, (int)round((float)($g['threshold'] ?? 0)));
            }
        }

        return max(0, (int)($meta['proc_ratio_min'] ?? 0));
    }

    /**
     * Parse parts from wizard/canvas POST (`parts[i][…]` or JSON `parts_json`).
     *
     * @param array<string,mixed> $post
     * @return list<array<string,mixed>>
     */
    public static function partsFromPost(array $post): array
    {
        if (!empty($post['parts_json']) && is_string($post['parts_json'])) {
            $decoded = json_decode($post['parts_json'], true);
            if (is_array($decoded)) {
                $post['parts'] = $decoded;
            }
        }
        $raw = $post['parts'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $dictIds = $row['dict_ids'] ?? $row['dicts'] ?? [];
            if (!is_array($dictIds)) {
                $dictIds = self::parseIds((string)$dictIds);
            }
            $roles = $row['roles'] ?? null;
            if (!is_array($roles)) {
                $roles = null;
            }
            if ($roles === null && isset($row['role'])) {
                $roles = self::normalizeRoles([(string)$row['role']]);
            }
            if ($roles === null) {
                $roles = ['ordinary'];
            }
            $roles = self::normalizeRoles($roles);
            $condType = strtolower(trim((string)($row['cond_type'] ?? '')));
            if ($condType === 'term_scan_conversion') {
                $condType = 'term_scan_unique';
            }
            if (!in_array($condType, self::allowedCondTypes(), true)) {
                $condType = '';
            }
            $cleanDicts = array_values(array_filter(array_map('intval', $dictIds), static fn ($i) => $i > 0));
            // Activator is exclusive: metric COND_* OR dictionaries (CollectRunner).
            // Exception: proc_created stores procedure TYPE ids in IDS_DICTONARYS.
            $condValue = (int)($row['cond_value'] ?? 0);
            $diversity = max(0, (int)($row['diversity'] ?? 0));
            $instancesMin = max(0, (int)($row['instances_min'] ?? 0));
            if ($condType !== '') {
                $diversity = 0;
                $instancesMin = 0;
                if ($condType === 'proc_created') {
                    $condValue = 0;
                    // Keep type ids; empty list is invalid for this activator.
                } else {
                    $cleanDicts = [];
                    // intersect / no_intersect are boolean — no threshold
                    if ($condType === 'intersect' || $condType === 'no_intersect') {
                        $condValue = 0;
                    }
                }
            } else {
                $condValue = 0;
            }
            $startGate = max(0, min(2, (int)($row['start_gate'] ?? 0)));
            // START_GATE applies only to trigger parts
            if (!in_array('trigger', $roles, true)) {
                $startGate = 0;
            }
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => mb_substr($name, 0, 40),
                'disabled' => !empty($row['disabled']) ? 1 : 0,
                'dict_ids' => $cleanDicts,
                'score' => (int)($row['score'] ?? 0),
                'score_rate' => (int)($row['score_rate'] ?? 0),
                'kpi' => max(0, (int)($row['kpi'] ?? 0)),
                'cond_type' => $condType,
                'cond_value' => $condValue,
                'start_gate' => $startGate,
                'extend_timeout' => array_key_exists('extend_timeout', $row)
                    ? (!empty($row['extend_timeout']) ? 1 : 0)
                    : 1,
                'timeout' => max(0, (int)($row['timeout'] ?? 0)),
                'diversity' => $diversity,
                'instances_min' => $instancesMin,
                'gate_red_max' => max(0.0, (float)($row['gate_red_max'] ?? 0)),
                'gate_yellow_max' => max(0.0, (float)($row['gate_yellow_max'] ?? 0)),
                'hide_from_reports' => !empty($row['hide_from_reports']) ? 1 : 0,
                'roles' => $roles,
                'role' => $roles[0] ?? 'ordinary',
                'collection_killer' => in_array('killer', $roles, true) ? 1 : 0,
                'id_position' => '',
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $part
     * @return array{is_req:int,finish:int,closer:int,break:int,killer:int}
     */
    public static function flagsFromPart(array $part): array
    {
        $roles = $part['roles'] ?? null;
        if (!is_array($roles)) {
            $single = self::normalizeRole((string)($part['role'] ?? ''));
            if ($single !== '' && $single !== 'ordinary') {
                $roles = [$single];
            } elseif (isset($part['is_req']) || isset($part['finish']) || isset($part['closer'])
                || isset($part['break_flag']) || isset($part['break']) || isset($part['collection_killer'])
            ) {
                $roles = self::rolesFromFlags(
                    (int)($part['is_req'] ?? 0),
                    (int)($part['finish'] ?? 0),
                    (int)($part['break_flag'] ?? $part['break'] ?? 0),
                    (int)($part['collection_killer'] ?? $part['killer'] ?? 0),
                    (int)($part['closer'] ?? 0)
                );
            } else {
                $roles = ['ordinary'];
            }
        }
        $roles = self::normalizeRoles($roles);
        return [
            'is_req' => in_array('trigger', $roles, true) ? 1 : 0,
            'finish' => in_array('conversion', $roles, true) ? 1 : 0,
            'closer' => in_array('closer', $roles, true) ? 1 : 0,
            'break' => in_array('breaker', $roles, true) ? 1 : 0,
            'killer' => in_array('killer', $roles, true) ? 1 : 0,
        ];
    }

    /**
     * @return list<string>
     */
    public static function rolesFromFlags(
        int $isReq,
        int $finish,
        int $break,
        int $killer = 0,
        int $closer = 0
    ): array {
        $roles = [];
        if ($isReq > 0) {
            $roles[] = 'trigger';
        }
        if ($finish > 0) {
            $roles[] = 'conversion';
        }
        if ($closer > 0) {
            $roles[] = 'closer';
        }
        if ($break > 0) {
            $roles[] = 'breaker';
        }
        if ($killer > 0) {
            $roles[] = 'killer';
        }
        return $roles === [] ? ['ordinary'] : $roles;
    }

    /** @deprecated use rolesFromFlags */
    public static function roleFromFlags(int $isReq, int $finish, int $break): string
    {
        $roles = self::rolesFromFlags($isReq, $finish, $break, 0, 0);
        return $roles[0] ?? 'ordinary';
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        $allowed = ['ordinary', 'trigger', 'conversion', 'closer', 'breaker', 'killer'];
        return in_array($role, $allowed, true) ? $role : 'ordinary';
    }

    /**
     * @param list<mixed> $roles
     * @return list<string>
     */
    public static function normalizeRoles(array $roles): array
    {
        $allowed = ['trigger', 'conversion', 'closer', 'breaker', 'killer'];
        $out = [];
        foreach ($roles as $r) {
            $r = self::normalizeRole((string)$r);
            if ($r === 'ordinary') {
                continue;
            }
            if (in_array($r, $allowed, true) && !in_array($r, $out, true)) {
                $out[] = $r;
            }
        }
        // Killer is exclusive: it aborts the open collection and cannot build it.
        if (in_array('killer', $out, true)) {
            return ['killer'];
        }
        return $out === [] ? ['ordinary'] : $out;
    }

    /** @return list<int> */
    public static function parseIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
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

    /** @param list<int|string> $ids */
    public static function joinIds(array $ids): string
    {
        $clean = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $clean[] = $n;
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return '';
        }
        // Oper-style #id# separators; CollectRunner accepts #/,/whitespace
        return '#' . implode('#,#', $clean) . '#';
    }

    /** True if a root script with this exact name already exists. */
    public static function rootExistsByName(string $name, int $excludeId = 0): bool
    {
        $name = trim($name);
        if ($name === '' || !Database::tableExists('base_records_scripts')) {
            return false;
        }
        $sql = 'SELECT ID FROM base_records_scripts WHERE PID = 0 AND NAME = ?';
        $params = [mb_substr($name, 0, 40)];
        if ($excludeId > 0) {
            $sql .= ' AND ID <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn() > 0;
    }

    /**
     * All root script names (for import UI uniqueness check).
     *
     * @return list<string>
     */
    public static function listRootNames(): array
    {
        if (!Database::tableExists('base_records_scripts')) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT NAME AS name FROM base_records_scripts WHERE PID = 0 ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $n = trim((string)($row['name'] ?? ''));
            if ($n !== '') {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** @return array<int,string> status id => name */
    public static function positionOptions(): array
    {
        $opts = StatusesRepository::optionsForAudio();
        unset($opts[0]);
        return $opts;
    }

    /**
     * Positions that appear on at least one root script (for list filter).
     * Empty ID_POSITION (applies to all) does not add every status — only explicit ids.
     *
     * @return array<int,string> status id => name
     */
    public static function positionOptionsUsedInScripts(): array
    {
        $all = self::positionOptions();
        if ($all === [] || !Database::tableExists('base_records_scripts')) {
            return [];
        }
        $used = [];
        $st = Database::pdo()->query(
            "SELECT ID_POSITION FROM base_records_scripts
             WHERE PID = 0 AND ID_POSITION IS NOT NULL AND TRIM(ID_POSITION) <> '' AND ID_POSITION <> '0'"
        );
        foreach ($st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $raw) {
            foreach (self::parseIds((string)$raw) as $pid) {
                $used[$pid] = true;
            }
        }
        $out = [];
        foreach (array_keys($used) as $pid) {
            $pid = (int)$pid;
            if ($pid < 1) {
                continue;
            }
            $out[$pid] = $all[$pid] ?? ('#' . $pid);
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** Shared SELECT column list (aliases) for script rows. */
    private static function selectColumnsSql(): string
    {
        $cols = 'ID AS id, PID AS pid, NEXT_QID AS next_qid, EL_TYPE AS el_type, NUM AS num,
                    NAME AS name, DT_CREATE AS dt_create, DISABLED AS disabled,
                    IS_REQ AS is_req, FINISH AS finish, IDS_DICTONARYS AS ids_dictonarys,
                    SCORE AS score, `BREAK` AS break_flag, TIMEOUT AS timeout,
                    DIVERSITY AS diversity, INSTANCES_MIN AS instances_min,
                    COLLECTION_KILLER AS collection_killer, ID_POSITION AS id_position';
        if (Database::columnExists('base_records_scripts', 'RATING_ENABLED')) {
            $cols .= ', RATING_ENABLED AS rating_enabled, IS_HIDDEN AS is_hidden, LEGACY_TYPE_ID AS legacy_type_id';
        } else {
            $cols .= ', 0 AS rating_enabled, 0 AS is_hidden, 0 AS legacy_type_id';
        }
        if (Database::columnExists('base_records_scripts', 'SCORE_RATE')) {
            $cols .= ', SCORE_RATE AS score_rate';
        } else {
            $cols .= ', 0 AS score_rate';
        }
        if (Database::columnExists('base_records_scripts', 'KPI')) {
            $cols .= ', KPI AS kpi';
        } else {
            $cols .= ', 0 AS kpi';
        }
        if (Database::columnExists('base_records_scripts', 'COND_TYPE')) {
            $cols .= ', COND_TYPE AS cond_type, COND_VALUE AS cond_value';
        } else {
            $cols .= ", '' AS cond_type, 0 AS cond_value";
        }
        if (Database::columnExists('base_records_scripts', 'START_GATE')) {
            $cols .= ', START_GATE AS start_gate';
        } else {
            $cols .= ', 0 AS start_gate';
        }
        if (Database::columnExists('base_records_scripts', 'EXTEND_TIMEOUT')) {
            $cols .= ', EXTEND_TIMEOUT AS extend_timeout';
        } else {
            $cols .= ', 1 AS extend_timeout';
        }
        if (Database::columnExists('base_records_scripts', 'BREAK_GAP')) {
            $cols .= ', BREAK_GAP AS break_gap';
        } else {
            $cols .= ', 120 AS break_gap';
        }
        if (Database::columnExists('base_records_scripts', 'DAY_BONUS')) {
            $cols .= ', DAY_BONUS AS day_bonus, DAY_BONUS_SUM AS day_bonus_sum, PROC_RATIO_MIN AS proc_ratio_min';
        } else {
            $cols .= ', 0 AS day_bonus, 0 AS day_bonus_sum, 0 AS proc_ratio_min';
        }
        if (Database::columnExists('base_records_scripts', 'DAY_GATES')) {
            $cols .= ', DAY_GATES AS day_gates';
        } else {
            $cols .= ", '' AS day_gates";
        }
        if (Database::columnExists('base_records_scripts', 'GATE_RED_MAX')) {
            $cols .= ', GATE_RED_MAX AS gate_red_max, GATE_YELLOW_MAX AS gate_yellow_max';
        } else {
            $cols .= ', 0 AS gate_red_max, 0 AS gate_yellow_max';
        }
        if (Database::columnExists('base_records_scripts', 'HIDE_FROM_REPORTS')) {
            $cols .= ', HIDE_FROM_REPORTS AS hide_from_reports';
        } else {
            $cols .= ', 0 AS hide_from_reports';
        }
        if (Database::columnExists('base_records_scripts', 'CLOSER')) {
            $cols .= ', CLOSER AS closer';
        } else {
            $cols .= ', 0 AS closer';
        }
        return $cols;
    }

    /**
     * @param array<string,mixed> $root
     * @return array<string,mixed>
     */
    private static function metaFromRoot(array $root): array
    {
        return [
            'name' => (string)$root['name'],
            'disabled' => (int)$root['disabled'],
            'num' => (int)$root['num'],
            'id_position' => (string)$root['id_position'],
            'rating_enabled' => (int)($root['rating_enabled'] ?? 0),
            'is_hidden' => (int)($root['is_hidden'] ?? 0),
            'legacy_type_id' => (int)($root['legacy_type_id'] ?? 0),
            'day_bonus' => (int)($root['day_bonus'] ?? 0),
            'day_bonus_sum' => (int)($root['day_bonus_sum'] ?? 0),
            'day_gates' => is_array($root['day_gates'] ?? null)
                ? $root['day_gates']
                : self::resolveDayGatesFromRow($root),
            'proc_ratio_min' => (int)($root['proc_ratio_min'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function findRow(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_scripts')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . self::selectColumnsSql() . ' FROM base_records_scripts WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRoot(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'pid' => (int)$row['pid'],
            'next_qid' => (int)($row['next_qid'] ?? 0),
            'el_type' => (int)($row['el_type'] ?? 0),
            'num' => (int)($row['num'] ?? 0),
            'name' => (string)$row['name'],
            'dt_create' => (int)($row['dt_create'] ?? 0),
            'disabled' => (int)($row['disabled'] ?? 0),
            'id_position' => (string)($row['id_position'] ?? ''),
            'position_ids' => self::parseIds((string)($row['id_position'] ?? '')),
            'rating_enabled' => (int)($row['rating_enabled'] ?? 0),
            'is_hidden' => (int)($row['is_hidden'] ?? 0),
            'legacy_type_id' => (int)($row['legacy_type_id'] ?? 0),
            'day_bonus' => (int)($row['day_bonus'] ?? 0),
            'day_bonus_sum' => (int)($row['day_bonus_sum'] ?? 0),
            'day_gates' => self::resolveDayGatesFromRow($row),
            'proc_ratio_min' => (int)($row['proc_ratio_min'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizePart(array $row): array
    {
        $isReq = (int)($row['is_req'] ?? 0);
        $finish = (int)($row['finish'] ?? 0);
        $closer = (int)($row['closer'] ?? 0);
        $break = (int)($row['break_flag'] ?? $row['break'] ?? 0);
        $killer = (int)($row['collection_killer'] ?? 0);
        $dictIds = self::parseIds((string)($row['ids_dictonarys'] ?? ''));
        $roles = self::rolesFromFlags($isReq, $finish, $break, $killer, $closer);
        return [
            'id' => (int)$row['id'],
            'pid' => (int)$row['pid'],
            'next_qid' => (int)($row['next_qid'] ?? 0),
            'el_type' => (int)($row['el_type'] ?? 1),
            'num' => (int)($row['num'] ?? 0),
            'name' => (string)$row['name'],
            'dt_create' => (int)($row['dt_create'] ?? 0),
            'disabled' => (int)($row['disabled'] ?? 0),
            'is_req' => $isReq,
            'finish' => $finish,
            'closer' => $closer,
            'break_flag' => $break,
            'ids_dictonarys' => (string)($row['ids_dictonarys'] ?? ''),
            'dict_ids' => $dictIds,
            'score' => (int)($row['score'] ?? 0),
            'score_rate' => (int)($row['score_rate'] ?? 0),
            'kpi' => (int)($row['kpi'] ?? 0),
            'cond_type' => (string)($row['cond_type'] ?? ''),
            'cond_value' => (int)($row['cond_value'] ?? 0),
            'start_gate' => max(0, min(2, (int)($row['start_gate'] ?? 0))),
            'extend_timeout' => array_key_exists('extend_timeout', $row)
                ? ((int)$row['extend_timeout'] > 0 ? 1 : 0)
                : 1,
            'timeout' => (int)($row['timeout'] ?? 0),
            'break_gap' => max(0, (int)($row['break_gap'] ?? 120)),
            'diversity' => (int)($row['diversity'] ?? 0),
            'instances_min' => (int)($row['instances_min'] ?? 0),
            'gate_red_max' => max(0.0, (float)($row['gate_red_max'] ?? 0)),
            'gate_yellow_max' => max(0.0, (float)($row['gate_yellow_max'] ?? 0)),
            'hide_from_reports' => !empty($row['hide_from_reports']) ? 1 : 0,
            'collection_killer' => $killer,
            'id_position' => (string)($row['id_position'] ?? ''),
            'roles' => $roles,
            'role' => $roles[0] ?? 'ordinary',
        ];
    }

    private static function applyCloserFlag(PDO $pdo, int $partId, int $closer): void
    {
        if ($partId < 1 || !Database::columnExists('base_records_scripts', 'CLOSER')) {
            return;
        }
        $st = $pdo->prepare('UPDATE base_records_scripts SET CLOSER=? WHERE ID=? AND PID > 0');
        $st->execute([$closer > 0 ? 1 : 0, $partId]);
    }

    /**
     * @param array<string,mixed> $part
     * @param array{is_req:int,finish:int,closer?:int,break:int,killer:int} $flags
     */
    private static function updatePartRow(
        PDO $pdo,
        int $partId,
        int $rootId,
        int $num,
        array $part,
        array $flags,
        string $dicts
    ): void {
        if (Database::columnExists('base_records_scripts', 'SCORE_RATE')) {
            $hasGate = Database::columnExists('base_records_scripts', 'START_GATE');
            $hasExtend = Database::columnExists('base_records_scripts', 'EXTEND_TIMEOUT');
            $hasBreakGap = Database::columnExists('base_records_scripts', 'BREAK_GAP');
            $hasQuality = Database::columnExists('base_records_scripts', 'GATE_RED_MAX');
            $hasHide = Database::columnExists('base_records_scripts', 'HIDE_FROM_REPORTS');
            $upd = $pdo->prepare(
                'UPDATE base_records_scripts SET
                 NEXT_QID=0, EL_TYPE=1, NUM=?, NAME=?, DISABLED=?,
                 IS_REQ=?, FINISH=?, IDS_DICTONARYS=?, SCORE=?, `BREAK`=?, TIMEOUT=?,
                 DIVERSITY=?, INSTANCES_MIN=?, COLLECTION_KILLER=?, ID_POSITION=?,
                 SCORE_RATE=?, KPI=?, COND_TYPE=?, COND_VALUE=?'
                . ($hasGate ? ', START_GATE=?' : '')
                . ($hasExtend ? ', EXTEND_TIMEOUT=?' : '')
                . ($hasBreakGap ? ', BREAK_GAP=?' : '')
                . ($hasQuality ? ', GATE_RED_MAX=?, GATE_YELLOW_MAX=?' : '')
                . ($hasHide ? ', HIDE_FROM_REPORTS=?' : '')
                . ' WHERE ID=? AND PID=?'
            );
            $params = [
                $num,
                $part['name'],
                (int)($part['disabled'] ?? 0),
                $flags['is_req'],
                $flags['finish'],
                $dicts,
                (int)($part['score'] ?? 0),
                $flags['break'],
                (int)($part['timeout'] ?? 0),
                (int)($part['diversity'] ?? 0),
                (int)($part['instances_min'] ?? 0),
                $flags['killer'],
                (string)($part['id_position'] ?? ''),
                (int)($part['score_rate'] ?? 0),
                (int)($part['kpi'] ?? 0),
                (string)($part['cond_type'] ?? ''),
                (int)($part['cond_value'] ?? 0),
            ];
            if ($hasGate) {
                $params[] = max(0, min(2, (int)($part['start_gate'] ?? 0)));
            }
            if ($hasExtend) {
                $params[] = array_key_exists('extend_timeout', $part)
                    ? (!empty($part['extend_timeout']) ? 1 : 0)
                    : 1;
            }
            if ($hasBreakGap) {
                $params[] = max(0, (int)($part['break_gap'] ?? 120));
            }
            if ($hasQuality) {
                $params[] = max(0.0, (float)($part['gate_red_max'] ?? 0));
                $params[] = max(0.0, (float)($part['gate_yellow_max'] ?? 0));
            }
            if ($hasHide) {
                $params[] = !empty($part['hide_from_reports']) ? 1 : 0;
            }
            $params[] = $partId;
            $params[] = $rootId;
            $upd->execute($params);
            self::applyCloserFlag($pdo, $partId, (int)($flags['closer'] ?? 0));
            return;
        }
        $upd = $pdo->prepare(
            'UPDATE base_records_scripts SET
             NEXT_QID=0, EL_TYPE=1, NUM=?, NAME=?, DISABLED=?,
             IS_REQ=?, FINISH=?, IDS_DICTONARYS=?, SCORE=?, `BREAK`=?, TIMEOUT=?,
             DIVERSITY=?, INSTANCES_MIN=?, COLLECTION_KILLER=?, ID_POSITION=?
             WHERE ID=? AND PID=?'
        );
        $upd->execute([
            $num,
            $part['name'],
            (int)($part['disabled'] ?? 0),
            $flags['is_req'],
            $flags['finish'],
            $dicts,
            (int)($part['score'] ?? 0),
            $flags['break'],
            (int)($part['timeout'] ?? 0),
            (int)($part['diversity'] ?? 0),
            (int)($part['instances_min'] ?? 0),
            $flags['killer'],
            (string)($part['id_position'] ?? ''),
            $partId,
            $rootId,
        ]);
        self::applyCloserFlag($pdo, $partId, (int)($flags['closer'] ?? 0));
    }

    /**
     * @param array<string,mixed> $part
     * @param array{is_req:int,finish:int,closer?:int,break:int,killer:int} $flags
     */
    private static function insertPartRow(
        PDO $pdo,
        int $rootId,
        int $num,
        array $part,
        array $flags,
        string $dicts
    ): int {
        if (Database::columnExists('base_records_scripts', 'SCORE_RATE')) {
            $hasGate = Database::columnExists('base_records_scripts', 'START_GATE');
            $hasExtend = Database::columnExists('base_records_scripts', 'EXTEND_TIMEOUT');
            $hasBreakGap = Database::columnExists('base_records_scripts', 'BREAK_GAP');
            $hasQuality = Database::columnExists('base_records_scripts', 'GATE_RED_MAX');
            $hasHide = Database::columnExists('base_records_scripts', 'HIDE_FROM_REPORTS');
            $ins = $pdo->prepare(
                'INSERT INTO base_records_scripts
                 (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
                  IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
                  COLLECTION_KILLER, ID_POSITION, SCORE_RATE, KPI, COND_TYPE, COND_VALUE'
                . ($hasGate ? ', START_GATE' : '')
                . ($hasExtend ? ', EXTEND_TIMEOUT' : '')
                . ($hasBreakGap ? ', BREAK_GAP' : '')
                . ($hasQuality ? ', GATE_RED_MAX, GATE_YELLOW_MAX' : '')
                . ($hasHide ? ', HIDE_FROM_REPORTS' : '')
                . ')
                 VALUES (?, 0, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?'
                . ($hasGate ? ', ?' : '')
                . ($hasExtend ? ', ?' : '')
                . ($hasBreakGap ? ', ?' : '')
                . ($hasQuality ? ', ?, ?' : '')
                . ($hasHide ? ', ?' : '')
                . ')'
            );
            $params = [
                $rootId,
                $num,
                $part['name'],
                time(),
                (int)($part['disabled'] ?? 0),
                $flags['is_req'],
                $flags['finish'],
                $dicts,
                (int)($part['score'] ?? 0),
                $flags['break'],
                (int)($part['timeout'] ?? 0),
                (int)($part['diversity'] ?? 0),
                (int)($part['instances_min'] ?? 0),
                $flags['killer'],
                (string)($part['id_position'] ?? ''),
                (int)($part['score_rate'] ?? 0),
                (int)($part['kpi'] ?? 0),
                (string)($part['cond_type'] ?? ''),
                (int)($part['cond_value'] ?? 0),
            ];
            if ($hasGate) {
                $params[] = max(0, min(2, (int)($part['start_gate'] ?? 0)));
            }
            if ($hasExtend) {
                $params[] = array_key_exists('extend_timeout', $part)
                    ? (!empty($part['extend_timeout']) ? 1 : 0)
                    : 1;
            }
            if ($hasBreakGap) {
                $params[] = max(0, (int)($part['break_gap'] ?? 120));
            }
            if ($hasQuality) {
                $params[] = max(0.0, (float)($part['gate_red_max'] ?? 0));
                $params[] = max(0.0, (float)($part['gate_yellow_max'] ?? 0));
            }
            if ($hasHide) {
                $params[] = !empty($part['hide_from_reports']) ? 1 : 0;
            }
            $ins->execute($params);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO base_records_scripts
                 (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
                  IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
                  COLLECTION_KILLER, ID_POSITION)
                 VALUES (?, 0, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $rootId,
                $num,
                $part['name'],
                time(),
                (int)($part['disabled'] ?? 0),
                $flags['is_req'],
                $flags['finish'],
                $dicts,
                (int)($part['score'] ?? 0),
                $flags['break'],
                (int)($part['timeout'] ?? 0),
                (int)($part['diversity'] ?? 0),
                (int)($part['instances_min'] ?? 0),
                $flags['killer'],
                (string)($part['id_position'] ?? ''),
            ]);
        }
        $newId = (int)$pdo->lastInsertId();
        self::applyCloserFlag($pdo, $newId, (int)($flags['closer'] ?? 0));

        return $newId;
    }

    /**
     * @param list<array<string,mixed>> $parts
     */
    private static function insertParts(PDO $pdo, int $rootId, array $parts): void
    {
        $ordered = [];
        foreach (array_values($parts) as $i => $part) {
            $num = $i + 1;
            $flags = self::flagsFromPart($part);
            $dicts = self::joinIds(is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : []);
            $ordered[] = self::insertPartRow($pdo, $rootId, $num, $part, $flags, $dicts);
        }
        self::linkNextQids($pdo, $ordered);
    }

    /** @param list<int> $orderedIds */
    private static function linkNextQids(PDO $pdo, array $orderedIds): void
    {
        $n = count($orderedIds);
        $st = $pdo->prepare('UPDATE base_records_scripts SET NEXT_QID = ?, NUM = ? WHERE ID = ?');
        for ($i = 0; $i < $n; $i++) {
            $next = ($i + 1 < $n) ? $orderedIds[$i + 1] : 0;
            $st->execute([$next, $i + 1, $orderedIds[$i]]);
        }
    }
}
