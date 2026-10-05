<?php
declare(strict_types=1);

/**
 * Recognition scripts tree: root (PID=0, EL_TYPE=0) + parts (PID=root, EL_TYPE=1).
 */
final class RecordsScriptsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 's.ID', 'name' => 's.NAME', 'disabled' => 's.DISABLED', 'num' => 's.NUM'];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
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
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_scripts s WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 's.ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT s.ID AS id, s.DT_CREATE AS dt_create, s.NAME AS name, s.DISABLED AS disabled,
                       s.NUM AS num, s.ID_POSITION AS id_position,
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
     * Compact parts for list UI: name + roles, ordered by NUM.
     *
     * @param list<int> $rootIds
     * @return array<int,list<array{id:int,name:string,roles:list<string>,score:int}>>
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
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, PID AS pid, NAME AS name, NUM AS num,
                    IS_REQ AS is_req, FINISH AS finish, `BREAK` AS break_flag,
                    COLLECTION_KILLER AS collection_killer, SCORE AS score
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
                    (int)$row['collection_killer']
                ),
                'score' => (int)$row['score'],
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
            'SELECT ID AS id, PID AS pid, NEXT_QID AS next_qid, EL_TYPE AS el_type, NUM AS num,
                    NAME AS name, DT_CREATE AS dt_create, DISABLED AS disabled,
                    IS_REQ AS is_req, FINISH AS finish, IDS_DICTONARYS AS ids_dictonarys,
                    SCORE AS score, `BREAK` AS break_flag, TIMEOUT AS timeout,
                    DIVERSITY AS diversity, INSTANCES_MIN AS instances_min,
                    COLLECTION_KILLER AS collection_killer, ID_POSITION AS id_position
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
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $rootId = 0;
        try {
            $st = $pdo->prepare(
                'INSERT INTO base_records_scripts
                 (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
                  IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
                  COLLECTION_KILLER, ID_POSITION)
                 VALUES (0, 0, 0, ?, ?, ?, ?, 0, 0, NULL, 0, 0, 0, 0, 0, 0, ?)'
            );
            $st->execute([
                (int)($meta['num'] ?? 0),
                $meta['name'],
                time(),
                (int)($meta['disabled'] ?? 0),
                $meta['id_position'] ?? '',
            ]);
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
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
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
                        $id,
                    ]);
                    $keep[] = $partId;
                    $ordered[] = $partId;
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO base_records_scripts
                         (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
                          IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
                          COLLECTION_KILLER, ID_POSITION)
                         VALUES (?, 0, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $ins->execute([
                        $id,
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
                    $newId = (int)$pdo->lastInsertId();
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
        $meta = [
            'name' => $name,
            'disabled' => (int)$root['disabled'],
            'num' => (int)$root['num'],
            'id_position' => (string)$root['id_position'],
        ];
        $parts = [];
        foreach ($tree['parts'] as $p) {
            $parts[] = [
                'name' => (string)$p['name'],
                'disabled' => (int)$p['disabled'],
                'dict_ids' => $p['dict_ids'],
                'score' => (int)$p['score'],
                'timeout' => (int)$p['timeout'],
                'diversity' => (int)$p['diversity'],
                'instances_min' => (int)$p['instances_min'],
                'role' => (string)($p['roles'][0] ?? $p['role'] ?? 'ordinary'),
                'roles' => is_array($p['roles'] ?? null) ? $p['roles'] : self::rolesFromFlags(
                    (int)($p['is_req'] ?? 0),
                    (int)($p['finish'] ?? 0),
                    (int)($p['break_flag'] ?? 0),
                    (int)($p['collection_killer'] ?? 0)
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
        self::update($scriptId, [
            'name' => (string)$tree['root']['name'],
            'disabled' => (int)$tree['root']['disabled'],
            'num' => (int)$tree['root']['num'],
            'id_position' => (string)$tree['root']['id_position'],
        ], $parts);
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
        self::update($scriptId, [
            'name' => (string)$tree['root']['name'],
            'disabled' => (int)$tree['root']['disabled'],
            'num' => (int)$tree['root']['num'],
            'id_position' => (string)$tree['root']['id_position'],
        ], $ordered);
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
        self::update($scriptId, [
            'name' => (string)$tree['root']['name'],
            'disabled' => (int)$tree['root']['disabled'],
            'num' => (int)$tree['root']['num'],
            'id_position' => (string)$tree['root']['id_position'],
        ], $parts);
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
        ];
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
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => mb_substr($name, 0, 40),
                'disabled' => !empty($row['disabled']) ? 1 : 0,
                'dict_ids' => array_values(array_filter(array_map('intval', $dictIds), static fn ($i) => $i > 0)),
                'score' => (int)($row['score'] ?? 0),
                'timeout' => max(0, (int)($row['timeout'] ?? 0)),
                'diversity' => max(0, (int)($row['diversity'] ?? 0)),
                'instances_min' => max(0, (int)($row['instances_min'] ?? 0)),
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
     * @return array{is_req:int,finish:int,break:int,killer:int}
     */
    public static function flagsFromPart(array $part): array
    {
        $roles = $part['roles'] ?? null;
        if (!is_array($roles)) {
            $single = self::normalizeRole((string)($part['role'] ?? ''));
            if ($single !== '' && $single !== 'ordinary') {
                $roles = [$single];
            } elseif (isset($part['is_req']) || isset($part['finish']) || isset($part['break_flag']) || isset($part['break']) || isset($part['collection_killer'])) {
                $roles = self::rolesFromFlags(
                    (int)($part['is_req'] ?? 0),
                    (int)($part['finish'] ?? 0),
                    (int)($part['break_flag'] ?? $part['break'] ?? 0),
                    (int)($part['collection_killer'] ?? $part['killer'] ?? 0)
                );
            } else {
                $roles = ['ordinary'];
            }
        }
        $roles = self::normalizeRoles($roles);
        return [
            'is_req' => in_array('trigger', $roles, true) ? 1 : 0,
            'finish' => in_array('conversion', $roles, true) ? 1 : 0,
            'break' => in_array('breaker', $roles, true) ? 1 : 0,
            'killer' => in_array('killer', $roles, true) ? 1 : 0,
        ];
    }

    /**
     * @return list<string>
     */
    public static function rolesFromFlags(int $isReq, int $finish, int $break, int $killer = 0): array
    {
        $roles = [];
        if ($isReq > 0) {
            $roles[] = 'trigger';
        }
        if ($finish > 0) {
            $roles[] = 'conversion';
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
        $roles = self::rolesFromFlags($isReq, $finish, $break, 0);
        return $roles[0] ?? 'ordinary';
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        $allowed = ['ordinary', 'trigger', 'conversion', 'breaker', 'killer'];
        return in_array($role, $allowed, true) ? $role : 'ordinary';
    }

    /**
     * @param list<mixed> $roles
     * @return list<string>
     */
    public static function normalizeRoles(array $roles): array
    {
        $allowed = ['trigger', 'conversion', 'breaker', 'killer'];
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

    /** @return array<int,string> status id => name */
    public static function positionOptions(): array
    {
        $opts = StatusesRepository::optionsForAudio();
        unset($opts[0]);
        return $opts;
    }

    /** @return array<string,mixed>|null */
    private static function findRow(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_scripts')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PID AS pid, NEXT_QID AS next_qid, EL_TYPE AS el_type, NUM AS num,
                    NAME AS name, DT_CREATE AS dt_create, DISABLED AS disabled,
                    IS_REQ AS is_req, FINISH AS finish, IDS_DICTONARYS AS ids_dictonarys,
                    SCORE AS score, `BREAK` AS break_flag, TIMEOUT AS timeout,
                    DIVERSITY AS diversity, INSTANCES_MIN AS instances_min,
                    COLLECTION_KILLER AS collection_killer, ID_POSITION AS id_position
             FROM base_records_scripts WHERE ID = ? LIMIT 1'
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
        $break = (int)($row['break_flag'] ?? $row['break'] ?? 0);
        $killer = (int)($row['collection_killer'] ?? 0);
        $dictIds = self::parseIds((string)($row['ids_dictonarys'] ?? ''));
        $roles = self::rolesFromFlags($isReq, $finish, $break, $killer);
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
            'break_flag' => $break,
            'ids_dictonarys' => (string)($row['ids_dictonarys'] ?? ''),
            'dict_ids' => $dictIds,
            'score' => (int)($row['score'] ?? 0),
            'timeout' => (int)($row['timeout'] ?? 0),
            'diversity' => (int)($row['diversity'] ?? 0),
            'instances_min' => (int)($row['instances_min'] ?? 0),
            'collection_killer' => $killer,
            'id_position' => (string)($row['id_position'] ?? ''),
            'roles' => $roles,
            'role' => $roles[0] ?? 'ordinary',
        ];
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
            $ordered[] = (int)$pdo->lastInsertId();
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
