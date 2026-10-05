<?php
declare(strict_types=1);

/**
 * SQL day/admin rollups for the records calendar (not pageStats over a list page).
 */
final class CalendarAggregates
{
    /**
     * @param array{admin?:int,badge?:int,position?:int} $filters
     * @return array{
     *   by_day: array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>,
     *   by_admin_day: array<int, array<string, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>>,
     *   by_admin: array<int, array{count:int,playtime:int,score_w:float,score_sec:int,errors:int,outside:int,strings:int,strings_nok:int}>,
     *   admins: list<int>
     * }
     */
    public static function forRange(int $fromTs, int $toTs, array $filters = []): array
    {
        $empty = ['by_day' => [], 'by_admin_day' => [], 'by_admin' => [], 'admins' => []];
        if ($fromTs < 1 || $toTs < $fromTs || !Database::tableExists('base_records')) {
            return $empty;
        }

        $adminFilter = max(0, (int)($filters['admin'] ?? 0));
        $badgeFilter = max(0, (int)($filters['badge'] ?? 0));
        $positionFilter = max(0, (int)($filters['position'] ?? 0));

        $bounds = AudioDayBounds::read();
        $pdo = Database::pdo();
        $sql = 'SELECT r.ID_ADMIN AS id_admin, r.DT_RECORDED AS dt, r.PLAYTIME AS playtime,
                       r.SCORE AS score, r.ERROR_TRANS AS error_trans, r.QUALITY AS quality,
                       r.STRINGS AS strings, r.STRINGS_NOK AS strings_nok
                FROM base_records r';
        $params = [];
        if ($positionFilter > 0 && Database::tableExists('base_users')) {
            $sql .= ' INNER JOIN base_users u ON u.id = r.ID_ADMIN AND u.name_enabled = ?';
            $params[] = $positionFilter;
        }
        $sql .= ' WHERE r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
                  AND (r.DISABLED IS NULL OR r.DISABLED < 1)';
        $params[] = $fromTs;
        $params[] = $toTs;
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
        $admins = [];

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dt = (int)($row['dt'] ?? 0);
            if ($dt < 1) {
                continue;
            }
            $dayKey = date('Y-m-d', $dt);
            $adminId = (int)($row['id_admin'] ?? 0);
            $play = max(0, (int)($row['playtime'] ?? 0));
            $score = max(0.0, (float)($row['score'] ?? 0));
            $strings = max(0, (int)($row['strings'] ?? 0));
            $stringsNok = max(0, (int)($row['strings_nok'] ?? 0));
            $isErr = !empty($row['error_trans']) || ((int)($row['quality'] ?? 0) < 2 && $play < 1);
            $outside = self::isOutsideRecordWindow($dt, $bounds) ? $play : 0;

            self::bump($byDay, $dayKey, $play, $score, $isErr, $outside, $strings, $stringsNok);
            if ($adminId > 0) {
                if (!isset($byAdminDay[$adminId])) {
                    $byAdminDay[$adminId] = [];
                    $admins[$adminId] = true;
                }
                self::bump($byAdminDay[$adminId], $dayKey, $play, $score, $isErr, $outside, $strings, $stringsNok);
                self::bumpAdmin($byAdmin, $adminId, $play, $score, $isErr, $outside, $strings, $stringsNok);
            }
        }

        ksort($admins);
        return [
            'by_day' => $byDay,
            'by_admin_day' => $byAdminDay,
            'by_admin' => $byAdmin,
            'admins' => array_map('intval', array_keys($admins)),
        ];
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
        $sql = "SELECT i.ID_ADMIN AS id_admin, k.ID_DICTONARY AS id_dict,
                       FROM_UNIXTIME(i.DT_START, '%Y-%m-%d') AS day_key, COUNT(*) AS cnt
                FROM base_records_instances i
                INNER JOIN base_records_keys k ON k.ID = i.ID_KEY";
        $params = [];
        if ($badgeFilter > 0 && Database::tableExists('base_records')) {
            $sql .= ' INNER JOIN base_records r ON r.ID = i.ID_RECORD AND r.ID_DICTOPHONE = ?';
            $params[] = $badgeFilter;
        }
        if ($positionFilter > 0 && Database::tableExists('base_users')) {
            $sql .= ' INNER JOIN base_users u ON u.id = i.ID_ADMIN AND u.name_enabled = ?';
            $params[] = $positionFilter;
        }
        $sql .= " WHERE i.DT_START >= ? AND i.DT_START <= ?
                  AND k.ID_DICTONARY IN ($inD)
                  AND i.ID_ADMIN > 0";
        $params[] = $fromTs;
        $params[] = $toTs;
        if ($adminFilter > 0) {
            $sql .= ' AND i.ID_ADMIN = ?';
            $params[] = $adminFilter;
        }
        $sql .= ' GROUP BY i.ID_ADMIN, k.ID_DICTONARY, day_key';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int)$row['id_admin'];
            $did = (int)$row['id_dict'];
            $day = (string)$row['day_key'];
            $cnt = (int)$row['cnt'];
            if ($day === '') {
                continue;
            }
            if (!isset($out['by_admin_day'][$aid])) {
                $out['by_admin_day'][$aid] = [];
            }
            if (!isset($out['by_admin_day'][$aid][$did])) {
                $out['by_admin_day'][$aid][$did] = [];
            }
            $out['by_admin_day'][$aid][$did][$day] = $cnt;
            if (!isset($out['by_admin'][$aid][$did])) {
                $out['by_admin'][$aid][$did] = 0;
            }
            $out['by_admin'][$aid][$did] += $cnt;
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
        float $score,
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
        $bucket[$dayKey]['score_w'] += $score * $play;
        $bucket[$dayKey]['score_sec'] += $play;
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
    private static function bumpAdmin(
        array &$bucket,
        int $adminId,
        int $play,
        float $score,
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
        $bucket[$adminId]['score_w'] += $score * $play;
        $bucket[$adminId]['score_sec'] += $play;
        $bucket[$adminId]['strings'] += $strings;
        $bucket[$adminId]['strings_nok'] += $stringsNok;
        if ($isErr) {
            $bucket[$adminId]['errors']++;
        }
        $bucket[$adminId]['outside'] += $outside;
    }

    /** @param array<string,int> $bounds */
    public static function isOutsideRecordWindow(int $dt, array $bounds): bool
    {
        $y = (int)date('Y', $dt);
        $m = (int)date('n', $dt);
        $d = (int)date('j', $dt);
        $start = mktime(
            (int)$bounds['hourStartOffRecord'],
            (int)$bounds['minStartOffRecord'],
            0,
            $m,
            $d,
            $y
        );
        $end = mktime(
            (int)$bounds['hourEndOffRecord'],
            (int)$bounds['minEndOffRecord'],
            0,
            $m,
            $d,
            $y
        );
        if ($start === false || $end === false) {
            return false;
        }
        return $dt < $start || $dt > $end;
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
