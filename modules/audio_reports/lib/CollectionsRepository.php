<?php
declare(strict_types=1);

/**
 * Runtime script collections (base_records_collections) — list / detail / delete.
 * Built by audio_pipeline CollectRunner; this module only browses and cleans them.
 */
final class CollectionsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'c.ID',
            'dt' => 'c.DT_START',
            'score' => 'c.SCORE',
            'admin' => 'c.ID_ADMIN',
            'script' => 'c.ID_SCRIPT',
        ];
    }

    public static function tablesReady(): bool
    {
        return Database::tableExists('base_records_collections');
    }

    /**
     * @param array{
     *   admin?:int,script?:int,year?:int,month?:int,day?:int,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int,totals:array{duration:int,score:int,score_intersect:int}}
     */
    public static function list(array $opts = []): array
    {
        $empty = [
            'items' => [],
            'total' => 0,
            'totals' => ['duration' => 0, 'score' => 0, 'score_intersect' => 0],
        ];
        if (!self::tablesReady()) {
            return $empty;
        }

        $pdo = Database::pdo();
        [$where, $params] = self::buildWhere($opts);
        $sqlWhere = implode(' AND ', $where);

        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_collections c WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'c.DT_START ASC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));

        $rateCols = '';
        if (Database::columnExists('base_records_collections', 'SCORE_RATE')) {
            $rateCols .= ', c.SCORE_RATE AS score_rate';
        } else {
            $rateCols .= ', 0 AS score_rate';
        }
        if (Database::columnExists('base_records_collections', 'RATED')) {
            $rateCols .= ', c.RATED AS rated';
        } else {
            $rateCols .= ', 0 AS rated';
        }
        if (Database::columnExists('base_records_collections', 'GATE_REASON')) {
            $rateCols .= ', c.GATE_REASON AS gate_reason';
        } else {
            $rateCols .= ", '' AS gate_reason";
        }
        $sql = "SELECT c.ID AS id, c.DT_START AS dt_start, c.DT_END AS dt_end,
                       c.ID_ADMIN AS id_admin, c.ID_SCRIPT AS id_script, c.SCORE AS score
                       {$rateCols}
                FROM base_records_collections c
                WHERE $sqlWhere
                ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($items === []) {
            return ['items' => [], 'total' => $total, 'totals' => $empty['totals']];
        }

        $enriched = self::enrich($items);
        $totals = ['duration' => 0, 'score' => 0, 'score_intersect' => 0];
        foreach ($enriched as $row) {
            $dur = max(0, (int)$row['dt_end'] - (int)$row['dt_start']);
            $score = max(0, (int)$row['score']);
            $totals['duration'] += $dur;
            $totals['score'] += $score;
            if (!empty($row['has_intersect'])) {
                $totals['score_intersect'] += $score;
            }
        }

        return ['items' => $enriched, 'total' => $total, 'totals' => $totals];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tablesReady()) {
            return null;
        }
        $rateCols = '';
        if (Database::columnExists('base_records_collections', 'SCORE_RATE')) {
            $rateCols .= ', SCORE_RATE AS score_rate';
        } else {
            $rateCols .= ', 0 AS score_rate';
        }
        if (Database::columnExists('base_records_collections', 'RATED')) {
            $rateCols .= ', RATED AS rated';
        } else {
            $rateCols .= ', 0 AS rated';
        }
        if (Database::columnExists('base_records_collections', 'GATE_REASON')) {
            $rateCols .= ', GATE_REASON AS gate_reason';
        } else {
            $rateCols .= ", '' AS gate_reason";
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_START AS dt_start, DT_END AS dt_end,
                    ID_ADMIN AS id_admin, ID_SCRIPT AS id_script, SCORE AS score'
            . $rateCols .
            ' FROM base_records_collections WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $items = self::enrich([$row]);
        return $items[0] ?? null;
    }

    /**
     * @param list<int> $ids
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === [] || !self::tablesReady()) {
            return 0;
        }
        $pdo = Database::pdo();
        $done = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $in = implode(',', $chunk);
            if (Database::tableExists('base_records_collections_datas')) {
                $pdo->exec("DELETE FROM base_records_collections_datas WHERE ID_COLLECTION IN ($in)");
            }
            $done += (int)$pdo->exec("DELETE FROM base_records_collections WHERE ID IN ($in)");
        }
        return $done;
    }

    /**
     * Highlighted transcript slices for collection rows (oper list parity).
     * Window = collection DT_START−15 … DT_END+15 against record DT_RECORDED + line offset.
     *
     * @param list<array<string,mixed>> $items enriched collection rows
     * @return array<int, array{html:string,was_intersect:bool}> keyed by collection id
     */
    public static function transcriptsForItems(array $items, bool $compact = true): array
    {
        $out = [];
        if ($items === []
            || !class_exists('RecordsRepository', false)
            || !class_exists('TranscriptHighlighter', false)
        ) {
            return $out;
        }

        $recordIds = [];
        foreach ($items as $item) {
            foreach ($item['record_ids'] ?? [] as $rid) {
                $rid = (int)$rid;
                if ($rid > 0) {
                    $recordIds[$rid] = true;
                }
            }
        }
        if ($recordIds === []) {
            return $out;
        }

        $ids = array_keys($recordIds);
        $rows = RecordsRepository::findByIds($ids);
        $instances = RecordsRepository::instancesForRecords($ids);
        $byRec = [];
        foreach ($instances as $inst) {
            $byRec[(int)$inst['id_record']][] = $inst;
        }
        $trash = RecordsRepository::trashPhrases();

        foreach ($items as $item) {
            $cid = (int)($item['id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $windowFrom = max(0, (int)($item['dt_start'] ?? 0) - 15);
            $windowTo = max(0, (int)($item['dt_end'] ?? 0) + 15);
            $blocks = [];
            $wasIntersect = !empty($item['has_intersect']);
            foreach ($item['record_ids'] ?? [] as $rid) {
                $rid = (int)$rid;
                if ($rid < 1 || !isset($rows[$rid])) {
                    continue;
                }
                $src = (string)($rows[$rid]['transcription'] ?? '');
                $dtRec = (int)($rows[$rid]['dt_recorded'] ?? 0);
                $ix = TranscriptHighlighter::parseIntersections(
                    isset($rows[$rid]['intersections']) ? (string)$rows[$rid]['intersections'] : null
                );
                $rendered = TranscriptHighlighter::render(
                    $src,
                    $byRec[$rid] ?? [],
                    $trash,
                    '',
                    [
                        'window_from' => $windowFrom,
                        'window_to' => $windowTo,
                        'dt_recorded' => $dtRec,
                        'intersections' => $ix,
                        'compact' => $compact,
                        'absolute_time' => !$compact,
                    ]
                );
                if (!empty($rendered['was_intersect'])) {
                    $wasIntersect = true;
                }
                $playUrl = '';
                if (!$compact && method_exists('RecordsRepository', 'playbackRelativePath')) {
                    $rel = RecordsRepository::playbackRelativePath($rows[$rid]);
                    if ($rel !== '') {
                        $playUrl = '/audio/play?' . http_build_query(['file' => $rel]);
                    }
                }
                $head = '<div class="ar-col-record-head"><a href="/audio/' . $rid . '">[' . $rid . ']</a></div>';
                $player = '';
                if ($playUrl !== '' && Auth::can('audio.view')) {
                    $player = '<div class="ar-col-player audio-player-shell" data-play-src="'
                        . htmlspecialchars($playUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '"><audio id="audio-player-' . $rid . '" class="ar-col-audio" controls preload="none" src="'
                        . htmlspecialchars($playUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '"></audio></div>';
                }
                $blocks[] = '<div class="ar-col-record-block" data-record-id="' . $rid . '">'
                    . $head . $player
                    . '<div class="transcript transcript--list' . ($compact ? ' transcript--compact' : '') . '">'
                    . (string)$rendered['html'] . '</div></div>';
            }
            $out[$cid] = [
                'html' => $blocks !== [] ? implode('', $blocks) : '',
                'was_intersect' => $wasIntersect,
            ];
        }

        return $out;
    }

    /** @return array<int,string> root script id => name */
    public static function scriptOptions(): array
    {
        if (!Database::tableExists('base_records_scripts')) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT ID AS id, NAME AS name FROM base_records_scripts
             WHERE PID = 0 ORDER BY NUM ASC, NAME ASC, ID ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $name = trim((string)$r['name']);
            $out[$id] = $name !== '' ? $name : ('#' . $id);
        }
        return $out;
    }

    /**
     * Admins that have collections in the given filter window (for filter select).
     *
     * @param array{admin?:int,script?:int,year?:int,month?:int,day?:int} $opts
     * @return array<int,string>
     */
    public static function adminOptions(array $opts = []): array
    {
        if (!self::tablesReady() || !Database::tableExists('base_users')) {
            return [];
        }
        $filter = $opts;
        unset($filter['admin']);
        [$where, $params] = self::buildWhere($filter);
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare(
            "SELECT DISTINCT c.ID_ADMIN AS id
             FROM base_records_collections c
             WHERE $sqlWhere AND c.ID_ADMIN > 0
             ORDER BY c.ID_ADMIN ASC
             LIMIT 500"
        );
        $st->execute($params);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $users = Database::pdo()->query(
            "SELECT id, name, login FROM base_users WHERE id IN ($in) ORDER BY name ASC, login ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($users as $u) {
            $label = trim((string)$u['name']);
            if ($label === '') {
                $label = trim((string)$u['login']);
            }
            if ($label === '') {
                $label = '#' . (int)$u['id'];
            }
            $out[(int)$u['id']] = $label;
        }
        return $out;
    }

    /**
     * @param array{admin?:int,script?:int,year?:int,month?:int,day?:int} $opts
     * @return array{0:list<string>,1:list<mixed>}
     */
    private static function buildWhere(array $opts): array
    {
        $where = ['c.ID > 0'];
        $params = [];

        $admin = max(0, (int)($opts['admin'] ?? 0));
        if ($admin > 0) {
            $where[] = 'c.ID_ADMIN = ?';
            $params[] = $admin;
        }

        $script = max(0, (int)($opts['script'] ?? 0));
        if ($script > 0) {
            $where[] = 'c.ID_SCRIPT = ?';
            $params[] = $script;
        }

        $year = (int)($opts['year'] ?? 0);
        $month = (int)($opts['month'] ?? 0);
        $day = (int)($opts['day'] ?? 0);
        if ($year >= 2000 && $year <= 2100) {
            if ($month >= 1 && $month <= 12) {
                if ($day >= 1 && $day <= 31) {
                    $from = mktime(0, 0, 0, $month, $day, $year);
                    $to = mktime(23, 59, 59, $month, $day, $year);
                } else {
                    $from = mktime(0, 0, 0, $month, 1, $year);
                    $to = mktime(0, 0, 0, $month + 1, 1, $year);
                    if ($to !== false) {
                        $to -= 1;
                    }
                }
            } else {
                $from = mktime(0, 0, 0, 1, 1, $year);
                $to = mktime(0, 0, 0, 1, 1, $year + 1);
                if ($to !== false) {
                    $to -= 1;
                }
            }
            if ($from !== false && $to !== false) {
                $where[] = 'c.DT_START >= ? AND c.DT_START <= ?';
                $params[] = (int)$from;
                $params[] = (int)$to;
            }
        }

        return [$where, $params];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function enrich(array $items): array
    {
        $pdo = Database::pdo();
        $ids = [];
        $scriptIds = [];
        $adminIds = [];
        foreach ($items as $row) {
            $cid = (int)$row['id'];
            $ids[] = $cid;
            $scriptIds[(int)$row['id_script']] = true;
            $adminIds[(int)$row['id_admin']] = true;
        }

        /** @var array<int,list<array{id_part:int,id_instance:int,id_key:int,dt_start:int}>> $datasByCol */
        $datasByCol = [];
        $instanceIds = [];
        $partIds = [];
        if ($ids !== [] && Database::tableExists('base_records_collections_datas')) {
            foreach (array_chunk($ids, 300) as $chunk) {
                $in = implode(',', $chunk);
                $st = $pdo->query(
                    "SELECT ID_COLLECTION AS id_collection, ID_PART AS id_part,
                            ID_INSTANCE AS id_instance, ID_KEY AS id_key, DT_START AS dt_start
                     FROM base_records_collections_datas
                     WHERE ID_COLLECTION IN ($in)
                     ORDER BY DT_START ASC, ID ASC"
                );
                if (!$st) {
                    continue;
                }
                while ($d = $st->fetch(PDO::FETCH_ASSOC)) {
                    $cid = (int)$d['id_collection'];
                    $datasByCol[$cid][] = [
                        'id_part' => (int)$d['id_part'],
                        'id_instance' => (int)$d['id_instance'],
                        'id_key' => (int)$d['id_key'],
                        'dt_start' => (int)$d['dt_start'],
                    ];
                    if ((int)$d['id_instance'] > 0) {
                        $instanceIds[(int)$d['id_instance']] = true;
                    }
                    if ((int)$d['id_part'] > 0) {
                        $partIds[(int)$d['id_part']] = true;
                    }
                }
            }
        }

        /** @var array<int,array{id_record:int,id_key:int}> $instances */
        $instances = [];
        $recordIds = [];
        $keyIds = [];
        if ($instanceIds !== [] && Database::tableExists('base_records_instances')) {
            $in = implode(',', array_keys($instanceIds));
            $st = $pdo->query(
                "SELECT ID AS id, ID_RECORD AS id_record, ID_KEY AS id_key
                 FROM base_records_instances WHERE ID IN ($in)"
            );
            if ($st) {
                while ($i = $st->fetch(PDO::FETCH_ASSOC)) {
                    $iid = (int)$i['id'];
                    $rid = (int)$i['id_record'];
                    $kid = (int)$i['id_key'];
                    $instances[$iid] = ['id_record' => $rid, 'id_key' => $kid];
                    if ($rid > 0) {
                        $recordIds[$rid] = true;
                    }
                    if ($kid > 0) {
                        $keyIds[$kid] = true;
                    }
                }
            }
        }
        foreach ($datasByCol as $rows) {
            foreach ($rows as $d) {
                if ($d['id_key'] > 0) {
                    $keyIds[$d['id_key']] = true;
                }
            }
        }

        /** @var array<int,array{name:string,finish:int}> $scriptNames */
        $scriptNames = [];
        $allScriptIds = array_unique(array_merge(array_keys($scriptIds), array_keys($partIds)));
        if ($allScriptIds !== [] && Database::tableExists('base_records_scripts')) {
            $in = implode(',', array_map('intval', $allScriptIds));
            $st = $pdo->query(
                "SELECT ID AS id, NAME AS name, FINISH AS finish
                 FROM base_records_scripts WHERE ID IN ($in)"
            );
            if ($st) {
                while ($s = $st->fetch(PDO::FETCH_ASSOC)) {
                    $scriptNames[(int)$s['id']] = [
                        'name' => trim((string)$s['name']),
                        'finish' => (int)$s['finish'],
                    ];
                }
            }
        }

        /** @var array<int,array{name:string,id_dict:int}> $keys */
        $keys = [];
        /** @var array<int,array{name:string,color:string,bg:string}> $dicts */
        $dicts = [];
        if ($keyIds !== [] && Database::tableExists('base_records_keys')) {
            $in = implode(',', array_keys($keyIds));
            $st = $pdo->query(
                "SELECT ID AS id, NAME AS name, ID_DICTONARY AS id_dict
                 FROM base_records_keys WHERE ID IN ($in)"
            );
            if ($st) {
                while ($k = $st->fetch(PDO::FETCH_ASSOC)) {
                    $kid = (int)$k['id'];
                    $did = (int)$k['id_dict'];
                    $keys[$kid] = [
                        'name' => str_replace('**', ' ', (string)$k['name']),
                        'id_dict' => $did,
                    ];
                    if ($did > 0) {
                        $dicts[$did] = $dicts[$did] ?? ['name' => '', 'color' => '', 'bg' => ''];
                    }
                }
            }
        }
        if ($dicts !== [] && Database::tableExists('base_records_dictonarys')) {
            $in = implode(',', array_keys($dicts));
            $st = $pdo->query(
                "SELECT ID AS id, NAME AS name, COLOR AS color, COLOR_BACKGROUND AS bg
                 FROM base_records_dictonarys WHERE ID IN ($in)"
            );
            if ($st) {
                while ($d = $st->fetch(PDO::FETCH_ASSOC)) {
                    $dicts[(int)$d['id']] = [
                        'name' => (string)$d['name'],
                        'color' => (string)$d['color'],
                        'bg' => (string)$d['bg'],
                    ];
                }
            }
        }

        /** @var array<int,array{intersections:int}> $records */
        $records = [];
        if ($recordIds !== [] && Database::tableExists('base_records')) {
            $hasIntersect = Database::columnExists('base_records', 'INTERSECTIONS');
            $cols = 'ID AS id' . ($hasIntersect ? ', INTERSECTIONS AS intersections' : '');
            $in = implode(',', array_keys($recordIds));
            $st = $pdo->query("SELECT $cols FROM base_records WHERE ID IN ($in)");
            if ($st) {
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $records[(int)$r['id']] = [
                        'intersections' => $hasIntersect ? (int)$r['intersections'] : 0,
                    ];
                }
            }
        }

        /** @var array<int,string> $adminNames */
        $adminNames = [];
        $adminIdList = array_values(array_filter(array_map('intval', array_keys($adminIds)), static fn ($i) => $i > 0));
        if ($adminIdList !== [] && Database::tableExists('base_users')) {
            $in = implode(',', $adminIdList);
            $st = $pdo->query("SELECT id, name, login FROM base_users WHERE id IN ($in)");
            if ($st) {
                while ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                    $label = trim((string)$u['name']);
                    if ($label === '') {
                        $label = trim((string)$u['login']);
                    }
                    $adminNames[(int)$u['id']] = $label !== '' ? $label : ('#' . (int)$u['id']);
                }
            }
        }

        $out = [];
        foreach ($items as $row) {
            $cid = (int)$row['id'];
            $sid = (int)$row['id_script'];
            $aid = (int)$row['id_admin'];
            $datas = $datasByCol[$cid] ?? [];

            $parts = [];
            $finished = false;
            $keyHits = [];
            $recIds = [];
            $hasIntersect = false;

            foreach ($datas as $d) {
                $pid = $d['id_part'];
                if ($pid > 0 && !isset($parts[$pid])) {
                    $meta = $scriptNames[$pid] ?? ['name' => '#' . $pid, 'finish' => 0];
                    $parts[$pid] = [
                        'id' => $pid,
                        'name' => $meta['name'] !== '' ? $meta['name'] : ('#' . $pid),
                        'finish' => (int)$meta['finish'],
                    ];
                    if ((int)$meta['finish'] > 0) {
                        $finished = true;
                    }
                }
                $kid = $d['id_key'];
                if ($kid < 1 && isset($instances[$d['id_instance']])) {
                    $kid = (int)$instances[$d['id_instance']]['id_key'];
                }
                if ($kid > 0 && !isset($keyHits[$kid])) {
                    $kmeta = $keys[$kid] ?? ['name' => '#' . $kid, 'id_dict' => 0];
                    $did = (int)$kmeta['id_dict'];
                    $dmeta = $dicts[$did] ?? ['name' => '', 'color' => '', 'bg' => ''];
                    $keyHits[$kid] = [
                        'id' => $kid,
                        'name' => (string)$kmeta['name'],
                        'dict_name' => (string)$dmeta['name'],
                        'color' => (string)$dmeta['color'],
                        'bg' => (string)$dmeta['bg'],
                    ];
                }
                if ($d['id_instance'] > 0 && isset($instances[$d['id_instance']])) {
                    $rid = (int)$instances[$d['id_instance']]['id_record'];
                    if ($rid > 0) {
                        $recIds[$rid] = true;
                        if (!empty($records[$rid]['intersections'])) {
                            $hasIntersect = true;
                        }
                    }
                }
            }

            $scriptName = $scriptNames[$sid]['name'] ?? '';
            if ($scriptName === '') {
                $scriptName = '#' . $sid;
            }

            $out[] = [
                'id' => $cid,
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
                'id_admin' => $aid,
                'admin_name' => $adminNames[$aid] ?? ('#' . $aid),
                'id_script' => $sid,
                'script_name' => $scriptName,
                'score' => (int)$row['score'],
                'score_rate' => (int)($row['score_rate'] ?? 0),
                'rated' => (int)($row['rated'] ?? 0),
                'gate_reason' => (string)($row['gate_reason'] ?? ''),
                'finished' => $finished,
                'parts' => array_values($parts),
                'keys' => array_values($keyHits),
                'record_ids' => array_keys($recIds),
                'has_intersect' => $hasIntersect,
                'duration' => max(0, (int)$row['dt_end'] - (int)$row['dt_start']),
            ];
        }

        return $out;
    }
}
