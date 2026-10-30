<?php
declare(strict_types=1);

/**
 * CRM procedure creates as script openings (TYPE + DTCREATE) + soft phrase attach.
 *
 * Parts use COND_TYPE=proc_created and store type ids in IDS_DICTONARYS (#1#,#40#).
 * CollectRunner never evaluates this COND_TYPE. After collect:
 *  1) Attach CRM hit to existing speech collections when they exist.
 *  2) If the proc_created part is a trigger (IS_REQ) — or there are no openings —
 *     seed rated collections from unmatched procedures (CRM-as-opener).
 *     Several creates for the same client within TIMEOUT (DTCREATE chain) glue
 *     into one episode («записали сразу на несколько»).
 *  3) Attach sibling dictionary parts (e.g. «предлагаем допы») from instances
 *     in a window around each collection.
 */
final class ProcedureCreatedCollect
{
    public const COND_TYPE = 'proc_created';

    /** Half-window around collection / create when part TIMEOUT is 0. */
    public const DEFAULT_WINDOW_SEC = 1800;

    /**
     * After CollectRunner rebuilt collections for one admin.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onCollectAfterAdmin($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!self::tableReady()) {
            return $payload;
        }
        $adminId = (int)($payload['admin_id'] ?? 0);
        if ($adminId < 1) {
            return $payload;
        }
        $winStart = (int)($payload['window_start'] ?? 0);
        $winEnd = (int)($payload['window_end'] ?? 0);
        $payload['proc_created_attached'] = self::attachForAdmin($adminId, $winStart, $winEnd);

        return $payload;
    }

    /**
     * Day-quality: % of script collections that have the proc_created part hit.
     *
     * @return array{available:bool,matched:int,sessions:int,percent:float,window_sec:int}
     */
    public static function matchedForScript(int $adminId, string $date, int $scriptId): array
    {
        $empty = [
            'available' => false,
            'matched' => 0,
            'sessions' => 0,
            'percent' => 0.0,
            'window_sec' => 0,
        ];
        if ($adminId < 1 || $scriptId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $empty;
        }
        if (!self::tableReady()
            || !Database::tableExists('base_records_collections')
            || !Database::tableExists('base_records_collections_datas')
        ) {
            return $empty;
        }
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            return $empty;
        }
        $dayEnd = (int)$dayStart + 86399;
        $parts = self::partsForScript($scriptId);
        if ($parts === []) {
            return $empty;
        }
        $windowSec = self::windowSecFromParts($parts);
        $partId = (int)$parts[0]['id'];

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT c.ID
             FROM base_records_collections c
             WHERE c.ID_ADMIN = ? AND c.ID_SCRIPT = ?
               AND c.DT_START <= ? AND (c.DT_END = 0 OR c.DT_END >= ?)'
        );
        $st->execute([$adminId, $scriptId, $dayEnd, $dayStart]);
        $colIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $sessions = count($colIds);
        if ($sessions < 1) {
            return [
                'available' => true,
                'matched' => 0,
                'sessions' => 0,
                'percent' => 0.0,
                'window_sec' => $windowSec,
            ];
        }
        $in = implode(',', $colIds);
        $matched = (int)$pdo->query(
            "SELECT COUNT(DISTINCT ID_COLLECTION) FROM base_records_collections_datas
             WHERE ID_PART = {$partId} AND ID_COLLECTION IN ($in)"
        )->fetchColumn();
        $pct = round(100.0 * $matched / $sessions, 1);

        return [
            'available' => true,
            'matched' => $matched,
            'sessions' => $sessions,
            'percent' => $pct,
            'window_sec' => $windowSec,
        ];
    }

    /**
     * @return array{collections:int,seeded:int,phrase_hits:int,score_rate_added:int}
     */
    public static function attachForAdmin(int $adminId, int $winStart, int $winEnd): array
    {
        $out = ['collections' => 0, 'seeded' => 0, 'phrase_hits' => 0, 'score_rate_added' => 0];
        if ($adminId < 1
            || !Database::tableExists('base_records_collections')
            || !Database::tableExists('base_records_collections_datas')
        ) {
            return $out;
        }
        $partsByScript = self::partsByScript();
        if ($partsByScript === []) {
            return $out;
        }

        foreach ($partsByScript as $scriptId => $parts) {
            $stats = self::syncScript($adminId, $scriptId, $parts, $winStart, $winEnd);
            $out['collections'] += (int)$stats['attached'];
            $out['seeded'] += (int)$stats['seeded'];
            $out['phrase_hits'] += (int)$stats['phrase_hits'];
            $out['score_rate_added'] += (int)$stats['score_rate_added'];
        }

        return $out;
    }

    /**
     * @param list<array{id:int,score_rate:int,timeout:int,type_ids:list<int>,is_req:int}> $parts
     * @return array{attached:int,seeded:int,phrase_hits:int,score_rate_added:int}
     */
    private static function syncScript(
        int $adminId,
        int $scriptId,
        array $parts,
        int $winStart,
        int $winEnd
    ): array {
        $out = ['attached' => 0, 'seeded' => 0, 'phrase_hits' => 0, 'score_rate_added' => 0];
        $windowSec = self::windowSecFromParts($parts);
        $part = $parts[0];
        $partId = (int)$part['id'];
        $scoreRate = (int)$part['score_rate'];
        $typeList = $part['type_ids'];
        if ($typeList === [] || $windowSec < 1) {
            return $out;
        }

        $pdo = Database::pdo();
        $hasScoreRate = Database::columnExists('base_records_collections', 'SCORE_RATE');
        $hasRated = Database::columnExists('base_records_collections', 'RATED');
        $ratingEnabled = self::scriptRatingEnabled($scriptId);

        $fetchStart = $winStart > 0 ? max(0, $winStart - $windowSec) : 0;
        $fetchEnd = $winEnd > 0 ? $winEnd + $windowSec : 0;

        $collections = self::loadCollections($adminId, $scriptId, $fetchStart, $fetchEnd);
        $procs = self::loadProcedures($adminId, $typeList, $fetchStart, $fetchEnd, $winStart, $winEnd, $windowSec);
        if ($procs === [] && $collections === []) {
            return $out;
        }

        $usedProc = [];

        // 1) Attach CRM hit to existing speech collections (nearest cluster).
        foreach ($collections as $col) {
            $colId = (int)$col['id'];
            if (self::partAlreadyHit($colId, $partId)) {
                continue;
            }
            $cStart = (int)$col['dt_start'];
            $cEnd = (int)$col['dt_end'] > 0 ? (int)$col['dt_end'] : $cStart;
            $best = null;
            $bestDist = PHP_INT_MAX;
            foreach ($procs as $proc) {
                $pid = (int)$proc['ID'];
                if (isset($usedProc[$pid])) {
                    continue;
                }
                $dt = (int)$proc['DTCREATE'];
                if ($dt < $cStart - $windowSec || $dt > $cEnd + $windowSec) {
                    continue;
                }
                $dist = abs($dt - $cEnd);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $proc;
                }
            }
            if ($best === null) {
                continue;
            }
            // Consume the whole same-client create burst around this procedure.
            $cluster = self::clusterAround($procs, $best, $windowSec, $usedProc);
            foreach ($cluster['proc_ids'] as $pid) {
                $usedProc[$pid] = true;
            }
            $dtCreate = (int)$cluster['dt_start'];
            self::insertPartHit($colId, $partId, $dtCreate);
            if ($hasScoreRate && $scoreRate !== 0) {
                $pdo->prepare('UPDATE base_records_collections SET SCORE_RATE = SCORE_RATE + ? WHERE ID = ?')
                    ->execute([$scoreRate, $colId]);
                $out['score_rate_added'] += $scoreRate;
            }
            $out['attached']++;
        }

        // 2) CRM-as-opener: seed one collection per create-burst (same client,
        //    DTCREATE chain within TIMEOUT) when trigger or no speech openings.
        $seedOpener = !empty($part['is_req']) || $collections === [];
        if ($seedOpener) {
            $remaining = [];
            foreach ($procs as $proc) {
                $pid = (int)$proc['ID'];
                if (isset($usedProc[$pid])) {
                    continue;
                }
                $dtCreate = (int)$proc['DTCREATE'];
                if ($winStart > 0 && $winEnd >= $winStart
                    && ($dtCreate < $winStart || $dtCreate > $winEnd)
                ) {
                    continue;
                }
                $remaining[] = $proc;
            }
            foreach (self::clusterProcedures($remaining, $windowSec) as $cluster) {
                $dtStart = (int)$cluster['dt_start'];
                $dtEnd = (int)$cluster['dt_end'];
                $colId = self::insertCollection(
                    $adminId,
                    $scriptId,
                    $dtStart,
                    $dtEnd,
                    $scoreRate,
                    $ratingEnabled,
                    $hasScoreRate,
                    $hasRated
                );
                if ($colId < 1) {
                    continue;
                }
                self::insertPartHit($colId, $partId, $dtStart);
                foreach ($cluster['proc_ids'] as $pid) {
                    $usedProc[$pid] = true;
                }
                $collections[] = [
                    'id' => $colId,
                    'dt_start' => $dtStart,
                    'dt_end' => $dtEnd,
                ];
                $out['seeded']++;
                $out['score_rate_added'] += $scoreRate;
            }
        }

        // 3) Phrase siblings (conversion / closer dict parts) near each collection.
        $phrase = self::attachPhrasePartsNearCollections(
            $adminId,
            $scriptId,
            $collections,
            $windowSec,
            $hasScoreRate
        );
        $out['phrase_hits'] += (int)$phrase['hits'];
        $out['score_rate_added'] += (int)$phrase['score_rate_added'];

        return $out;
    }

    /**
     * @param list<array{id:int,dt_start:int,dt_end:int}> $collections
     * @return array{hits:int,score_rate_added:int}
     */
    private static function attachPhrasePartsNearCollections(
        int $adminId,
        int $scriptId,
        array $collections,
        int $windowSec,
        bool $hasScoreRate
    ): array {
        $out = ['hits' => 0, 'score_rate_added' => 0];
        if ($collections === [] || !Database::tableExists('base_records_instances')) {
            return $out;
        }
        $phraseParts = self::phrasePartsForScript($scriptId);
        if ($phraseParts === []) {
            return $out;
        }
        $keyToParts = self::keysForPhraseParts($phraseParts);
        if ($keyToParts === []) {
            return $out;
        }
        $keyIds = array_keys($keyToParts);
        $inKeys = implode(',', array_map('intval', $keyIds));

        $minLo = PHP_INT_MAX;
        $maxHi = 0;
        foreach ($collections as $c) {
            $cStart = (int)$c['dt_start'];
            $cEnd = (int)$c['dt_end'] > 0 ? (int)$c['dt_end'] : $cStart;
            $minLo = min($minLo, $cStart - $windowSec);
            $maxHi = max($maxHi, $cEnd + $windowSec);
        }
        if ($minLo === PHP_INT_MAX) {
            return $out;
        }
        $minLo = max(0, $minLo);

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT ID, DT_START, DT_END, ID_KEY
             FROM base_records_instances
             WHERE ID_ADMIN = ?
               AND ID_KEY IN ($inKeys)
               AND DT_START >= ?
               AND DT_START <= ?
             ORDER BY DT_START ASC, ID ASC"
        );
        $st->execute([$adminId, $minLo, $maxHi]);
        $instances = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($instances === []) {
            return $out;
        }

        foreach ($collections as &$col) {
            $colId = (int)$col['id'];
            $cStart = (int)$col['dt_start'];
            $cEnd = (int)$col['dt_end'] > 0 ? (int)$col['dt_end'] : $cStart;
            $winLo = $cStart - $windowSec;
            $winHi = $cEnd + $windowSec;
            foreach ($phraseParts as $pp) {
                $ppId = (int)$pp['id'];
                if (self::partAlreadyHit($colId, $ppId)) {
                    continue;
                }
                $best = null;
                foreach ($instances as $inst) {
                    $keyId = (int)$inst['ID_KEY'];
                    $mapped = $keyToParts[$keyId] ?? [];
                    if (!in_array($ppId, $mapped, true)) {
                        continue;
                    }
                    $dt = (int)$inst['DT_START'];
                    if ($dt < $winLo || $dt > $winHi) {
                        continue;
                    }
                    $best = $inst;
                    break; // first chronological hit in window
                }
                if ($best === null) {
                    continue;
                }
                $hitStart = (int)$best['DT_START'];
                $hitEnd = (int)($best['DT_END'] ?? 0);
                if ($hitEnd < $hitStart) {
                    $hitEnd = $hitStart;
                }
                $pdo->prepare(
                    'INSERT INTO base_records_collections_datas
                     (DT_START, ID_COLLECTION, ID_PART, ID_INSTANCE, ID_KEY)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $hitStart,
                    $colId,
                    $ppId,
                    (int)$best['ID'],
                    (int)$best['ID_KEY'],
                ]);
                // Transcript UI slices by collection DT_START−15…DT_END+15 —
                // expand span so phrase hits before/after CRM create stay visible.
                self::expandCollectionSpan($colId, $hitStart, $hitEnd);
                $col['dt_start'] = min($cStart, $hitStart);
                $col['dt_end'] = max($cEnd, $hitEnd);
                $cStart = (int)$col['dt_start'];
                $cEnd = (int)$col['dt_end'];
                $rate = (int)$pp['score_rate'];
                if ($hasScoreRate && $rate !== 0) {
                    $pdo->prepare('UPDATE base_records_collections SET SCORE_RATE = SCORE_RATE + ? WHERE ID = ?')
                        ->execute([$rate, $colId]);
                    $out['score_rate_added'] += $rate;
                }
                $out['hits']++;
            }
        }
        unset($col);

        return $out;
    }

    /** Widen collection wall-clock span to cover an attached instance. */
    private static function expandCollectionSpan(int $colId, int $hitStart, int $hitEnd): void
    {
        if ($colId < 1 || $hitStart < 1) {
            return;
        }
        if ($hitEnd < $hitStart) {
            $hitEnd = $hitStart;
        }
        Database::pdo()->prepare(
            'UPDATE base_records_collections
             SET DT_START = LEAST(DT_START, ?),
                 DT_END = GREATEST(IF(DT_END > 0, DT_END, DT_START), ?)
             WHERE ID = ?'
        )->execute([$hitStart, $hitEnd, $colId]);
    }

    /**
     * @param list<int> $typeList
     * @return list<array<string,mixed>>
     */
    private static function loadProcedures(
        int $adminId,
        array $typeList,
        int $fetchStart,
        int $fetchEnd,
        int $winStart,
        int $winEnd,
        int $windowSec
    ): array {
        $in = implode(',', array_map('intval', $typeList));
        $lo = $fetchStart;
        $hi = $fetchEnd;
        if ($lo < 1 || $hi < 1) {
            // No collect window — use a wide day-less scan is unsafe; require window.
            if ($winStart > 0 && $winEnd >= $winStart) {
                $lo = max(0, $winStart - $windowSec);
                $hi = $winEnd + $windowSec;
            } else {
                return [];
            }
        }
        $st = Database::pdo()->prepare(
            "SELECT ID, TYPE, DTCREATE, ID_USER
             FROM base_procedures
             WHERE ID_ADMIN = ?
               AND TYPE IN ($in)
               AND ENABLED = 1
               AND DTCREATE >= ?
               AND DTCREATE <= ?
             ORDER BY DTCREATE ASC, ID ASC"
        );
        $st->execute([$adminId, $lo, $hi]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array{id:int,dt_start:int,dt_end:int}>
     */
    private static function loadCollections(int $adminId, int $scriptId, int $winStart, int $winEnd): array
    {
        $sql = 'SELECT ID AS id, DT_START AS dt_start, DT_END AS dt_end
                FROM base_records_collections
                WHERE ID_ADMIN = ? AND ID_SCRIPT = ?';
        $params = [$adminId, $scriptId];
        if ($winStart > 0 && $winEnd >= $winStart) {
            $sql .= ' AND DT_START <= ? AND (DT_END = 0 OR DT_END >= ?)';
            $params[] = $winEnd;
            $params[] = $winStart;
        }
        $sql .= ' ORDER BY DT_START ASC, ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
            ];
        }

        return $out;
    }

    /**
     * Glue consecutive creates for the same client (DTCREATE gap ≤ windowSec)
     * into one opening — «записали сразу на несколько процедур».
     *
     * @param list<array<string,mixed>> $procs sorted by DTCREATE
     * @return list<array{dt_start:int,dt_end:int,proc_ids:list<int>,id_user:int}>
     */
    private static function clusterProcedures(array $procs, int $windowSec): array
    {
        if ($procs === []) {
            return [];
        }
        $clusters = [];
        $cur = null;
        foreach ($procs as $proc) {
            $pid = (int)$proc['ID'];
            $dt = (int)$proc['DTCREATE'];
            $user = (int)($proc['ID_USER'] ?? 0);
            if ($cur === null) {
                $cur = [
                    'dt_start' => $dt,
                    'dt_end' => $dt,
                    'proc_ids' => [$pid],
                    'id_user' => $user,
                ];
                continue;
            }
            $sameUser = $user > 0 && $user === (int)$cur['id_user'];
            $gapOk = ($dt - (int)$cur['dt_end']) <= $windowSec;
            // Same client + quick successive creates → one episode.
            // No client id: still glue by time-only chain (rare floor creates).
            $glue = $gapOk && ($sameUser || (int)$cur['id_user'] === 0 || $user < 1);
            if ($glue) {
                $cur['dt_end'] = $dt;
                $cur['proc_ids'][] = $pid;
                if ($cur['id_user'] < 1 && $user > 0) {
                    $cur['id_user'] = $user;
                }
                continue;
            }
            $clusters[] = $cur;
            $cur = [
                'dt_start' => $dt,
                'dt_end' => $dt,
                'proc_ids' => [$pid],
                'id_user' => $user,
            ];
        }
        if ($cur !== null) {
            $clusters[] = $cur;
        }

        return $clusters;
    }

    /**
     * Expand a matched procedure into its same-client create burst.
     *
     * @param list<array<string,mixed>> $procs
     * @param array<string,mixed> $seed
     * @param array<int,true> $usedProc
     * @return array{dt_start:int,dt_end:int,proc_ids:list<int>,id_user:int}
     */
    private static function clusterAround(array $procs, array $seed, int $windowSec, array $usedProc): array
    {
        $seedId = (int)$seed['ID'];
        $user = (int)($seed['ID_USER'] ?? 0);
        $available = [];
        foreach ($procs as $proc) {
            $pid = (int)$proc['ID'];
            if ($pid !== $seedId && isset($usedProc[$pid])) {
                continue;
            }
            if ($user > 0 && (int)($proc['ID_USER'] ?? 0) !== $user) {
                continue;
            }
            if ($user < 1 && $pid !== $seedId) {
                // Without client id only keep the seed itself here; full chain
                // clustering is for seeding path.
                continue;
            }
            $available[] = $proc;
        }
        $clusters = self::clusterProcedures($available, $windowSec);
        foreach ($clusters as $c) {
            if (in_array($seedId, $c['proc_ids'], true)) {
                return $c;
            }
        }

        $dt = (int)$seed['DTCREATE'];

        return [
            'dt_start' => $dt,
            'dt_end' => $dt,
            'proc_ids' => [$seedId],
            'id_user' => $user,
        ];
    }

    private static function insertCollection(
        int $adminId,
        int $scriptId,
        int $dtStart,
        int $dtEnd,
        int $scoreRate,
        bool $ratingEnabled,
        bool $hasScoreRate,
        bool $hasRated
    ): int {
        $pdo = Database::pdo();
        $rated = $ratingEnabled ? 1 : 0;
        if ($dtEnd < $dtStart) {
            $dtEnd = $dtStart;
        }
        if ($hasScoreRate && $hasRated) {
            $pdo->prepare(
                'INSERT INTO base_records_collections
                 (DT_START, DT_END, ID_ADMIN, ID_SCRIPT, SCORE, SCORE_RATE, RATED, GATE_REASON)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?)'
            )->execute([$dtStart, $dtEnd, $adminId, $scriptId, $scoreRate, $rated, 'proc_created']);
        } elseif ($hasScoreRate) {
            $pdo->prepare(
                'INSERT INTO base_records_collections
                 (DT_START, DT_END, ID_ADMIN, ID_SCRIPT, SCORE, SCORE_RATE)
                 VALUES (?, ?, ?, ?, 0, ?)'
            )->execute([$dtStart, $dtEnd, $adminId, $scriptId, $scoreRate]);
        } else {
            $pdo->prepare(
                'INSERT INTO base_records_collections
                 (DT_START, DT_END, ID_ADMIN, ID_SCRIPT, SCORE)
                 VALUES (?, ?, ?, ?, 0)'
            )->execute([$dtStart, $dtEnd, $adminId, $scriptId]);
        }

        return (int)$pdo->lastInsertId();
    }

    private static function insertPartHit(int $colId, int $partId, int $dt): void
    {
        Database::pdo()->prepare(
            'INSERT INTO base_records_collections_datas
             (DT_START, ID_COLLECTION, ID_PART, ID_INSTANCE, ID_KEY)
             VALUES (?, ?, ?, 0, 0)'
        )->execute([$dt, $colId, $partId]);
    }

    private static function partAlreadyHit(int $colId, int $partId): bool
    {
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records_collections_datas
             WHERE ID_COLLECTION = ? AND ID_PART = ? LIMIT 1'
        );
        $st->execute([$colId, $partId]);

        return (int)$st->fetchColumn() > 0;
    }

    private static function scriptRatingEnabled(int $scriptId): bool
    {
        if (!Database::columnExists('base_records_scripts', 'RATING_ENABLED')) {
            return true;
        }
        $st = Database::pdo()->prepare(
            'SELECT RATING_ENABLED FROM base_records_scripts WHERE ID = ? AND PID = 0 LIMIT 1'
        );
        $st->execute([$scriptId]);

        return (int)$st->fetchColumn() > 0;
    }

    /**
     * Dictionary parts of the script (not proc_created).
     *
     * @return list<array{id:int,score_rate:int,dict_ids:list<int>}>
     */
    private static function phrasePartsForScript(int $scriptId): array
    {
        if (!Database::tableExists('base_records_scripts')) {
            return [];
        }
        $rateCol = Database::columnExists('base_records_scripts', 'SCORE_RATE')
            ? 'SCORE_RATE' : '0';
        $condCol = Database::columnExists('base_records_scripts', 'COND_TYPE')
            ? 'COND_TYPE' : "''";
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, {$rateCol} AS score_rate, IDS_DICTONARYS AS ids,
                    {$condCol} AS cond_type
             FROM base_records_scripts
             WHERE PID = ? AND DISABLED < 1"
        );
        $st->execute([$scriptId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cond = trim((string)($row['cond_type'] ?? ''));
            if ($cond !== '') {
                continue;
            }
            $dictIds = self::parseIds((string)($row['ids'] ?? ''));
            if ($dictIds === []) {
                continue;
            }
            $out[] = [
                'id' => (int)$row['id'],
                'score_rate' => (int)$row['score_rate'],
                'dict_ids' => $dictIds,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{id:int,dict_ids:list<int>}> $phraseParts
     * @return array<int, list<int>> keyId => partIds
     */
    private static function keysForPhraseParts(array $phraseParts): array
    {
        if (!Database::tableExists('base_records_keys')) {
            return [];
        }
        $dictIds = [];
        foreach ($phraseParts as $p) {
            foreach ($p['dict_ids'] as $d) {
                $dictIds[$d] = true;
            }
        }
        if ($dictIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', array_keys($dictIds)));
        $rows = Database::pdo()->query(
            "SELECT ID, ID_DICTONARY FROM base_records_keys
             WHERE DISABLED < 1 AND ID_DICTONARY IN ($in)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $keysByDict = [];
        foreach ($rows as $r) {
            $keysByDict[(int)$r['ID_DICTONARY']][] = (int)$r['ID'];
        }
        $out = [];
        foreach ($phraseParts as $p) {
            $partId = (int)$p['id'];
            foreach ($p['dict_ids'] as $did) {
                foreach ($keysByDict[$did] ?? [] as $keyId) {
                    if (!isset($out[$keyId])) {
                        $out[$keyId] = [];
                    }
                    if (!in_array($partId, $out[$keyId], true)) {
                        $out[$keyId][] = $partId;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, list<array{id:int,score_rate:int,timeout:int,type_ids:list<int>,is_req:int}>>
     */
    private static function partsByScript(): array
    {
        if (!Database::tableExists('base_records_scripts')
            || !Database::columnExists('base_records_scripts', 'COND_TYPE')
        ) {
            return [];
        }
        $rateCol = Database::columnExists('base_records_scripts', 'SCORE_RATE')
            ? 'SCORE_RATE' : '0';
        $toCol = Database::columnExists('base_records_scripts', 'TIMEOUT')
            ? 'TIMEOUT' : '0';
        $dictCol = Database::columnExists('base_records_scripts', 'IDS_DICTONARYS')
            ? 'IDS_DICTONARYS' : "''";
        $reqCol = Database::columnExists('base_records_scripts', 'IS_REQ')
            ? 'IS_REQ' : '0';
        $rows = Database::pdo()->query(
            "SELECT ID AS id, PID AS pid, {$rateCol} AS score_rate,
                    {$toCol} AS timeout, {$dictCol} AS ids_dictonarys, {$reqCol} AS is_req
             FROM base_records_scripts
             WHERE PID > 0 AND DISABLED < 1 AND COND_TYPE = 'proc_created'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $sid = (int)$row['pid'];
            if ($sid < 1) {
                continue;
            }
            $typeIds = self::parseIds((string)($row['ids_dictonarys'] ?? ''));
            if ($typeIds === []) {
                continue;
            }
            if (!isset($out[$sid])) {
                $out[$sid] = [];
            }
            $out[$sid][] = [
                'id' => (int)$row['id'],
                'score_rate' => (int)$row['score_rate'],
                'timeout' => (int)$row['timeout'],
                'type_ids' => $typeIds,
                'is_req' => (int)$row['is_req'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id:int,score_rate:int,timeout:int,type_ids:list<int>,is_req:int}>
     */
    private static function partsForScript(int $scriptId): array
    {
        return self::partsByScript()[$scriptId] ?? [];
    }

    /**
     * @param list<array{timeout?:int}> $parts
     */
    private static function windowSecFromParts(array $parts): int
    {
        foreach ($parts as $p) {
            $t = (int)($p['timeout'] ?? 0);
            if ($t > 0) {
                return min(86400, $t);
            }
        }

        return self::DEFAULT_WINDOW_SEC;
    }

    /** @return list<int> */
    private static function parseIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        preg_match_all('/\d+/', $raw, $m);
        $ids = [];
        foreach ($m[0] ?? [] as $n) {
            $i = (int)$n;
            if ($i > 0) {
                $ids[$i] = $i;
            }
        }

        return array_values($ids);
    }

    private static function tableReady(): bool
    {
        return Database::tableExists('base_procedures')
            && Database::columnExists('base_procedures', 'DTCREATE')
            && Database::columnExists('base_procedures', 'TYPE')
            && Database::columnExists('base_procedures', 'ID_ADMIN');
    }
}
