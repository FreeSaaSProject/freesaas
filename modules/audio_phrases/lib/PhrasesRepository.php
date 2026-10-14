<?php
declare(strict_types=1);

final class PhrasesRepository
{
    public static function tablesReady(): bool
    {
        return Database::tableExists('base_records_phrases')
            && Database::tableExists('base_records_phrase_days');
    }

    /**
     * @param array{
     *   status?:string,dict?:int,admin?:int,from?:string,to?:string,
     *   min_days?:int,min_admins?:int,q?:string,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tablesReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $status = trim((string)($opts['status'] ?? ''));
        if ($status !== '' && in_array($status, ['new', 'junk', 'skip', 'key'], true)) {
            $where[] = 'p.STATUS = ?';
            $params[] = $status;
        }
        $dict = (int)($opts['dict'] ?? 0);
        if ($dict > 0) {
            $where[] = 'p.ID_DICTONARY = ?';
            $params[] = $dict;
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.LEMMAS LIKE ? OR p.EXAMPLE LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $minDays = (int)($opts['min_days'] ?? 0);
        if ($minDays > 0) {
            $where[] = 'p.DAYS_SEEN >= ?';
            $params[] = $minDays;
        }
        $minAdmins = (int)($opts['min_admins'] ?? 0);
        if ($minAdmins > 0) {
            $where[] = 'p.ADMINS_SEEN >= ?';
            $params[] = $minAdmins;
        }
        $admin = (int)($opts['admin'] ?? 0);
        $from = trim((string)($opts['from'] ?? ''));
        $to = trim((string)($opts['to'] ?? ''));
        $join = '';
        if ($admin > 0 || ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from))
            || ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))
        ) {
            $join = ' INNER JOIN base_records_phrase_days d ON d.ID_PHRASE = p.ID';
            if ($admin > 0) {
                $where[] = 'd.ID_ADMIN = ?';
                $params[] = $admin;
            }
            if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $where[] = 'd.DAY >= ?';
                $params[] = $from;
            }
            if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $where[] = 'd.DAY <= ?';
                $params[] = $to;
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(DISTINCT p.ID) FROM base_records_phrases p{$join} WHERE {$sqlWhere}");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'p.TOTAL_COUNT DESC, p.ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $keyJoin = self::keyDictJoinSql();
        // When filtering via phrase_days, dedupe with a subquery (avoids DISTINCT+ORDER BY / JOIN quirks).
        if ($join !== '') {
            $idSql = "SELECT DISTINCT p.ID FROM base_records_phrases p{$join} WHERE {$sqlWhere}";
            $sql = "SELECT p.ID AS id, p.HASH AS hash, p.LEMMAS AS lemmas, p.WORDS AS words,
                           p.EXAMPLE AS example, p.STATUS AS status, p.ID_DICTONARY AS id_dictonary,
                           p.ID_KEY AS id_key, p.DT_FIRST_SEEN AS dt_first_seen, p.DT_LAST_SEEN AS dt_last_seen,
                           p.DAYS_SEEN AS days_seen, p.ADMINS_SEEN AS admins_seen, p.TOTAL_COUNT AS total_count,
                           " . self::keyDictSelectSql() . "
                    FROM base_records_phrases p
                    INNER JOIN ({$idSql}) AS _ph ON _ph.ID = p.ID
                    {$keyJoin}
                    ORDER BY {$order}";
        } else {
            $sql = "SELECT p.ID AS id, p.HASH AS hash, p.LEMMAS AS lemmas, p.WORDS AS words,
                           p.EXAMPLE AS example, p.STATUS AS status, p.ID_DICTONARY AS id_dictonary,
                           p.ID_KEY AS id_key, p.DT_FIRST_SEEN AS dt_first_seen, p.DT_LAST_SEEN AS dt_last_seen,
                           p.DAYS_SEEN AS days_seen, p.ADMINS_SEEN AS admins_seen, p.TOTAL_COUNT AS total_count,
                           " . self::keyDictSelectSql() . "
                    FROM base_records_phrases p{$keyJoin}
                    WHERE {$sqlWhere}
                    ORDER BY {$order}";
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeListRow($row);
        }
        $items = self::coalesceItems($items, true);
        foreach ($items as &$row) {
            $row = self::normalizeListRow($row);
        }
        unset($row);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeListRow(array $row): array
    {
        // Tolerate driver case / coalesce merges that omit keys.
        if (!isset($row['status']) && isset($row['STATUS'])) {
            $row['status'] = $row['STATUS'];
        }
        if (!isset($row['total_count']) && isset($row['TOTAL_COUNT'])) {
            $row['total_count'] = $row['TOTAL_COUNT'];
        }
        if (!isset($row['days_seen']) && isset($row['DAYS_SEEN'])) {
            $row['days_seen'] = $row['DAYS_SEEN'];
        }
        if (!isset($row['admins_seen']) && isset($row['ADMINS_SEEN'])) {
            $row['admins_seen'] = $row['ADMINS_SEEN'];
        }
        $row['status'] = (string)($row['status'] ?? 'new');
        $row['total_count'] = (int)($row['total_count'] ?? 0);
        $row['days_seen'] = (int)($row['days_seen'] ?? 0);
        $row['admins_seen'] = (int)($row['admins_seen'] ?? 0);
        $row['example'] = (string)($row['example'] ?? '');
        $row['lemmas'] = (string)($row['lemmas'] ?? '');
        $row['id'] = (int)($row['id'] ?? $row['ID'] ?? 0);
        $row['id_key'] = (int)($row['id_key'] ?? $row['ID_KEY'] ?? 0);
        return $row;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tablesReady()) {
            return null;
        }
        $keyJoin = self::keyDictJoinSql();
        $st = Database::pdo()->prepare(
            'SELECT p.ID AS id, p.HASH AS hash, p.LEMMAS AS lemmas, p.WORDS AS words, p.EXAMPLE AS example,
                    p.STATUS AS status, p.ID_DICTONARY AS id_dictonary, p.ID_KEY AS id_key,
                    p.DT_FIRST_SEEN AS dt_first_seen, p.DT_LAST_SEEN AS dt_last_seen,
                    p.DAYS_SEEN AS days_seen, p.ADMINS_SEEN AS admins_seen, p.TOTAL_COUNT AS total_count,
                    ' . self::keyDictSelectSql() . '
             FROM base_records_phrases p' . $keyJoin . '
             WHERE p.ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    /**
     * Staff who have phrase-day rows for $day (harvested reports), id => label.
     *
     * @return array<int,string>
     */
    public static function adminsForDay(string $day): array
    {
        if (!self::tablesReady() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT DISTINCT ID_ADMIN FROM base_records_phrase_days WHERE DAY = ? AND ID_ADMIN > 0 ORDER BY ID_ADMIN'
        );
        $st->execute([$day]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
            $id = (int)$raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        if (class_exists('UsersRepository', false)) {
            UsersRepository::preload($ids);
            foreach ($ids as $id) {
                $u = UsersRepository::find($id);
                if ($u === null) {
                    $out[$id] = '#' . $id;
                    continue;
                }
                $label = trim((string)($u['name'] ?? ''));
                if ($label === '') {
                    $label = (string)($u['login'] ?? ('#' . $id));
                }
                $out[$id] = $label;
            }
            // Stable alpha order by label.
            asort($out, SORT_NATURAL | SORT_FLAG_CASE);
            return $out;
        }
        foreach ($ids as $id) {
            $out[$id] = '#' . $id;
        }
        return $out;
    }

    /**
     * Phrases still needing classification (new, no key).
     *
     * @param array{admin?:int,day?:string,limit?:int} $opts
     * @return array{total:int,items:list<array{id:int,lemmas:string,example:string}>}
     */
    public static function pendingQueue(array $opts = []): array
    {
        if (!self::tablesReady()) {
            return ['total' => 0, 'items' => []];
        }
        $pdo = Database::pdo();
        $where = ['p.STATUS = \'new\'', '(p.ID_KEY IS NULL OR p.ID_KEY < 1)'];
        $params = [];
        $join = '';
        $day = trim((string)($opts['day'] ?? ''));
        $admin = (int)($opts['admin'] ?? 0);
        if (($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) || $admin > 0) {
            $join = ' INNER JOIN base_records_phrase_days d ON d.ID_PHRASE = p.ID';
            if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $where[] = 'd.DAY = ?';
                $params[] = $day;
            }
            if ($admin > 0) {
                $where[] = 'd.ID_ADMIN = ?';
                $params[] = $admin;
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(DISTINCT p.ID) FROM base_records_phrases p{$join} WHERE {$sqlWhere}");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $limit = max(1, min(80, (int)($opts['limit'] ?? 40)));
        // GROUP BY (not DISTINCT+ORDER BY TOTAL_COUNT) — MySQL 3065 / ONLY_FULL_GROUP_BY.
        $sql = "SELECT p.ID AS id, p.LEMMAS AS lemmas, p.EXAMPLE AS example, p.TOTAL_COUNT AS total_count
                FROM base_records_phrases p{$join}
                WHERE {$sqlWhere}
                GROUP BY p.ID, p.LEMMAS, p.EXAMPLE, p.TOTAL_COUNT
                ORDER BY p.TOTAL_COUNT DESC, p.ID DESC
                LIMIT {$limit}";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'lemmas' => (string)$row['lemmas'],
                'example' => (string)$row['example'],
            ];
        }
        return ['total' => $total, 'items' => $items];
    }

    public static function forDay(string $day, int $adminId = 0): array
    {
        if (!self::tablesReady() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return [];
        }
        $keyJoin = self::keyDictJoinSql();
        $sql = 'SELECT p.ID AS id, p.LEMMAS AS lemmas, p.EXAMPLE AS example, p.STATUS AS status,
                       p.WORDS AS words, p.ID_DICTONARY AS id_dictonary, p.ID_KEY AS id_key,
                       d.COUNT AS day_count, d.ID_ADMIN AS id_admin,
                       d.RECORDS_COUNT AS records_count, d.EXAMPLES AS examples_json,
                       ' . self::keyDictSelectSql() . '
                FROM base_records_phrase_days d
                INNER JOIN base_records_phrases p ON p.ID = d.ID_PHRASE
                ' . $keyJoin . '
                WHERE d.DAY = ? AND p.STATUS <> \'junk\'';
        $params = [$day];
        if ($adminId > 0) {
            $sql .= ' AND d.ID_ADMIN = ?';
            $params[] = $adminId;
        }
        $sql .= ' ORDER BY d.COUNT DESC, p.ID DESC LIMIT 500';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['samples'] = self::decodeSamples((string)($row['examples_json'] ?? ''));
            unset($row['examples_json']);
        }
        unset($row);
        return self::coalesceItems($rows, true);
    }

    /**
     * Attach missing keys by surface containment; fold longer examples into shorter roots in the result set.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function coalesceItems(array $items, bool $persistKeys = true): array
    {
        if ($items === []) {
            return [];
        }
        if (class_exists('RecordsKeysRepository', false)) {
            foreach ($items as &$row) {
                if ((int)($row['id_key'] ?? 0) > 0) {
                    continue;
                }
                $status = (string)($row['status'] ?? '');
                if ($status === 'junk' || $status === 'skip') {
                    continue;
                }
                $keyId = self::findContainedKeyId(
                    (string)($row['example'] ?? ''),
                    (string)($row['lemmas'] ?? '')
                );
                if ($keyId < 1) {
                    continue;
                }
                $row['id_key'] = $keyId;
                if ($status === 'new') {
                    $row['status'] = 'key';
                }
                if ($persistKeys && (int)($row['id'] ?? 0) > 0) {
                    Database::pdo()->prepare(
                        'UPDATE base_records_phrases
                         SET ID_KEY = IF(ID_KEY > 0, ID_KEY, ?),
                             STATUS = IF(STATUS = \'new\', \'key\', STATUS)
                         WHERE ID = ?'
                    )->execute([$keyId, (int)$row['id']]);
                }
                $meta = self::keyMeta($keyId);
                if ($meta !== null) {
                    $row['key_name'] = $meta['name'];
                    $row['id_key_dictonary'] = $meta['id_dictonary'];
                    $row['dict_name'] = $meta['dict_name'];
                }
            }
            unset($row);
        }

        // Fold longer surfaces into shorter roots within this page/result.
        $n = count($items);
        if ($n < 2) {
            return $items;
        }
        $norms = [];
        for ($i = 0; $i < $n; $i++) {
            $norms[$i] = class_exists('RecordsKeysRepository', false)
                ? RecordsKeysRepository::normalizeName((string)($items[$i]['example'] ?? ''))
                : mb_strtolower(trim((string)($items[$i]['example'] ?? '')), 'UTF-8');
        }
        $order = range(0, $n - 1);
        usort($order, static function (int $a, int $b) use ($norms): int {
            $la = mb_strlen($norms[$a], 'UTF-8');
            $lb = mb_strlen($norms[$b], 'UTF-8');
            return $la === $lb ? ($a <=> $b) : ($la <=> $lb);
        });
        $absorbed = array_fill(0, $n, false);
        foreach ($order as $rootIdx) {
            if ($absorbed[$rootIdx]) {
                continue;
            }
            $rootNorm = $norms[$rootIdx];
            $rootLen = mb_strlen($rootNorm, 'UTF-8');
            if ($rootLen < 8) {
                continue;
            }
            foreach ($order as $extIdx) {
                if ($extIdx === $rootIdx || $absorbed[$extIdx]) {
                    continue;
                }
                $extNorm = $norms[$extIdx];
                $extLen = mb_strlen($extNorm, 'UTF-8');
                if ($extLen <= $rootLen || ($extLen - $rootLen) > 80) {
                    continue;
                }
                if (mb_strpos($extNorm, $rootNorm, 0, 'UTF-8') === false) {
                    continue;
                }
                // Merge counts into root; drop extension from UI.
                if (isset($items[$rootIdx]['total_count'], $items[$extIdx]['total_count'])) {
                    $items[$rootIdx]['total_count'] = (int)$items[$rootIdx]['total_count']
                        + (int)$items[$extIdx]['total_count'];
                }
                if (isset($items[$rootIdx]['day_count'], $items[$extIdx]['day_count'])) {
                    $items[$rootIdx]['day_count'] = (int)$items[$rootIdx]['day_count']
                        + (int)$items[$extIdx]['day_count'];
                }
                if ((int)($items[$rootIdx]['id_key'] ?? 0) < 1 && (int)($items[$extIdx]['id_key'] ?? 0) > 0) {
                    $items[$rootIdx]['id_key'] = (int)$items[$extIdx]['id_key'];
                    $items[$rootIdx]['key_name'] = $items[$extIdx]['key_name'] ?? null;
                    $items[$rootIdx]['dict_name'] = $items[$extIdx]['dict_name'] ?? null;
                    $items[$rootIdx]['id_key_dictonary'] = $items[$extIdx]['id_key_dictonary'] ?? null;
                    if ((string)($items[$rootIdx]['status'] ?? '') === 'new') {
                        $items[$rootIdx]['status'] = 'key';
                    }
                }
                $rootSamples = is_array($items[$rootIdx]['samples'] ?? null) ? $items[$rootIdx]['samples'] : [];
                $extSamples = is_array($items[$extIdx]['samples'] ?? null) ? $items[$extIdx]['samples'] : [];
                if ($extSamples !== []) {
                    $seen = [];
                    foreach ($rootSamples as $s) {
                        $seen['r:' . (int)($s['record_id'] ?? 0)] = true;
                    }
                    foreach ($extSamples as $s) {
                        if (count($rootSamples) >= 5) {
                            break;
                        }
                        $k = 'r:' . (int)($s['record_id'] ?? 0);
                        if (isset($seen[$k])) {
                            continue;
                        }
                        $rootSamples[] = $s;
                        $seen[$k] = true;
                    }
                    $items[$rootIdx]['samples'] = $rootSamples;
                }
                $absorbed[$extIdx] = true;
            }
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (!$absorbed[$i]) {
                $out[] = $items[$i];
            }
        }
        return $out;
    }

    /** Best existing key contained in phrase surface (or exact). */
    private static function findContainedKeyId(string $example, string $lemmas): int
    {
        if (!class_exists('RecordsKeysRepository', false)) {
            return 0;
        }
        $bestId = 0;
        $bestLen = 0;
        foreach ([$example, $lemmas] as $probe) {
            $probe = trim($probe);
            if ($probe === '') {
                continue;
            }
            foreach (RecordsKeysRepository::findOverlaps($probe, 0, 20) as $hit) {
                $rel = (string)($hit['relation'] ?? '');
                // exact, or key is shorter and sits inside the phrase (contained).
                if ($rel !== 'exact' && $rel !== 'contained') {
                    continue;
                }
                $len = mb_strlen(RecordsKeysRepository::normalizeName((string)$hit['name']), 'UTF-8');
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestId = (int)$hit['id'];
                }
            }
        }
        return $bestId;
    }

    /** @return array{name:string,id_dictonary:int,dict_name:string}|null */
    private static function keyMeta(int $keyId): ?array
    {
        if ($keyId < 1 || !Database::tableExists('base_records_keys')) {
            return null;
        }
        $sql = 'SELECT k.NAME AS name, k.ID_DICTONARY AS id_dictonary, d.NAME AS dict_name
                FROM base_records_keys k
                LEFT JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY
                WHERE k.ID = ? LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$keyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'name' => (string)$row['name'],
            'id_dictonary' => (int)$row['id_dictonary'],
            'dict_name' => (string)($row['dict_name'] ?? ''),
        ];
    }

    private static function keyDictJoinSql(): string
    {
        $sql = '';
        $hasKeys = Database::tableExists('base_records_keys');
        if ($hasKeys) {
            $sql .= ' LEFT JOIN base_records_keys k ON k.ID = p.ID_KEY AND p.ID_KEY > 0';
        }
        if (Database::tableExists('base_records_dictonarys')) {
            $dictIdExpr = $hasKeys
                ? 'IF(p.ID_DICTONARY > 0, p.ID_DICTONARY, IFNULL(k.ID_DICTONARY, 0))'
                : 'p.ID_DICTONARY';
            $sql .= " LEFT JOIN base_records_dictonarys dict ON dict.ID = {$dictIdExpr} AND {$dictIdExpr} > 0";
        }
        return $sql;
    }

    private static function keyDictSelectSql(): string
    {
        $hasKeys = Database::tableExists('base_records_keys');
        $hasDicts = Database::tableExists('base_records_dictonarys');
        $keyName = $hasKeys ? 'k.NAME' : 'NULL';
        $keyDictId = $hasKeys ? 'k.ID_DICTONARY' : 'NULL';
        $dictName = $hasDicts ? 'dict.NAME' : 'NULL';
        return "{$keyName} AS key_name, {$keyDictId} AS id_key_dictonary, {$dictName} AS dict_name";
    }

    /**
     * @return list<array{record_id:int,before:string,hit:string,after:string}>
     */
    public static function decodeSamples(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $raw = [];
        if (isset($data['samples']) && is_array($data['samples'])) {
            $raw = $data['samples'];
        } elseif (isset($data[0]) && is_array($data[0])) {
            $raw = $data;
        } elseif (isset($data['example']) && (string)$data['example'] !== '') {
            // Legacy single-example payload.
            return [[
                'record_id' => 0,
                'before' => '',
                'hit' => (string)$data['example'],
                'after' => '',
            ]];
        }
        $out = [];
        foreach ($raw as $s) {
            if (!is_array($s)) {
                continue;
            }
            $hit = trim((string)($s['hit'] ?? $s['text'] ?? ''));
            if ($hit === '') {
                continue;
            }
            $out[] = [
                'record_id' => (int)($s['record_id'] ?? 0),
                'before' => (string)($s['before'] ?? ''),
                'hit' => $hit,
                'after' => (string)($s['after'] ?? ''),
            ];
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array{
     *   status:string,id_dictonary?:int,id_key?:int,create_key?:bool,
     *   admin_id?:int,force?:bool,key_name?:string
     * } $data
     * @return array<string,mixed>|null
     */
    public static function setStatus(int $id, array $data, int $userId = 0): ?array
    {
        $row = self::find($id);
        if ($row === null) {
            return null;
        }
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['new', 'junk', 'skip', 'key'], true)) {
            throw new InvalidArgumentException('bad status');
        }
        $dictId = (int)($data['id_dictonary'] ?? $row['id_dictonary'] ?? 0);
        $keyId = (int)($data['id_key'] ?? $row['id_key'] ?? 0);
        $force = !empty($data['force']);
        // create_key always mints a new key (also used to fix wrong-position auto-matches).
        if ($status === 'key' && !empty($data['create_key']) && $dictId > 0
            && class_exists('RecordsKeysRepository', false)
        ) {
            $example = trim((string)($row['example'] ?? ''));
            $lemmas = trim((string)($row['lemmas'] ?? ''));
            // Editable name from UI; fallback to surface example, then lemmas.
            $name = trim((string)($data['key_name'] ?? ''));
            if ($name === '') {
                $name = $example !== '' ? $example : $lemmas;
            }
            if ($name !== '') {
                $overlaps = self::collectKeyOverlaps($name, $lemmas);
                if ($overlaps !== [] && !$force) {
                    throw new PhraseKeyOverlapException($overlaps);
                }
                $looklike = $overlaps !== [] ? (int)$overlaps[0]['id'] : 0;
                $keyId = RecordsKeysRepository::create([
                    'name' => mb_substr($name, 0, 255),
                    'id_dictonary' => $dictId,
                    'id_type' => 0,
                    'id_looklike' => $looklike,
                    'disabled' => 0,
                ]);
            }
        }
        if ($status === 'key' && $keyId < 1) {
            throw new InvalidArgumentException('key required');
        }
        if ($status === 'key' && $dictId > 0 && !empty($data['admin_id'])
            && class_exists('RecordsDictsRepository', false)
            && !RecordsDictsRepository::isDictAllowedForAdmin($dictId, (int)$data['admin_id'])
        ) {
            throw new InvalidArgumentException(t('audio_phrases.err.dict_position'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_records_phrases
             SET STATUS = ?, ID_DICTONARY = ?, ID_KEY = ?, DT_STATUS = ?, ID_USER_STATUS = ?
             WHERE ID = ?'
        );
        $st->execute([$status, $dictId, $keyId, time(), $userId, $id]);
        // Junk always goes into trash dictionary so parsers skip it forever.
        if ($status === 'junk') {
            self::ensureTrashFromPhrase($row);
        }
        return self::find($id);
    }

    /**
     * @param list<int> $ids
     * @param array{status:string,id_dictonary?:int,id_key?:int,create_key?:bool} $data
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function setStatusMany(array $ids, array $data, int $userId = 0): array
    {
        $done = 0;
        $skipped = [];
        foreach ($ids as $raw) {
            $id = (int)$raw;
            if ($id < 1) {
                continue;
            }
            try {
                $row = self::setStatus($id, $data, $userId);
                if ($row === null) {
                    $skipped[] = ['id' => $id, 'reason' => 'not_found'];
                    continue;
                }
                $done++;
            } catch (Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Mark phrases whose lemmas are only integers as junk (ASR counting trash).
     * @return int rows updated
     */
    public static function junkNumericOnlyLemmas(): int
    {
        if (!self::tablesReady()) {
            return 0;
        }
        $st = Database::pdo()->query(
            "SELECT ID, LEMMAS FROM base_records_phrases
             WHERE STATUS = 'new' AND (ID_KEY IS NULL OR ID_KEY < 1)
             LIMIT 8000"
        );
        if (!$st) {
            return 0;
        }
        $ids = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $parts = preg_split('/\s+/u', trim((string)$row['LEMMAS'])) ?: [];
            $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
            if ($parts === []) {
                continue;
            }
            if (class_exists('TranscriptGarbage', false)
                && TranscriptGarbage::isNumericLemmaSlice($parts)
            ) {
                $ids[] = (int)$row['ID'];
            }
        }
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $upd = Database::pdo()->prepare(
            "UPDATE base_records_phrases SET STATUS = 'junk', DT_STATUS = ? WHERE ID IN ($in)"
        );
        $upd->execute(array_merge([time()], $ids));
        return (int)$upd->rowCount();
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if ($ids === [] || !self::tablesReady()) {
            return 0;
        }
        $pdo = Database::pdo();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM base_records_phrase_days WHERE ID_PHRASE IN ($in)")->execute($ids);
        $st = $pdo->prepare("DELETE FROM base_records_phrases WHERE ID IN ($in)");
        $st->execute($ids);
        return (int)$st->rowCount();
    }

    /**
     * Push phrase surface into base_records_trashes for future parsers.
     *
     * @param array<string,mixed> $row
     */
    public static function ensureTrashFromPhrase(array $row): int
    {
        $sample = trim((string)($row['example'] ?? ''));
        if ($sample === '') {
            $sample = trim((string)($row['lemmas'] ?? ''));
        }
        if ($sample === '') {
            return 0;
        }
        if (class_exists('TranscriptGarbage', false)) {
            return TranscriptGarbage::ensureSample($sample);
        }
        if (class_exists('RecordsTrashesRepository', false)) {
            $before = RecordsTrashesRepository::findByName($sample);
            RecordsTrashesRepository::ensure($sample, true);
            return $before ? 0 : 1;
        }
        return 0;
    }

    /**
     * Drop day rows before rebuild (keeps classified phrases; clears day counts).
     */
    public static function clearDay(string $day, int $adminId = 0): int
    {
        if (!self::tablesReady() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return 0;
        }
        $pdo = Database::pdo();
        if ($adminId > 0) {
            $st = $pdo->prepare('DELETE FROM base_records_phrase_days WHERE DAY = ? AND ID_ADMIN = ?');
            $st->execute([$day, $adminId]);
        } else {
            $st = $pdo->prepare('DELETE FROM base_records_phrase_days WHERE DAY = ?');
            $st->execute([$day]);
        }
        return (int)$st->rowCount();
    }

    /**
     * Upsert phrase + day row from extractor hit.
     *
     * @param array{hash:string,lemmas:string,words:int,example:string,count:int,records:list<int>,is_stop:bool,matched_key_id:int,samples?:list<array{record_id:int,before:string,hit:string,after:string}>} $hit
     */
    public static function upsertHit(array $hit, int $adminId, string $day): void
    {
        if (!self::tablesReady()) {
            return;
        }
        $pdo = Database::pdo();
        $hash = (string)$hit['hash'];
        $now = time();
        $st = $pdo->prepare('SELECT ID, STATUS, TOTAL_COUNT, DAYS_SEEN, ADMINS_SEEN FROM base_records_phrases WHERE HASH = ? LIMIT 1');
        $st->execute([$hash]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        $status = 'new';
        $phraseId = 0;
        if ($existing) {
            $phraseId = (int)$existing['ID'];
            $status = (string)$existing['STATUS'];
            // junk/skip: keep classification; do not refresh day hits (invisible in day report).
            if ($status === 'junk' || $status === 'skip') {
                return;
            }
            if (!empty($hit['matched_key_id']) && $status === 'new') {
                $status = 'key';
            }
            if (!empty($hit['is_stop']) && $status === 'new') {
                $status = 'junk';
            }
            $pdo->prepare(
                'UPDATE base_records_phrases
                 SET LEMMAS = ?, WORDS = ?, EXAMPLE = IF(EXAMPLE = "", ?, EXAMPLE),
                     STATUS = ?, ID_KEY = IF(ID_KEY > 0, ID_KEY, ?),
                     DT_LAST_SEEN = ?, TOTAL_COUNT = TOTAL_COUNT + ?
                 WHERE ID = ?'
            )->execute([
                (string)$hit['lemmas'],
                (int)$hit['words'],
                (string)$hit['example'],
                $status,
                (int)($hit['matched_key_id'] ?? 0),
                $now,
                (int)$hit['count'],
                $phraseId,
            ]);
        } else {
            if (!empty($hit['is_stop'])) {
                $status = 'junk';
            } elseif (!empty($hit['matched_key_id'])) {
                $status = 'key';
            }
            $pdo->prepare(
                'INSERT INTO base_records_phrases
                 (HASH, LEMMAS, WORDS, EXAMPLE, STATUS, ID_DICTONARY, ID_KEY,
                  DT_FIRST_SEEN, DT_LAST_SEEN, DAYS_SEEN, ADMINS_SEEN, TOTAL_COUNT)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 0, 0, ?)'
            )->execute([
                $hash,
                (string)$hit['lemmas'],
                (int)$hit['words'],
                (string)$hit['example'],
                $status,
                (int)($hit['matched_key_id'] ?? 0),
                $now,
                $now,
                (int)$hit['count'],
            ]);
            $phraseId = (int)$pdo->lastInsertId();
        }
        if ($phraseId < 1 || $status === 'junk') {
            return;
        }
        $samples = [];
        if (isset($hit['samples']) && is_array($hit['samples'])) {
            foreach ($hit['samples'] as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $hitText = trim((string)($s['hit'] ?? ''));
                if ($hitText === '') {
                    continue;
                }
                $samples[] = [
                    'record_id' => (int)($s['record_id'] ?? 0),
                    'before' => mb_substr((string)($s['before'] ?? ''), 0, 500),
                    'hit' => mb_substr($hitText, 0, 300),
                    'after' => mb_substr((string)($s['after'] ?? ''), 0, 500),
                ];
                if (count($samples) >= 5) {
                    break;
                }
            }
        }
        if ($samples === [] && (string)$hit['example'] !== '') {
            $samples[] = [
                'record_id' => 0,
                'before' => '',
                'hit' => mb_substr((string)$hit['example'], 0, 300),
                'after' => '',
            ];
        }
        $examples = json_encode([
            'example' => (string)$hit['example'],
            'records' => array_slice($hit['records'] ?? [], 0, 20),
            'samples' => $samples,
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            'INSERT INTO base_records_phrase_days
             (ID_PHRASE, ID_ADMIN, DAY, COUNT, RECORDS_COUNT, EXAMPLES)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               COUNT = COUNT + VALUES(COUNT),
               RECORDS_COUNT = GREATEST(RECORDS_COUNT, VALUES(RECORDS_COUNT)),
               EXAMPLES = VALUES(EXAMPLES)'
        )->execute([
            $phraseId,
            $adminId,
            $day,
            (int)$hit['count'],
            count($hit['records'] ?? []),
            $examples,
        ]);
        self::refreshStats($phraseId);
    }

    private static function refreshStats(int $phraseId): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT DAY) AS days_seen, COUNT(DISTINCT ID_ADMIN) AS admins_seen,
                    COALESCE(SUM(COUNT),0) AS total_count
             FROM base_records_phrase_days WHERE ID_PHRASE = ?'
        );
        $st->execute([$phraseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare(
            'UPDATE base_records_phrases
             SET DAYS_SEEN = ?, ADMINS_SEEN = ?, TOTAL_COUNT = ?
             WHERE ID = ?'
        )->execute([
            (int)($row['days_seen'] ?? 0),
            (int)($row['admins_seen'] ?? 0),
            (int)($row['total_count'] ?? 0),
            $phraseId,
        ]);
    }

    /**
     * Overlaps against surface example and lemmas (broader than exact NAME=).
     *
     * @return list<array{id:int,name:string,id_dictonary:int,dict_name:string,relation:string,relation_label:string}>
     */
    public static function collectKeyOverlaps(string $example, string $lemmas): array
    {
        if (!class_exists('RecordsKeysRepository', false)) {
            return [];
        }
        $labels = [
            'exact' => t('audio_phrases.overlap.exact'),
            'contains' => t('audio_phrases.overlap.contains'),
            'contained' => t('audio_phrases.overlap.contained'),
        ];
        $byId = [];
        foreach ([$example, $lemmas] as $probe) {
            $probe = trim($probe);
            if ($probe === '') {
                continue;
            }
            foreach (RecordsKeysRepository::findOverlaps($probe) as $hit) {
                $id = (int)$hit['id'];
                if (isset($byId[$id])) {
                    continue;
                }
                $rel = (string)($hit['relation'] ?? '');
                $hit['relation_label'] = $labels[$rel] ?? $rel;
                $byId[$id] = $hit;
            }
        }
        return array_values($byId);
    }
}

/** Thrown when create_key finds nested/similar keys and force was not set. */
final class PhraseKeyOverlapException extends RuntimeException
{
    /** @var list<array<string,mixed>> */
    public $overlaps;

    /** @param list<array<string,mixed>> $overlaps */
    public function __construct(array $overlaps)
    {
        $this->overlaps = $overlaps;
        parent::__construct(t('audio_phrases.err.key_overlaps'));
    }
}
