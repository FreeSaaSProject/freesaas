<?php
declare(strict_types=1);

/**
 * SQL day/admin rollups for the records calendar (not pageStats over a list page).
 */
final class CalendarAggregates
{
    /** Oper promoter bonus marker — excluded from ® (show_records_calendars). */
    private const BONUS_MARKER = 'BONUS TIME';

    /**
     * Request-scoped OffRecord/OffRate windows: "Y-m-d:OffRecord" → [start, end].
     *
     * @var array<string, array{0:int,1:int}|null>
     */
    private static $dayWindowCache = [];

    /**
     * @param array{admin?:int,badge?:int,position?:int,positions?:list<int>} $filters
     * @return array{
     *   by_day: array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>,
     *   by_admin_day: array<int, array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>>,
     *   by_admin: array<int, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>,
     *   by_unassigned_badge_day: array<string, array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>>,
     *   by_unassigned_badge: array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>,
     *   admins: list<int>
     * }
     */
    public static function forRange(int $fromTs, int $toTs, array $filters = []): array
    {
        $empty = [
            'by_day' => [],
            'by_admin_day' => [],
            'by_admin' => [],
            'by_unassigned_badge_day' => [],
            'by_unassigned_badge' => [],
            'admins' => [],
        ];
        if ($fromTs < 1 || $toTs < $fromTs || !Database::tableExists('base_records')) {
            return $empty;
        }

        $adminFilter = max(0, (int)($filters['admin'] ?? 0));
        $badgeFilter = max(0, (int)($filters['badge'] ?? 0));
        $positionFilter = max(0, (int)($filters['position'] ?? 0));
        $positionsScope = [];
        if (isset($filters['positions']) && is_array($filters['positions'])) {
            foreach ($filters['positions'] as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $positionsScope[$pid] = true;
                }
            }
        }
        $positionsScope = array_keys($positionsScope);

        $bounds = AudioDayBounds::read();
        self::prewarmDayWindows($fromTs, $toTs, $bounds);
        if (class_exists('StageTiming', false)) {
            StageTiming::mark('CalendarAgg: windows');
        }

        $pdo = Database::pdo();
        $hasDesc = Database::columnExists('base_records', 'DESCRIPTION');
        $sql = 'SELECT r.ID_ADMIN AS id_admin, r.ID_DICTOPHONE AS id_dictophone, r.DT_RECORDED AS dt,
                       r.PLAYTIME AS playtime, r.SCORE AS score, r.ERROR_TRANS AS error_trans,
                       r.QUALITY AS quality, r.STRINGS AS strings, r.STRINGS_NOK AS strings_nok'
            . ($hasDesc ? ', r.DESCRIPTION AS description' : '')
            . ' FROM base_records r';
        $params = [];
        $needUsersJoin = ($positionFilter > 0 || $positionsScope !== []) && Database::tableExists('base_users');
        if ($needUsersJoin) {
            // LEFT JOIN so ID_ADMIN=0 (unassigned) still appears; filter assigned by position.
            $sql .= ' LEFT JOIN base_users u ON u.id = r.ID_ADMIN';
        }
        $sql .= ' WHERE r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
                  AND (r.DISABLED IS NULL OR r.DISABLED < 1)';
        $params[] = $fromTs;
        $params[] = $toTs;
        if ($needUsersJoin) {
            if ($positionFilter > 0) {
                $sql .= ' AND (r.ID_ADMIN < 1 OR u.ID_STATUS = ?)';
                $params[] = $positionFilter;
            } elseif ($positionsScope !== []) {
                $ph = implode(',', array_fill(0, count($positionsScope), '?'));
                $sql .= " AND (r.ID_ADMIN < 1 OR u.ID_STATUS IN ($ph))";
                foreach ($positionsScope as $pid) {
                    $params[] = $pid;
                }
            }
        }
        if ($adminFilter > 0) {
            $sql .= ' AND r.ID_ADMIN = ?';
            $params[] = $adminFilter;
        }
        if ($badgeFilter > 0) {
            $sql .= ' AND r.ID_DICTOPHONE = ?';
            $params[] = $badgeFilter;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $byDay = [];
        $byAdminDay = [];
        $byAdmin = [];
        $byUnassignedBadgeDay = [];
        $byUnassignedBadge = [];
        $admins = [];
        /** @var array<int, array<string, int>> $recordSec OffRecord non-bonus playtime for ® overlay */
        $recordSec = [];

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dt = (int)($row['dt'] ?? 0);
            if ($dt < 1) {
                continue;
            }
            $dayKey = date('Y-m-d', $dt);
            $adminId = (int)($row['id_admin'] ?? 0);
            $badgeCode = trim((string)($row['id_dictophone'] ?? ''));
            if ($badgeCode === '' || $badgeCode === '0') {
                $badgeCode = '0';
            }
            $play = max(0, (int)($row['playtime'] ?? 0));
            $score = max(0.0, (float)($row['score'] ?? 0));
            $strings = max(0, (int)($row['strings'] ?? 0));
            $stringsNok = max(0, (int)($row['strings_nok'] ?? 0));
            $isErr = !empty($row['error_trans']) || ((int)($row['quality'] ?? 0) < 2 && $play < 1);
            $inRecord = self::isFullyInWindow($dt, $play, $bounds, 'OffRecord');
            $outside = $inRecord ? 0 : $play;
            $isBonus = $hasDesc && (string)($row['description'] ?? '') === self::BONUS_MARKER;

            // Legacy ® from base_records.SCORE (kept until rated collections exist for the day).
            $scoreW = 0.0;
            $scoreSec = 0;
            if ($inRecord && !$isBonus) {
                $inRate = self::isFullyInWindow($dt, $play, $bounds, 'OffRate');
                if ($inRate || $score > 2) {
                    $scoreW = $score * $play;
                    $scoreSec = $play;
                }
                // Same denominator rules as batchRatedScores (OffRecord, non-bonus).
                if ($adminId > 0 && $play > 0) {
                    $recordSec[$adminId][$dayKey] = ($recordSec[$adminId][$dayKey] ?? 0) + $play;
                }
            }

            self::bump($byDay, $dayKey, $play, $scoreW, $scoreSec, $isErr, $outside, $strings, $stringsNok);
            if ($adminId > 0) {
                if (!isset($byAdminDay[$adminId])) {
                    $byAdminDay[$adminId] = [];
                    $admins[$adminId] = true;
                }
                self::bump($byAdminDay[$adminId], $dayKey, $play, $scoreW, $scoreSec, $isErr, $outside, $strings, $stringsNok);
                self::bumpAdmin($byAdmin, $adminId, $play, $scoreW, $scoreSec, $isErr, $outside, $strings, $stringsNok);
            } elseif ($adminFilter < 1) {
                // Oper: unassigned rows split by badge (different badges ≈ different people).
                if (!isset($byUnassignedBadgeDay[$badgeCode])) {
                    $byUnassignedBadgeDay[$badgeCode] = [];
                }
                self::bump($byUnassignedBadgeDay[$badgeCode], $dayKey, $play, $scoreW, $scoreSec, $isErr, $outside, $strings, $stringsNok);
                self::bumpAdmin($byUnassignedBadge, $badgeCode, $play, $scoreW, $scoreSec, $isErr, $outside, $strings, $stringsNok);
            }
        }
        if (class_exists('StageTiming', false)) {
            StageTiming::mark('CalendarAgg: records loop');
        }

        // Prefer collection-based ® when rated collections exist; otherwise keep legacy SCORE above.
        self::overlayCollectionRating($byDay, $byAdminDay, $byAdmin, $bounds, $recordSec);
        // Overlay rebuilds day ® from staff only — put unassigned legacy scores back.
        foreach ($byUnassignedBadgeDay as $badgeDays) {
            foreach ($badgeDays as $dayKey => $cell) {
                if (!isset($byDay[$dayKey])) {
                    continue;
                }
                $byDay[$dayKey]['score_w'] += (float)($cell['score_w'] ?? 0);
                $byDay[$dayKey]['score_sec'] += (int)($cell['score_sec'] ?? 0);
            }
        }
        if (class_exists('StageTiming', false)) {
            StageTiming::mark('CalendarAgg: rating overlay');
        }

        ksort($admins);
        ksort($byUnassignedBadgeDay, SORT_NATURAL);
        return [
            'by_day' => $byDay,
            'by_admin_day' => $byAdminDay,
            'by_admin' => $byAdmin,
            'by_unassigned_badge_day' => $byUnassignedBadgeDay,
            'by_unassigned_badge' => $byUnassignedBadge,
            'admins' => array_map('intval', array_keys($admins)),
        ];
    }

    /**
     * Replace score_w/score_sec from RatingAggregates (SCORE_RATE × duration / accounted secs).
     * Falls back to legacy record SCORE when pipeline rating is unavailable.
     *
     * @param array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}> $byDay
     * @param array<int, array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>> $byAdminDay
     * @param array<int, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}> $byAdmin
     * @param array<string,int> $bounds
     * @param array<int, array<string, int>> $recordSec OffRecord non-bonus playtime from the first records pass
     */
    private static function overlayCollectionRating(
        array &$byDay,
        array &$byAdminDay,
        array &$byAdmin,
        array $bounds,
        array $recordSec = []
    ): void {
        if (!class_exists('RatingAggregates', false)
            || !Database::tableExists('base_records_collections')
            || !Database::columnExists('base_records_collections', 'SCORE_RATE')
        ) {
            return;
        }

        /** @var array<int, array<string, array{0:float,1:int}>> $legacyScores */
        $legacyScores = [];
        $adminIds = [];
        $minDay = '';
        $maxDay = '';
        foreach ($byAdminDay as $adminId => $days) {
            $adminId = (int)$adminId;
            if ($adminId > 0) {
                $adminIds[$adminId] = true;
            }
            foreach ($days as $dayKey => $cell) {
                $dayKey = (string)$dayKey;
                $legacyScores[$adminId][$dayKey] = [
                    (float)($cell['score_w'] ?? 0),
                    (int)($cell['score_sec'] ?? 0),
                ];
                if ($minDay === '' || $dayKey < $minDay) {
                    $minDay = $dayKey;
                }
                if ($maxDay === '' || $dayKey > $maxDay) {
                    $maxDay = $dayKey;
                }
            }
        }
        if ($adminIds === [] || $minDay === '' || $maxDay === '') {
            return;
        }
        $rangeStart = strtotime($minDay . ' 00:00:00');
        $rangeEnd = strtotime($maxDay . ' 23:59:59');
        if ($rangeStart === false || $rangeEnd === false) {
            return;
        }

        // Collections pass only — recordSec comes from forRange (avoids second base_records scan).
        $ratedDays = self::batchRatedScores(
            array_keys($adminIds),
            (int)$rangeStart,
            (int)$rangeEnd,
            $bounds,
            $recordSec
        );

        foreach ($byDay as $dk => $_) {
            $byDay[$dk]['score_w'] = 0.0;
            $byDay[$dk]['score_sec'] = 0;
        }
        foreach ($byAdmin as $aid => $_) {
            $byAdmin[$aid]['score_w'] = 0.0;
            $byAdmin[$aid]['score_sec'] = 0;
        }
        foreach ($byAdminDay as $adminId => $days) {
            foreach ($days as $dayKey => $_) {
                $byAdminDay[$adminId][$dayKey]['score_w'] = 0.0;
                $byAdminDay[$adminId][$dayKey]['score_sec'] = 0;
            }
        }

        foreach ($legacyScores as $adminId => $days) {
            $adminId = (int)$adminId;
            if ($adminId < 1) {
                continue;
            }
            foreach ($days as $dayKey => $legacyPair) {
                $dayKey = (string)$dayKey;
                $hasRated = isset($ratedDays[$adminId][$dayKey]);
                if (!$hasRated) {
                    $sw = (float)$legacyPair[0];
                    $ss = (int)$legacyPair[1];
                } else {
                    $sw = (float)$ratedDays[$adminId][$dayKey]['score_w'];
                    $ss = (int)$ratedDays[$adminId][$dayKey]['score_sec'];
                }
                $byAdminDay[$adminId][$dayKey]['score_w'] = $sw;
                $byAdminDay[$adminId][$dayKey]['score_sec'] = $ss;
                if (!isset($byDay[$dayKey])) {
                    $byDay[$dayKey] = [
                        'count' => 0, 'playtime' => 0, 'score_w' => 0.0, 'score_sec' => 0,
                        'errors' => 0, 'outside' => 0, 'strings' => 0, 'strings_nok' => 0,
                    ];
                }
                $byDay[$dayKey]['score_w'] += $sw;
                $byDay[$dayKey]['score_sec'] += $ss;
                if (!isset($byAdmin[$adminId])) {
                    $byAdmin[$adminId] = [
                        'count' => 0, 'playtime' => 0, 'score_w' => 0.0, 'score_sec' => 0,
                        'errors' => 0, 'outside' => 0, 'strings' => 0, 'strings_nok' => 0,
                    ];
                }
                $byAdmin[$adminId]['score_w'] += $sw;
                $byAdmin[$adminId]['score_sec'] += $ss;
            }
        }
    }

    /**
     * Batch ® per admin-day from rated collections + OffRecord playtime.
     *
     * @param list<int> $adminIds
     * @param array<string,int> $bounds
     * @param array<int, array<string, int>>|null $precomputedRecordSec From forRange; null = scan base_records
     * @return array<int, array<string, array{score_w:float,score_sec:int}>>
     */
    private static function batchRatedScores(
        array $adminIds,
        int $rangeStart,
        int $rangeEnd,
        array $bounds,
        ?array $precomputedRecordSec = null
    ): array {
        $out = [];
        $adminIds = array_values(array_filter(array_map('intval', $adminIds), static fn ($i) => $i > 0));
        if ($adminIds === [] || $rangeEnd < $rangeStart) {
            return $out;
        }
        $pdo = Database::pdo();
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $hasRated = Database::columnExists('base_records_collections', 'RATED');
        $ratedWhere = $hasRated ? ' AND c.RATED = 1' : '';

        $params = $adminIds;
        $params[] = $rangeEnd + 86400;
        $params[] = max(0, $rangeStart - 86400);
        $st = $pdo->prepare(
            "SELECT c.ID_ADMIN AS id_admin, c.DT_START AS dt_start, c.DT_END AS dt_end, c.SCORE_RATE AS score_rate
             FROM base_records_collections c
             WHERE c.ID_ADMIN IN ($ph)
               AND c.DT_START <= ?
               AND (c.DT_END = 0 OR c.DT_END >= ?)
               {$ratedWhere}
             ORDER BY c.ID_ADMIN ASC, c.DT_START ASC"
        );
        $st->execute($params);

        /** @var array<int, array<string, float>> $scoreW */
        $scoreW = [];
        /** @var array<int, array<string, true>> $hasCol */
        $hasCol = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int)$row['id_admin'];
            $dtStart = (int)$row['dt_start'];
            $dtEnd = (int)$row['dt_end'];
            if ($dtEnd < $dtStart) {
                $dtEnd = $dtStart;
            }
            if ($dtEnd < 1) {
                $dtEnd = $dtStart + 1;
            }
            $rate = (int)$row['score_rate'];
            $dayFrom = max($dtStart, $rangeStart);
            $dayTo = min($dtEnd, $rangeEnd);
            if ($dayTo < $dayFrom) {
                continue;
            }
            // Split contribution across calendar days overlapping the collection.
            $cursor = (int)mktime(0, 0, 0, (int)date('n', $dayFrom), (int)date('j', $dayFrom), (int)date('Y', $dayFrom));
            while ($cursor <= $dayTo) {
                $dayKey = date('Y-m-d', $cursor);
                $dayEndTs = $cursor + 86400 - 1;
                $clipStart = max($dtStart, $cursor, $rangeStart);
                $clipEnd = min($dtEnd, $dayEndTs, $rangeEnd);
                if ($clipEnd >= $clipStart) {
                    $useDur = max(1, $clipEnd - $clipStart);
                    if (!isset($scoreW[$aid][$dayKey])) {
                        $scoreW[$aid][$dayKey] = 0.0;
                    }
                    $scoreW[$aid][$dayKey] += $rate * $useDur;
                    $hasCol[$aid][$dayKey] = true;
                }
                $cursor += 86400;
            }
        }

        if ($hasCol === []) {
            return $out;
        }

        /** @var array<int, array<string, int>> $recordSec */
        $recordSec = [];
        if ($precomputedRecordSec !== null) {
            $recordSec = $precomputedRecordSec;
        } else {
            $hasDesc = Database::columnExists('base_records', 'DESCRIPTION');
            $rParams = $adminIds;
            $rParams[] = $rangeStart;
            $rParams[] = $rangeEnd;
            $rsql = 'SELECT ID_ADMIN AS id_admin, DT_RECORDED AS dt, PLAYTIME AS playtime'
                . ($hasDesc ? ', DESCRIPTION AS description' : '')
                . " FROM base_records
                   WHERE ID_ADMIN IN ($ph) AND DT_RECORDED >= ? AND DT_RECORDED <= ?
                     AND (DISABLED IS NULL OR DISABLED < 1)";
            $rst = $pdo->prepare($rsql);
            $rst->execute($rParams);
            while ($row = $rst->fetch(PDO::FETCH_ASSOC)) {
                if ($hasDesc && (string)($row['description'] ?? '') === self::BONUS_MARKER) {
                    continue;
                }
                $dt = (int)$row['dt'];
                $play = max(0, (int)$row['playtime']);
                if ($play < 1 || $dt < 1) {
                    continue;
                }
                if (!self::isFullyInWindow($dt, $play, $bounds, 'OffRecord')) {
                    continue;
                }
                $aid = (int)$row['id_admin'];
                $dayKey = date('Y-m-d', $dt);
                if (!isset($hasCol[$aid][$dayKey])) {
                    continue;
                }
                $recordSec[$aid][$dayKey] = ($recordSec[$aid][$dayKey] ?? 0) + $play;
            }
        }

        foreach ($hasCol as $aid => $days) {
            foreach ($days as $dayKey => $_) {
                $out[$aid][$dayKey] = [
                    'score_w' => (float)($scoreW[$aid][$dayKey] ?? 0),
                    'score_sec' => (int)($recordSec[$aid][$dayKey] ?? 0),
                ];
            }
        }
        return $out;
    }

    /**
     * Tracking dictionaries with TRACKING_CODE → counts per admin/day (Y-m-d).
     *
     * @param array{admin?:int,badge?:int,position?:int} $filters
     * @return array{
     *   dicts: list<array{id:int,name:string,code:string}>,
     *   by_admin_day: array<int, array<int, array<string,int>>>,
     *   by_admin: array<int, array<int,int>>
     * }
     */
    public static function trackingForRange(int $fromTs, int $toTs, array $filters = []): array
    {
        $out = ['dicts' => [], 'by_admin_day' => [], 'by_admin' => []];
        if ($fromTs < 1 || $toTs < $fromTs
            || !Database::tableExists('base_records_dictonarys')
            || !Database::tableExists('base_records_keys')
            || !Database::tableExists('base_records_instances')
        ) {
            return $out;
        }

        $adminFilter = max(0, (int)($filters['admin'] ?? 0));
        $badgeFilter = max(0, (int)($filters['badge'] ?? 0));
        $positionFilter = max(0, (int)($filters['position'] ?? 0));

        $dicts = Database::pdo()->query(
            "SELECT ID AS id, NAME AS name, TRACKING_CODE AS code
             FROM base_records_dictonarys
             WHERE DISABLED < 1 AND TRACKING_CODE IS NOT NULL AND TRIM(TRACKING_CODE) <> ''
             ORDER BY NAME ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($dicts === []) {
            return $out;
        }

        foreach ($dicts as $d) {
            $out['dicts'][] = [
                'id' => (int)$d['id'],
                'name' => (string)$d['name'],
                // TRACKING_CODE may store HTML entities (&#128545;) — decode to emoji for display
                'code' => self::decodeTrackingCode(trim((string)$d['code'])),
            ];
        }

        $dictIds = array_column($out['dicts'], 'id');
        $inD = implode(',', array_map('intval', $dictIds));
        // Resolve key ids first — avoids a full scan of base_records_instances (no DT_START-leading index).
        $keyToDict = [];
        $stKeys = Database::pdo()->query(
            "SELECT ID AS id, ID_DICTONARY AS id_dict
             FROM base_records_keys
             WHERE ID_DICTONARY IN ($inD)"
        );
        if ($stKeys) {
            while ($row = $stKeys->fetch(PDO::FETCH_ASSOC)) {
                $kid = (int)($row['id'] ?? 0);
                $did = (int)($row['id_dict'] ?? 0);
                if ($kid > 0 && $did > 0) {
                    $keyToDict[$kid] = $did;
                }
            }
        }
        if ($keyToDict === []) {
            return $out;
        }
        $inK = implode(',', array_map('intval', array_keys($keyToDict)));

        $sql = 'SELECT i.ID_ADMIN AS id_admin, i.ID_KEY AS id_key, i.DT_START AS dt_start
                FROM base_records_instances i';
        $params = [];
        if ($badgeFilter > 0 && Database::tableExists('base_records')) {
            $sql .= ' INNER JOIN base_records r ON r.ID = i.ID_RECORD AND r.ID_DICTOPHONE = ?';
            $params[] = $badgeFilter;
        }
        if ($positionFilter > 0 && Database::tableExists('base_users')) {
            $sql .= ' INNER JOIN base_users u ON u.id = i.ID_ADMIN AND u.ID_STATUS = ?';
            $params[] = $positionFilter;
        }
        $sql .= " WHERE i.DT_START >= ? AND i.DT_START <= ?
                  AND i.ID_KEY IN ($inK)
                  AND i.ID_ADMIN > 0";
        $params[] = $fromTs;
        $params[] = $toTs;
        if ($adminFilter > 0) {
            $sql .= ' AND i.ID_ADMIN = ?';
            $params[] = $adminFilter;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int)$row['id_admin'];
            $did = $keyToDict[(int)$row['id_key']] ?? 0;
            $dt = (int)$row['dt_start'];
            if ($aid < 1 || $did < 1 || $dt < 1) {
                continue;
            }
            $day = date('Y-m-d', $dt);
            if (!isset($out['by_admin_day'][$aid][$did][$day])) {
                $out['by_admin_day'][$aid][$did][$day] = 0;
            }
            $out['by_admin_day'][$aid][$did][$day]++;
            if (!isset($out['by_admin'][$aid][$did])) {
                $out['by_admin'][$aid][$did] = 0;
            }
            $out['by_admin'][$aid][$did]++;
        }
        return $out;
    }

    /**
     * Script collections per admin/day (starts / finishes / score), oper calendar parity.
     *
     * @param array{admin?:int,badge?:int,position?:int} $filters
     * @return array{
     *   scripts: list<array{id:int,name:string,short:string}>,
     *   by_admin_day: array<int, array<int, array<string, array{starts:int,finishes:int,score:int}>>>,
     *   by_admin: array<int, array<int, array{starts:int,finishes:int,score:int}>>
     * }
     */
    public static function scriptsForRange(int $fromTs, int $toTs, array $filters = []): array
    {
        $emptyCell = ['starts' => 0, 'finishes' => 0, 'score' => 0];
        $out = ['scripts' => [], 'by_admin_day' => [], 'by_admin' => []];
        if ($fromTs < 1 || $toTs < $fromTs
            || !Database::tableExists('base_records_scripts')
            || !Database::tableExists('base_records_collections')
        ) {
            return $out;
        }

        $adminFilter = max(0, (int)($filters['admin'] ?? 0));
        $badgeFilter = max(0, (int)($filters['badge'] ?? 0));
        $positionFilter = max(0, (int)($filters['position'] ?? 0));
        $pdo = Database::pdo();

        $roots = $pdo->query(
            'SELECT ID AS id, NAME AS name
             FROM base_records_scripts
             WHERE PID = 0 AND DISABLED < 1
             ORDER BY NUM ASC, ID ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($roots === []) {
            return $out;
        }

        /** @var array<int, int> $finishPartByScript finish part id keyed by root script id */
        $finishPartByScript = [];
        $stFinish = $pdo->query(
            'SELECT ID AS id, PID AS pid
             FROM base_records_scripts
             WHERE PID > 0 AND FINISH > 0 AND DISABLED < 1'
        );
        if ($stFinish) {
            while ($row = $stFinish->fetch(PDO::FETCH_ASSOC)) {
                $finishPartByScript[(int)$row['pid']] = (int)$row['id'];
            }
        }

        $sql = 'SELECT c.ID AS id, c.ID_ADMIN AS id_admin, c.ID_SCRIPT AS id_script,
                       c.SCORE AS score, FROM_UNIXTIME(c.DT_START, \'%Y-%m-%d\') AS day_key
                FROM base_records_collections c';
        $params = [];
        if ($positionFilter > 0 && Database::tableExists('base_users')) {
            $sql .= ' INNER JOIN base_users u ON u.id = c.ID_ADMIN AND u.ID_STATUS = ?';
            $params[] = $positionFilter;
        }
        $sql .= ' WHERE c.DT_START >= ? AND c.DT_START <= ? AND c.ID_ADMIN > 0 AND c.ID_SCRIPT > 0';
        $params[] = $fromTs;
        $params[] = $toTs;
        if ($adminFilter > 0) {
            $sql .= ' AND c.ID_ADMIN = ?';
            $params[] = $adminFilter;
        }
        if ($badgeFilter > 0
            && Database::tableExists('base_records_collections_datas')
            && Database::tableExists('base_records_instances')
            && Database::tableExists('base_records')
        ) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM base_records_collections_datas d
                INNER JOIN base_records_instances i ON i.ID = d.ID_INSTANCE
                INNER JOIN base_records r ON r.ID = i.ID_RECORD
                WHERE d.ID_COLLECTION = c.ID AND r.ID_DICTOPHONE = ?
            )';
            $params[] = $badgeFilter;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $collections = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($collections === []) {
            return $out;
        }

        /** @var array<int, array<int,true>> $finishPartsByCollection collectionId → finish part ids present */
        $finishPartsByCollection = [];
        if (Database::tableExists('base_records_collections_datas') && $finishPartByScript !== []) {
            // One range JOIN instead of chunked IN (thousands of collection ids) — same filters as above.
            $finishIn = implode(',', array_map('intval', array_unique(array_values($finishPartByScript))));
            $sqlFinish = 'SELECT d.ID_COLLECTION AS id, d.ID_PART AS id_part
                FROM base_records_collections_datas d
                INNER JOIN base_records_collections c ON c.ID = d.ID_COLLECTION';
            $finishParams = [];
            if ($positionFilter > 0 && Database::tableExists('base_users')) {
                $sqlFinish .= ' INNER JOIN base_users u ON u.id = c.ID_ADMIN AND u.ID_STATUS = ?';
                $finishParams[] = $positionFilter;
            }
            $sqlFinish .= ' WHERE c.DT_START >= ? AND c.DT_START <= ? AND c.ID_ADMIN > 0 AND c.ID_SCRIPT > 0
                AND d.ID_PART IN (' . $finishIn . ')';
            $finishParams[] = $fromTs;
            $finishParams[] = $toTs;
            if ($adminFilter > 0) {
                $sqlFinish .= ' AND c.ID_ADMIN = ?';
                $finishParams[] = $adminFilter;
            }
            if ($badgeFilter > 0
                && Database::tableExists('base_records_collections_datas')
                && Database::tableExists('base_records_instances')
                && Database::tableExists('base_records')
            ) {
                $sqlFinish .= ' AND EXISTS (
                    SELECT 1 FROM base_records_collections_datas d2
                    INNER JOIN base_records_instances i ON i.ID = d2.ID_INSTANCE
                    INNER JOIN base_records r ON r.ID = i.ID_RECORD
                    WHERE d2.ID_COLLECTION = c.ID AND r.ID_DICTOPHONE = ?
                )';
                $finishParams[] = $badgeFilter;
            }
            $qf = $pdo->prepare($sqlFinish);
            $qf->execute($finishParams);
            while ($fr = $qf->fetch(PDO::FETCH_ASSOC)) {
                $finishPartsByCollection[(int)$fr['id']][(int)$fr['id_part']] = true;
            }
        }

        /** @var array<int, true> $usedScripts */
        $usedScripts = [];
        foreach ($collections as $row) {
            $cid = (int)$row['id'];
            $aid = (int)$row['id_admin'];
            $sid = (int)$row['id_script'];
            $day = (string)$row['day_key'];
            $score = max(0, (int)$row['score']);
            if ($aid < 1 || $sid < 1 || $day === '') {
                continue;
            }
            $usedScripts[$sid] = true;
            if (!isset($out['by_admin_day'][$aid][$sid][$day])) {
                $out['by_admin_day'][$aid][$sid][$day] = $emptyCell;
            }
            $out['by_admin_day'][$aid][$sid][$day]['starts']++;
            $out['by_admin_day'][$aid][$sid][$day]['score'] += $score;
            if (!isset($out['by_admin'][$aid][$sid])) {
                $out['by_admin'][$aid][$sid] = $emptyCell;
            }
            $out['by_admin'][$aid][$sid]['starts']++;
            $out['by_admin'][$aid][$sid]['score'] += $score;

            $finishPart = $finishPartByScript[$sid] ?? 0;
            if ($finishPart > 0 && !empty($finishPartsByCollection[$cid][$finishPart])) {
                $out['by_admin_day'][$aid][$sid][$day]['finishes']++;
                $out['by_admin'][$aid][$sid]['finishes']++;
            }
        }

        foreach ($roots as $r) {
            $id = (int)$r['id'];
            if (!isset($usedScripts[$id])) {
                continue;
            }
            $name = trim((string)$r['name']);
            $out['scripts'][] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('#' . $id),
                'short' => self::scriptShortName($name !== '' ? $name : ('#' . $id)),
            ];
        }

        return $out;
    }

    /** Oper shortName(NAME): uppercase first letter of each word (e.g. «Контроль скрипт» → «КС»). */
    public static function scriptShortName(string $name): string
    {
        if (class_exists('UsersRepository', false)) {
            $s = UsersRepository::shortName($name, 1);
            if ($s !== '') {
                return $s;
            }
        }
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
        }
        return $out !== '' ? $out : $name;
    }

    /**
     * @param array{starts?:int,finishes?:int,score?:int} $cell
     */
    public static function formatScriptCell(array $cell, string $short): string
    {
        $starts = (int)($cell['starts'] ?? 0);
        if ($starts < 1) {
            return '';
        }
        $finishes = (int)($cell['finishes'] ?? 0);
        $score = (int)($cell['score'] ?? 0);
        $label = $short . $starts . ':' . $finishes;
        if ($score > 0) {
            $label .= '=' . $score;
        }
        return $label;
    }

    /**
     * Month summary cell: finishes/starts=pct%
     *
     * @param array{starts?:int,finishes?:int,score?:int} $cell
     */
    public static function formatScriptSummary(array $cell): string
    {
        $starts = (int)($cell['starts'] ?? 0);
        if ($starts < 1) {
            return '';
        }
        $finishes = (int)($cell['finishes'] ?? 0);
        $pct = (int)round($finishes * 100 / $starts);
        return $finishes . '/' . $starts . '=' . $pct . '%';
    }

    /**
     * Shift staff with AUDIO_ANALYTICS_ENABLED and no records that day (oper «нет зап.» / «ожидаем»).
     *
     * @param array{admin?:int,position?:int,positions?:list<int>} $filters
     * @param array<int, array<string, mixed>> $byAdminDay from forRange()['by_admin_day']
     * @return array<string, list<array{admin_id:int,badge:string,kind:string}>> date Y-m-d → missing lines
     */
    public static function missingAudioForRange(
        int $fromTs,
        int $toTs,
        array $byAdminDay,
        array $filters = []
    ): array {
        $out = [];
        if ($fromTs < 1 || $toTs < $fromTs || !Database::tableExists('base_workweeks')) {
            return $out;
        }

        $adminFilter = max(0, (int)($filters['admin'] ?? 0));
        $positionFilter = max(0, (int)($filters['position'] ?? 0));
        $positionsScope = [];
        if (isset($filters['positions']) && is_array($filters['positions'])) {
            foreach ($filters['positions'] as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $positionsScope[$pid] = true;
                }
            }
        }
        $pdo = Database::pdo();

        $sql = 'SELECT ID_OPERATOR AS id_operator, DT AS dt FROM base_workweeks WHERE DT >= ? AND DT <= ?';
        $params = [$fromTs, $toTs];
        if ($adminFilter > 0) {
            $sql .= ' AND ID_OPERATOR = ?';
            $params[] = $adminFilter;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        /** @var array<string, array<int,true>> $shifts */
        $shifts = [];
        $opIds = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $oid = (int)$row['id_operator'];
            $dt = (int)$row['dt'];
            if ($oid < 1 || $dt < 1) {
                continue;
            }
            $day = date('Y-m-d', $dt);
            $shifts[$day][$oid] = true;
            $opIds[$oid] = true;
        }
        if ($shifts === []) {
            return $out;
        }

        /** @var array<string, array<int,string>> $badges */
        $badges = [];
        if (Database::tableExists('base_badge_assignments')) {
            $stB = $pdo->prepare(
                'SELECT ID_OPERATOR AS id_operator, ID_BADGE AS id_badge, DT_START AS dt_start
                 FROM base_badge_assignments
                 WHERE DT_START >= ? AND DT_START <= ?
                   AND ID_BADGE IS NOT NULL AND ID_BADGE <> \'\' AND ID_BADGE <> \'0\'
                 ORDER BY DT_START DESC'
            );
            $stB->execute([$fromTs, $toTs]);
            while ($row = $stB->fetch(PDO::FETCH_ASSOC)) {
                $oid = (int)$row['id_operator'];
                $badge = trim((string)$row['id_badge']);
                $dt = (int)$row['dt_start'];
                if ($oid < 1 || $badge === '' || $badge === '0' || $dt < 1) {
                    continue;
                }
                $day = date('Y-m-d', $dt);
                if (!isset($badges[$day][$oid])) {
                    $badges[$day][$oid] = $badge;
                }
            }
        }

        $opList = array_map('intval', array_keys($opIds));
        /** @var array<int, array{analytics:bool,position_id:int}> $opMeta */
        $opMeta = [];
        foreach (array_chunk($opList, 200) as $chunk) {
            $in = implode(',', $chunk);
            $cols = 'id, ID_STATUS';
            if (Database::columnExists('base_users', 'AUDIO_ANALYTICS_ENABLED')) {
                $cols .= ', AUDIO_ANALYTICS_ENABLED';
            }
            $uq = $pdo->query("SELECT $cols FROM base_users WHERE id IN ($in)");
            if (!$uq) {
                continue;
            }
            while ($u = $uq->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)$u['id'];
                $opMeta[$id] = [
                    'analytics' => !empty($u['AUDIO_ANALYTICS_ENABLED']),
                    'position_id' => (int)($u['ID_STATUS'] ?? 0),
                ];
            }
        }

        $today = date('Y-m-d');
        foreach ($shifts as $day => $ops) {
            if ($day > $today) {
                continue;
            }
            $kind = $day === $today ? 'waiting' : 'missing';
            $opIdsDay = array_keys($ops);
            sort($opIdsDay, SORT_NUMERIC);
            foreach ($opIdsDay as $oid) {
                $oid = (int)$oid;
                if ($oid < 1) {
                    continue;
                }
                if (!empty($byAdminDay[$oid][$day]['count'])) {
                    continue;
                }
                $meta = $opMeta[$oid] ?? null;
                if (!$meta || !$meta['analytics']) {
                    continue;
                }
                if ($positionFilter > 0 && $meta['position_id'] !== $positionFilter) {
                    continue;
                }
                if ($positionsScope !== [] && !isset($positionsScope[$meta['position_id']])) {
                    continue;
                }
                $out[$day][] = [
                    'admin_id' => $oid,
                    'badge' => (string)($badges[$day][$oid] ?? ''),
                    'kind' => $kind,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}> $bucket
     */
    private static function bump(
        array &$bucket,
        string $dayKey,
        int $play,
        float $scoreW,
        int $scoreSec,
        bool $isErr,
        int $outside,
        int $strings = 0,
        int $stringsNok = 0
    ): void {
        if (!isset($bucket[$dayKey])) {
            $bucket[$dayKey] = [
                'count' => 0,
                'playtime' => 0,
                'score_w' => 0.0,
                'score_sec' => 0,
                'errors' => 0,
                'outside' => 0,
                'strings' => 0,
                'strings_nok' => 0,
            ];
        }
        $bucket[$dayKey]['count']++;
        $bucket[$dayKey]['playtime'] += $play;
        $bucket[$dayKey]['score_w'] += $scoreW;
        $bucket[$dayKey]['score_sec'] += $scoreSec;
        $bucket[$dayKey]['strings'] += $strings;
        $bucket[$dayKey]['strings_nok'] += $stringsNok;
        if ($isErr) {
            $bucket[$dayKey]['errors']++;
        }
        $bucket[$dayKey]['outside'] += $outside;
    }

    /**
     * @param array<int, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}> $bucket
     */
    /** @param int|string $adminId Staff id or unassigned badge code. */
    private static function bumpAdmin(
        array &$bucket,
        $adminId,
        int $play,
        float $scoreW,
        int $scoreSec,
        bool $isErr,
        int $outside,
        int $strings,
        int $stringsNok
    ): void {
        if (!isset($bucket[$adminId])) {
            $bucket[$adminId] = [
                'count' => 0,
                'playtime' => 0,
                'score_w' => 0.0,
                'score_sec' => 0,
                'errors' => 0,
                'outside' => 0,
                'strings' => 0,
                'strings_nok' => 0,
            ];
        }
        $bucket[$adminId]['count']++;
        $bucket[$adminId]['playtime'] += $play;
        $bucket[$adminId]['score_w'] += $scoreW;
        $bucket[$adminId]['score_sec'] += $scoreSec;
        $bucket[$adminId]['strings'] += $strings;
        $bucket[$adminId]['strings_nok'] += $stringsNok;
        if ($isErr) {
            $bucket[$adminId]['errors']++;
        }
        $bucket[$adminId]['outside'] += $outside;
    }

    /**
     * Precompute OffRecord/OffRate [start,end] for each calendar day in range.
     *
     * @param array<string,int> $bounds
     */
    public static function prewarmDayWindows(int $fromTs, int $toTs, array $bounds): void
    {
        if ($fromTs < 1 || $toTs < $fromTs) {
            return;
        }
        $cursor = (int)mktime(0, 0, 0, (int)date('n', $fromTs), (int)date('j', $fromTs), (int)date('Y', $fromTs));
        $last = (int)mktime(0, 0, 0, (int)date('n', $toTs), (int)date('j', $toTs), (int)date('Y', $toTs));
        if ($cursor < 1 || $last < 1) {
            return;
        }
        while ($cursor <= $last) {
            $dayKey = date('Y-m-d', $cursor);
            self::dayWindow($dayKey, $bounds, 'OffRecord');
            self::dayWindow($dayKey, $bounds, 'OffRate');
            $cursor += 86400;
        }
    }

    /**
     * @param array<string,int> $bounds
     * @param 'OffRate'|'OffRecord' $kind
     * @return array{0:int,1:int}|null null = invalid mktime (treat as always-in)
     */
    private static function dayWindow(string $dayKey, array $bounds, string $kind): ?array
    {
        $ck = $dayKey . ':' . $kind;
        if (array_key_exists($ck, self::$dayWindowCache)) {
            return self::$dayWindowCache[$ck];
        }
        $parts = explode('-', $dayKey);
        $y = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        $d = (int)($parts[2] ?? 0);
        if ($y < 1 || $m < 1 || $d < 1) {
            self::$dayWindowCache[$ck] = null;
            return null;
        }
        $start = mktime(
            (int)$bounds['hourStart' . $kind],
            (int)$bounds['minStart' . $kind],
            0,
            $m,
            $d,
            $y
        );
        $end = mktime(
            (int)$bounds['hourEnd' . $kind],
            (int)$bounds['minEnd' . $kind],
            0,
            $m,
            $d,
            $y
        );
        if ($start === false || $end === false) {
            self::$dayWindowCache[$ck] = null;
            return null;
        }
        $win = [(int)$start, (int)$end];
        self::$dayWindowCache[$ck] = $win;
        return $win;
    }

    /**
     * Record fully inside a day window (oper: start AND end within bounds).
     *
     * @param array<string,int> $bounds
     * @param 'OffRate'|'OffRecord' $kind
     */
    public static function isFullyInWindow(int $dt, int $playtime, array $bounds, string $kind): bool
    {
        $dayKey = date('Y-m-d', $dt);
        $win = self::dayWindow($dayKey, $bounds, $kind);
        if ($win === null) {
            return true;
        }
        $dtEnd = $dt + max(0, $playtime);
        return $dt >= $win[0] && $dtEnd <= $win[1];
    }

    /** @param array<string,int> $bounds */
    public static function isOutsideRecordWindow(int $dt, array $bounds, int $playtime = 0): bool
    {
        return !self::isFullyInWindow($dt, $playtime, $bounds, 'OffRecord');
    }

    /**
     * @param array{score_w?:float,score_sec?:int} $agg
     */
    public static function avgScore(array $agg): ?float
    {
        $sec = (int)($agg['score_sec'] ?? 0);
        if ($sec < 1) {
            return null;
        }
        return round((float)($agg['score_w'] ?? 0) / $sec, 2);
    }

    /** Dialog recognition % = 100 - (STRINGS_NOK / STRINGS * 100), like oper. */
    public static function keysPct(array $agg): ?int
    {
        $strings = (int)($agg['strings'] ?? 0);
        if ($strings < 1) {
            return null;
        }
        $nok = (int)($agg['strings_nok'] ?? 0);
        return (int)round(100 - ($nok * 100 / $strings));
    }

    public static function formatPlaytime(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h > 0) {
            return sprintf('%d:%02d', $h, $m);
        }
        return sprintf('0:%02d', $m);
    }

    public static function formatHours(int $seconds): string
    {
        return (string)round(max(0, $seconds) / 3600, 1);
    }

    /** Decode &#NNNN; / &#xHH; entities in TRACKING_CODE to unicode (e.g. &#128545; → 😠). */
    public static function decodeTrackingCode(string $code): string
    {
        if ($code === '' || strpos($code, '&') === false) {
            return $code;
        }
        return html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
