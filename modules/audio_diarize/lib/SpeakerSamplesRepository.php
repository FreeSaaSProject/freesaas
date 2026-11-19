<?php
declare(strict_types=1);

/**
 * Manual / diarize voice enrollment clips (base_records_speaker_samples).
 */
final class SpeakerSamplesRepository
{
    /** Soft target — more clean clips usually help; not a hard cap. */
    public const RECOMMENDED_MANUAL = 8;
    /** Safety ceiling against accidental mass inserts. */
    public const MAX_MANUAL = 40;
    public const MIN_CLIP_SEC = 3.0;
    public const MAX_CLIP_SEC = 120.0;
    /** Treat as duplicate if overlap with an existing clip is at least this many seconds. */
    public const DUPLICATE_OVERLAP_SEC = 0.5;
    /** Catalog: staff with records in this window, or anyone who already has samples. */
    public const CATALOG_RECENT_DAYS = 40;

    public static function available(): bool
    {
        return Database::tableExists('base_records_speaker_samples');
    }

    /**
     * Staff for voice-profiles catalog: records in the last N days, or anyone with samples.
     * Two cheap DISTINCT queries (no OR EXISTS full scan of base_records) + batched counts/profiles.
     *
     * @return list<array{
     *   id_admin:int,admin_name:string,manual_count:int,diarize_count:int,
     *   pending_embed:int,talk_sec_manual:float,has_profile:bool,
     *   profile_dim:int,profile_samples:int,profile_talk:float,profile_updated:int
     * }>
     */
    public static function catalogStaffWithAudio(int $recentDays = self::CATALOG_RECENT_DAYS): array
    {
        if (!Database::tableExists('base_records')) {
            return [];
        }
        $recentDays = max(1, min(3650, $recentDays));
        $since = time() - ($recentDays * 86400);
        $pdo = Database::pdo();
        $ids = [];

        // Indexed range on DT_RECORDED — avoid OR EXISTS that forces ALL scan of ~all partitions.
        $st = $pdo->prepare(
            'SELECT DISTINCT r.ID_ADMIN AS id_admin
             FROM base_records r
             WHERE r.ID_ADMIN > 0
               AND (r.DISABLED IS NULL OR r.DISABLED < 1)
               AND r.DT_RECORDED >= ?'
        );
        $st->execute([$since]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['id_admin'];
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        if (self::available()) {
            $st2 = $pdo->query(
                'SELECT DISTINCT ID_ADMIN AS id_admin
                 FROM base_records_speaker_samples
                 WHERE ID_ADMIN > 0'
            );
            while ($row = $st2->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)$row['id_admin'];
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $adminIds = array_keys($ids);
        $names = self::adminNamesMap($adminIds);
        $countsBy = self::countsMapForAdmins($adminIds);
        $profilesBy = [];
        if (class_exists('SpeakerProfilesRepository', false) && SpeakerProfilesRepository::available()) {
            $profilesBy = self::profilesMetaMap($adminIds);
        }

        $out = [];
        foreach ($adminIds as $adminId) {
            $counts = $countsBy[$adminId] ?? [
                'manual' => 0,
                'diarize' => 0,
                'pending_manual' => 0,
                'talk_sec_manual' => 0.0,
            ];
            $profile = $profilesBy[$adminId] ?? null;
            $out[] = [
                'id_admin' => $adminId,
                'admin_name' => $names[$adminId] ?? ('#' . $adminId),
                'manual_count' => $counts['manual'],
                'diarize_count' => $counts['diarize'],
                'pending_embed' => $counts['pending_manual'],
                'talk_sec_manual' => $counts['talk_sec_manual'],
                'has_profile' => $profile !== null && (int)($profile['embedding_dim'] ?? 0) > 0,
                'profile_dim' => (int)($profile['embedding_dim'] ?? 0),
                'profile_samples' => (int)($profile['sample_count'] ?? 0),
                'profile_talk' => (float)($profile['talk_sec_total'] ?? 0),
                'profile_updated' => (int)($profile['dt_update'] ?? 0),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $c = strcmp((string)$a['admin_name'], (string)$b['admin_name']);
            return $c !== 0 ? $c : ((int)$a['id_admin'] <=> (int)$b['id_admin']);
        });
        return $out;
    }

    /**
     * @param list<int> $adminIds
     * @return array<int,string>
     */
    private static function adminNamesMap(array $adminIds): array
    {
        $out = [];
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        if ($adminIds === [] || !Database::tableExists('base_users')) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID, COALESCE(NAME, '') AS name FROM base_users WHERE ID IN ($ph)"
        );
        $st->execute($adminIds);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$row['ID']] = (string)$row['name'];
        }
        return $out;
    }

    /**
     * @param list<int> $adminIds
     * @return array<int,array{manual:int,diarize:int,pending_manual:int,talk_sec_manual:float}>
     */
    private static function countsMapForAdmins(array $adminIds): array
    {
        $out = [];
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        if ($adminIds === [] || !self::available()) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID_ADMIN, SOURCE,
                    COUNT(*) AS n,
                    SUM(CASE WHEN DT_EMBEDDED < 1 THEN 1 ELSE 0 END) AS pending,
                    SUM(GREATEST(0, T1 - T0)) AS talk
             FROM base_records_speaker_samples
             WHERE ID_ADMIN IN ($ph)
             GROUP BY ID_ADMIN, SOURCE"
        );
        $st->execute($adminIds);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['ID_ADMIN'];
            if (!isset($out[$id])) {
                $out[$id] = [
                    'manual' => 0,
                    'diarize' => 0,
                    'pending_manual' => 0,
                    'talk_sec_manual' => 0.0,
                ];
            }
            $src = (string)$row['SOURCE'];
            if ($src === 'manual') {
                $out[$id]['manual'] = (int)$row['n'];
                $out[$id]['pending_manual'] = (int)$row['pending'];
                $out[$id]['talk_sec_manual'] = (float)$row['talk'];
            } elseif ($src === 'diarize') {
                $out[$id]['diarize'] = (int)$row['n'];
            }
        }
        return $out;
    }

    /**
     * Profile meta only (no EMBEDDING blob).
     *
     * @param list<int> $adminIds
     * @return array<int,array{embedding_dim:int,sample_count:int,talk_sec_total:float,dt_update:int}>
     */
    private static function profilesMetaMap(array $adminIds): array
    {
        $out = [];
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        if ($adminIds === [] || !Database::tableExists('base_records_speaker_profiles')) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID_ADMIN, EMBEDDING_DIM, SAMPLE_COUNT, TALK_SEC_TOTAL, DT_UPDATE
             FROM base_records_speaker_profiles
             WHERE ID_ADMIN IN ($ph)"
        );
        $st->execute($adminIds);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$row['ID_ADMIN']] = [
                'embedding_dim' => (int)$row['EMBEDDING_DIM'],
                'sample_count' => (int)$row['SAMPLE_COUNT'],
                'talk_sec_total' => (float)$row['TALK_SEC_TOTAL'],
                'dt_update' => (int)$row['DT_UPDATE'],
            ];
        }
        return $out;
    }

    /**
     * Admins with manual samples waiting for WeSpeaker embed (for LongJob / bulk).
     *
     * @return list<int>
     */
    public static function adminIdsWithPendingEmbed(): array
    {
        if (!self::available()) {
            return [];
        }
        $st = Database::pdo()->query(
            'SELECT DISTINCT ID_ADMIN
             FROM base_records_speaker_samples
             WHERE SOURCE = \'manual\' AND DT_EMBEDDED < 1 AND EMBEDDING_DIM < 1 AND ID_ADMIN > 0
             ORDER BY ID_ADMIN ASC'
        );
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = (int)$row['ID_ADMIN'];
        }
        return $out;
    }

    /** @return array{manual:int,diarize:int,pending_manual:int,talk_sec_manual:float} */
    public static function countsForAdmin(int $adminId): array
    {
        $empty = ['manual' => 0, 'diarize' => 0, 'pending_manual' => 0, 'talk_sec_manual' => 0.0];
        if ($adminId < 1 || !self::available()) {
            return $empty;
        }
        $st = Database::pdo()->prepare(
            'SELECT SOURCE,
                    COUNT(*) AS n,
                    SUM(CASE WHEN DT_EMBEDDED < 1 THEN 1 ELSE 0 END) AS pending,
                    SUM(GREATEST(0, T1 - T0)) AS talk
             FROM base_records_speaker_samples
             WHERE ID_ADMIN = ?
             GROUP BY SOURCE'
        );
        $st->execute([$adminId]);
        $out = $empty;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $src = (string)$row['SOURCE'];
            $n = (int)$row['n'];
            if ($src === 'manual') {
                $out['manual'] = $n;
                $out['pending_manual'] = (int)$row['pending'];
                $out['talk_sec_manual'] = (float)$row['talk'];
            } elseif ($src === 'diarize') {
                $out['diarize'] = $n;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForAdmin(int $adminId, string $source = 'manual', int $limit = 200): array
    {
        if ($adminId < 1 || !self::available()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $st = Database::pdo()->prepare(
            'SELECT s.ID AS id, s.ID_ADMIN AS id_admin, s.ID_RECORD AS id_record,
                    s.T0 AS t0, s.T1 AS t1, s.SOURCE AS source,
                    s.EMBEDDING_DIM AS embedding_dim, s.DT_EMBEDDED AS dt_embedded,
                    s.DT_CREATE AS dt_create,
                    r.FILENAME AS filename, r.DT_RECORDED AS dt_recorded
             FROM base_records_speaker_samples s
             LEFT JOIN base_records r ON r.ID = s.ID_RECORD
             WHERE s.ID_ADMIN = ? AND s.SOURCE = ?
             ORDER BY s.DT_CREATE DESC, s.ID DESC
             LIMIT ' . $limit
        );
        $st->execute([$adminId, $source]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int)$row['id'],
                'id_admin' => (int)$row['id_admin'],
                'id_record' => (int)$row['id_record'],
                't0' => (float)$row['t0'],
                't1' => (float)$row['t1'],
                'source' => (string)$row['source'],
                'embedding_dim' => (int)$row['embedding_dim'],
                'dt_embedded' => (int)$row['dt_embedded'],
                'dt_create' => (int)$row['dt_create'],
                'filename' => (string)($row['filename'] ?? ''),
                'dt_recorded' => (int)($row['dt_recorded'] ?? 0),
                'duration_sec' => max(0.0, (float)$row['t1'] - (float)$row['t0']),
            ];
        }
        return $out;
    }

    /**
     * Validate a manual clip without inserting.
     *
     * @return array{ok:bool,id:int,error:string,manual_count:int,remaining:int,duration:float,overlap_id:int}
     */
    public static function validateManual(int $adminId, int $recordId, float $t0, float $t1): array
    {
        $counts = self::countsForAdmin($adminId);
        $remaining = max(0, self::RECOMMENDED_MANUAL - $counts['manual']);
        $base = [
            'ok' => false,
            'id' => 0,
            'error' => '',
            'manual_count' => $counts['manual'],
            'remaining' => $remaining,
            'duration' => max(0.0, $t1 - $t0),
            'overlap_id' => 0,
        ];
        if (!self::available()) {
            $base['error'] = 'samples_unavailable';
            $base['remaining'] = self::RECOMMENDED_MANUAL;
            $base['manual_count'] = 0;
            return $base;
        }
        if ($adminId < 1 || $recordId < 1) {
            $base['error'] = 'bad_args';
            return $base;
        }
        if ($t1 <= $t0) {
            $base['error'] = 'bad_range';
            return $base;
        }
        $dur = $t1 - $t0;
        $base['duration'] = $dur;
        if ($dur < self::MIN_CLIP_SEC) {
            $base['error'] = 'too_short';
            return $base;
        }
        if ($dur > self::MAX_CLIP_SEC) {
            $base['error'] = 'too_long';
            return $base;
        }
        if ($counts['manual'] >= self::MAX_MANUAL) {
            $base['error'] = 'too_many';
            return $base;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records WHERE ID = ? LIMIT 1'
        );
        $st->execute([$recordId]);
        if (!(int)($st->fetchColumn() ?: 0)) {
            $base['error'] = 'bad_args';
            return $base;
        }
        // Same gates as staffOptionsForRecord: shift day + audio analytics.
        $allowed = false;
        foreach (self::staffOptionsForRecord($recordId) as $opt) {
            if ((int)($opt['id'] ?? 0) === $adminId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $base['error'] = 'bad_admin';
            return $base;
        }
        $overlapId = self::findOverlappingSampleId($adminId, $recordId, $t0, $t1);
        if ($overlapId > 0) {
            $base['error'] = 'duplicate';
            $base['overlap_id'] = $overlapId;
            return $base;
        }
        $base['ok'] = true;
        return $base;
    }

    /** Enabled staff user (not a client-only account). */
    public static function isEligibleStaffAdmin(int $adminId): bool
    {
        if ($adminId < 1 || !Database::tableExists('base_users')) {
            return false;
        }
        if (class_exists('UsersRepository', false)) {
            foreach (UsersRepository::listEnabledStaff() as $u) {
                if ((int)($u['id'] ?? 0) === $adminId) {
                    return true;
                }
            }
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_users WHERE ID = ? AND (DISABLED IS NULL OR DISABLED < 1) LIMIT 1'
        );
        $st->execute([$adminId]);
        return (int)($st->fetchColumn() ?: 0) > 0;
    }

    /**
     * Staff picker for voice sample — only two gates:
     * 1) on shift the record’s calendar day;
     * 2) AUDIO_ANALYTICS_ENABLED on the user.
     * No assist/hidden/group filters.
     *
     * @return list<array{id:int,name:string,default:bool}>
     */
    public static function staffOptionsForRecord(int $recordId): array
    {
        if ($recordId < 1 || !Database::tableExists('base_records')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_ADMIN, DT_RECORDED FROM base_records WHERE ID = ? LIMIT 1'
        );
        $st->execute([$recordId]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            return [];
        }
        $defaultAdmin = (int)($rec['ID_ADMIN'] ?? 0);
        if (
            !ModuleManager::isEnabled('workweeks')
            || !class_exists('WorkweeksRepository', false)
        ) {
            return [];
        }
        $recorded = (int)($rec['DT_RECORDED'] ?? 0);
        if ($recorded < 1) {
            $recorded = time();
        }
        $dayStart = (int)mktime(
            0,
            0,
            0,
            (int)date('n', $recorded),
            (int)date('j', $recorded),
            (int)date('Y', $recorded)
        );
        $ids = [];
        foreach (WorkweeksRepository::listForDay($dayStart) as $shift) {
            $oid = (int)($shift['id_operator'] ?? $shift['ID_OPERATOR'] ?? 0);
            if ($oid > 0) {
                $ids[$oid] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $audioOn = self::audioAnalyticsMap(array_keys($ids));
        $out = [];
        foreach (array_keys($ids) as $uid) {
            if (empty($audioOn[$uid])) {
                continue;
            }
            $name = class_exists('UsersRepository', false)
                ? UsersRepository::nameById($uid)
                : ('#' . $uid);
            $name = trim((string)$name);
            if ($name === '') {
                $name = '#' . $uid;
            }
            $acr = class_exists('UsersRepository', false)
                ? UsersRepository::shortName($name)
                : '';
            $out[] = [
                'id' => $uid,
                'name' => $name,
                'acronym' => $acr,
                'default' => $uid === $defaultAdmin,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['default'] !== $b['default']) {
                return $a['default'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * @param list<int> $adminIds
     * @return array<int,bool>
     */
    private static function audioAnalyticsMap(array $adminIds): array
    {
        $out = [];
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        if ($adminIds === []) {
            return $out;
        }
        if (
            !Database::tableExists('base_users')
            || !Database::columnExists('base_users', 'AUDIO_ANALYTICS_ENABLED')
        ) {
            // Column absent: do not invent a gate — treat as off (picker stays empty).
            return $out;
        }
        $ph = implode(',', array_fill(0, count($adminIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID, AUDIO_ANALYTICS_ENABLED AS audio FROM base_users WHERE ID IN ($ph)"
        );
        $st->execute($adminIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['ID']] = !empty($row['audio']);
        }
        return $out;
    }

    /** Existing manual sample on the same record overlapping [t0,t1] by ≥ DUPLICATE_OVERLAP_SEC. */
    public static function findOverlappingSampleId(int $adminId, int $recordId, float $t0, float $t1): int
    {
        if ($adminId < 1 || $recordId < 1 || $t1 <= $t0 || !self::available()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, T0, T1 FROM base_records_speaker_samples
             WHERE ID_ADMIN = ? AND ID_RECORD = ? AND SOURCE = \'manual\'
               AND T0 < ? AND T1 > ?
             ORDER BY ID ASC'
        );
        // Overlap condition: existing.T0 < t1 AND existing.T1 > t0
        $st->execute([$adminId, $recordId, $t1, $t0]);
        $minOv = self::DUPLICATE_OVERLAP_SEC;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $a = max($t0, (float)$row['T0']);
            $b = min($t1, (float)$row['T1']);
            if (($b - $a) >= $minOv) {
                return (int)$row['ID'];
            }
        }
        return 0;
    }

    /**
     * @return array{ok:bool,id:int,error:string,manual_count:int,remaining:int,duration?:float,overlap_id?:int}
     */
    public static function addManual(int $adminId, int $recordId, float $t0, float $t1): array
    {
        $check = self::validateManual($adminId, $recordId, $t0, $t1);
        if (!$check['ok']) {
            return $check;
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO base_records_speaker_samples
             (ID_ADMIN, ID_RECORD, T0, T1, SOURCE, EMBEDDING_DIM, DT_EMBEDDED, DT_CREATE)
             VALUES (?, ?, ?, ?, \'manual\', 0, 0, ?)'
        )->execute([$adminId, $recordId, $t0, $t1, $now]);
        $id = (int)Database::pdo()->lastInsertId();
        $counts = self::countsForAdmin($adminId);
        return [
            'ok' => true,
            'id' => $id,
            'error' => '',
            'manual_count' => $counts['manual'],
            'remaining' => max(0, self::RECOMMENDED_MANUAL - $counts['manual']),
            'duration' => $t1 - $t0,
            'overlap_id' => 0,
        ];
    }

    /** Merge nearby [t0,t1] into enrollment-sized clips; split overlong. */
    public const LOCAL_MERGE_GAP_SEC = 3.5;

    /**
     * @param list<array{0:float,1:float}|array{t0:float,t1:float}> $ranges
     * @return list<array{0:float,1:float}>
     */
    private static function normalizePairs(array $ranges): array
    {
        $pairs = [];
        foreach ($ranges as $r) {
            if (!is_array($r)) {
                continue;
            }
            $t0 = (float)($r['t0'] ?? $r[0] ?? 0);
            $t1 = (float)($r['t1'] ?? $r[1] ?? 0);
            if ($t1 > $t0) {
                $pairs[] = [$t0, $t1];
            }
        }
        usort($pairs, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        return $pairs;
    }

    /**
     * Subtract blocker intervals from keep intervals (foreign speech punch-out).
     *
     * @param list<array{0:float,1:float}|array{t0:float,t1:float}> $keep
     * @param list<array{0:float,1:float}|array{t0:float,t1:float}> $blockers
     * @return list<array{t0:float,t1:float}>
     */
    public static function punchOutRanges(array $keep, array $blockers): array
    {
        $pairs = self::normalizePairs($keep);
        $blocks = self::normalizePairs($blockers);
        if ($pairs === []) {
            return [];
        }
        if ($blocks === []) {
            $out = [];
            foreach ($pairs as $p) {
                $out[] = ['t0' => $p[0], 't1' => $p[1]];
            }
            return $out;
        }
        $out = [];
        foreach ($pairs as $p) {
            $pieces = [[$p[0], $p[1]]];
            foreach ($blocks as $b) {
                $next = [];
                foreach ($pieces as $piece) {
                    [$a, $c] = $piece;
                    [$x, $y] = $b;
                    if ($y <= $a || $x >= $c) {
                        $next[] = [$a, $c];
                        continue;
                    }
                    if ($x > $a) {
                        $next[] = [$a, min($x, $c)];
                    }
                    if ($y < $c) {
                        $next[] = [max($y, $a), $c];
                    }
                }
                $pieces = $next;
            }
            foreach ($pieces as $piece) {
                if ($piece[1] > $piece[0]) {
                    $out[] = ['t0' => $piece[0], 't1' => $piece[1]];
                }
            }
        }
        return $out;
    }

    /**
     * @param list<array{0:float,1:float}|array{t0:float,t1:float}> $ranges
     * @return list<array{t0:float,t1:float}>
     */
    public static function mergeClipRanges(array $ranges, float $gapSec = self::LOCAL_MERGE_GAP_SEC): array
    {
        $pairs = self::normalizePairs($ranges);
        if ($pairs === []) {
            return [];
        }
        $merged = [];
        $cur0 = $pairs[0][0];
        $cur1 = $pairs[0][1];
        for ($i = 1, $n = count($pairs); $i < $n; $i++) {
            [$a, $b] = $pairs[$i];
            if ($a <= $cur1 + $gapSec) {
                $cur1 = max($cur1, $b);
                continue;
            }
            $merged[] = ['t0' => $cur0, 't1' => $cur1];
            $cur0 = $a;
            $cur1 = $b;
        }
        $merged[] = ['t0' => $cur0, 't1' => $cur1];

        $out = [];
        foreach ($merged as $clip) {
            $t0 = $clip['t0'];
            $t1 = $clip['t1'];
            $dur = $t1 - $t0;
            if ($dur < self::MIN_CLIP_SEC) {
                continue;
            }
            if ($dur <= self::MAX_CLIP_SEC) {
                $out[] = ['t0' => $t0, 't1' => $t1];
                continue;
            }
            $pos = $t0;
            while ($pos + self::MIN_CLIP_SEC <= $t1) {
                $end = min($t1, $pos + self::MAX_CLIP_SEC);
                if ($end - $pos >= self::MIN_CLIP_SEC) {
                    $out[] = ['t0' => $pos, 't1' => $end];
                }
                $pos = $end;
            }
        }
        return $out;
    }

    /**
     * Nemotron segment windows for one local speaker on a record.
     *
     * @return list<array{t0:float,t1:float}>
     */
    public static function segmentRangesForLocal(int $recordId, string $localId): array
    {
        $localId = substr(trim($localId), 0, 16);
        if ($recordId < 1 || $localId === '' || !Database::tableExists('base_records_speaker_segments')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT T0, T1 FROM base_records_speaker_segments
             WHERE ID_RECORD = ? AND LOCAL_ID = ? ORDER BY T0 ASC'
        );
        $st->execute([$recordId, $localId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $t0 = (float)$row['T0'];
            $t1 = (float)$row['T1'];
            if ($t1 > $t0) {
                $out[] = ['t0' => $t0, 't1' => $t1];
            }
        }
        return $out;
    }

    /**
     * All segment windows on a record except one local speaker.
     *
     * @return list<array{t0:float,t1:float}>
     */
    public static function segmentRangesExceptLocal(int $recordId, string $localId): array
    {
        $localId = substr(trim($localId), 0, 16);
        if ($recordId < 1 || !Database::tableExists('base_records_speaker_segments')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT T0, T1 FROM base_records_speaker_segments
             WHERE ID_RECORD = ? AND LOCAL_ID <> ? ORDER BY T0 ASC'
        );
        $st->execute([$recordId, $localId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $t0 = (float)$row['T0'];
            $t1 = (float)$row['T1'];
            if ($t1 > $t0) {
                $out[] = ['t0' => $t0, 't1' => $t1];
            }
        }
        return $out;
    }

    /**
     * Enrollment clips: merge own segments, then punch out foreign speech (gap-merge
     * alone can re-include A127 inside an S2 window) and optional operator excludes,
     * then re-apply min/max sizing.
     *
     * @param list<array{t0:float,t1:float}|array{0:float,1:float}> $excludeRanges
     * @return list<array{t0:float,t1:float}>
     */
    public static function enrollmentClipsForLocal(
        int $recordId,
        string $localId,
        array $excludeRanges = []
    ): array {
        $own = self::segmentRangesForLocal($recordId, $localId);
        if ($own === []) {
            return [];
        }
        $blockers = array_merge(
            self::segmentRangesExceptLocal($recordId, $localId),
            $excludeRanges
        );
        $rough = self::mergeClipRanges($own, self::LOCAL_MERGE_GAP_SEC);
        $clean = self::punchOutRanges($rough, $blockers);
        // Tiny gap only for float abut; do not bridge punched holes again.
        return self::mergeClipRanges($clean, 0.05);
    }

    /**
     * Create manual samples from a local cluster’s segments (merge → clips → insert).
     *
     * @return array{
     *   ok:bool,error:string,added:int,skipped_dup:int,skipped_short:int,
     *   ids:list<int>,clips:list<array{t0:float,t1:float}>,
     *   manual_count:int,remaining:int,talk_sec:float
     * }
     */
    /**
     * @param list<array{t0:float,t1:float}|array{0:float,1:float}> $excludeRanges
     */
    public static function addManualFromLocalSpeaker(
        int $adminId,
        int $recordId,
        string $localId,
        array $excludeRanges = []
    ): array {
        $counts = self::countsForAdmin($adminId);
        $base = [
            'ok' => false,
            'error' => '',
            'added' => 0,
            'skipped_dup' => 0,
            'skipped_short' => 0,
            'ids' => [],
            'clips' => [],
            'manual_count' => $counts['manual'],
            'remaining' => max(0, self::RECOMMENDED_MANUAL - $counts['manual']),
            'talk_sec' => 0.0,
        ];
        if (!self::available()) {
            $base['error'] = 'samples_unavailable';
            return $base;
        }
        if ($adminId < 1 || $recordId < 1 || trim($localId) === '') {
            $base['error'] = 'bad_args';
            return $base;
        }
        $allowed = false;
        foreach (self::staffOptionsForRecord($recordId) as $opt) {
            if ((int)($opt['id'] ?? 0) === $adminId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $base['error'] = 'bad_admin';
            return $base;
        }
        if ($counts['manual'] >= self::MAX_MANUAL) {
            $base['error'] = 'too_many';
            return $base;
        }

        $raw = self::segmentRangesForLocal($recordId, $localId);
        $rawTalk = 0.0;
        foreach ($raw as $r) {
            $rawTalk += max(0.0, $r['t1'] - $r['t0']);
        }
        $base['talk_sec'] = $rawTalk;
        // Punch out other speakers + operator-excluded cues before insert.
        $clips = self::enrollmentClipsForLocal($recordId, $localId, $excludeRanges);
        $base['clips'] = $clips;
        if ($clips === []) {
            $base['error'] = 'no_clips';
            $base['skipped_short'] = count($raw);
            return $base;
        }

        $added = 0;
        $dup = 0;
        $ids = [];
        foreach ($clips as $clip) {
            if ($counts['manual'] + $added >= self::MAX_MANUAL) {
                break;
            }
            $res = self::addManual($adminId, $recordId, $clip['t0'], $clip['t1']);
            if ($res['ok']) {
                $added++;
                $ids[] = (int)$res['id'];
                continue;
            }
            if (($res['error'] ?? '') === 'duplicate') {
                $dup++;
                continue;
            }
            // First hard failure (e.g. too_many mid-loop) — stop.
            if ($added < 1 && $dup < 1) {
                $base['error'] = (string)$res['error'];
                $base['manual_count'] = (int)$res['manual_count'];
                $base['remaining'] = (int)$res['remaining'];
                return $base;
            }
            break;
        }

        $counts = self::countsForAdmin($adminId);
        $base['ok'] = $added > 0 || $dup > 0;
        $base['added'] = $added;
        $base['skipped_dup'] = $dup;
        $base['ids'] = $ids;
        $base['manual_count'] = $counts['manual'];
        $base['remaining'] = max(0, self::RECOMMENDED_MANUAL - $counts['manual']);
        if (!$base['ok']) {
            $base['error'] = 'no_clips';
        }
        return $base;
    }

    public static function delete(int $sampleId, int $adminId = 0): bool
    {
        if ($sampleId < 1 || !self::available()) {
            return false;
        }
        if ($adminId > 0) {
            $st = Database::pdo()->prepare(
                'DELETE FROM base_records_speaker_samples WHERE ID = ? AND ID_ADMIN = ?'
            );
            return $st->execute([$sampleId, $adminId]);
        }
        $st = Database::pdo()->prepare('DELETE FROM base_records_speaker_samples WHERE ID = ?');
        return $st->execute([$sampleId]);
    }

    /**
     * Pending manual samples for embed worker (optional filter by admin).
     *
     * @return array{id:int,id_admin:int,id_record:int,t0:float,t1:float,filename:string}|null
     */
    public static function claimPendingEmbed(int $adminId = 0): ?array
    {
        if (!self::available()) {
            return null;
        }
        $pdo = Database::pdo();
        $sql = 'SELECT s.ID AS id, s.ID_ADMIN AS id_admin, s.ID_RECORD AS id_record,
                       s.T0 AS t0, s.T1 AS t1, COALESCE(r.FILENAME, \'\') AS filename
                FROM base_records_speaker_samples s
                LEFT JOIN base_records r ON r.ID = s.ID_RECORD
                WHERE s.SOURCE = \'manual\' AND s.DT_EMBEDDED < 1 AND s.EMBEDDING_DIM < 1';
        $params = [];
        if ($adminId > 0) {
            $sql .= ' AND s.ID_ADMIN = ?';
            $params[] = $adminId;
        }
        $sql .= ' ORDER BY s.ID ASC LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'id_admin' => (int)$row['id_admin'],
            'id_record' => (int)$row['id_record'],
            't0' => (float)$row['t0'],
            't1' => (float)$row['t1'],
            'filename' => (string)$row['filename'],
        ];
    }

    /**
     * @param list<float> $embedding
     */
    public static function saveEmbedding(int $sampleId, array $embedding): bool
    {
        if ($sampleId < 1 || $embedding === [] || !self::available()) {
            return false;
        }
        require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
        $blob = SpeakerEmbedding::pack(SpeakerEmbedding::l2norm($embedding));
        $st = Database::pdo()->prepare(
            'UPDATE base_records_speaker_samples
             SET EMBEDDING = ?, EMBEDDING_DIM = ?, DT_EMBEDDED = ?
             WHERE ID = ?'
        );
        return $st->execute([$blob, count($embedding), time(), $sampleId]);
    }

    /**
     * Embedded manual samples for building a profile centroid.
     *
     * @return list<array{id:int,embedding:list<float>,talk_sec:float}>
     */
    public static function embeddedManualForAdmin(int $adminId): array
    {
        if ($adminId < 1 || !self::available()) {
            return [];
        }
        require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
        $st = Database::pdo()->prepare(
            'SELECT ID, EMBEDDING, EMBEDDING_DIM, T0, T1
             FROM base_records_speaker_samples
             WHERE ID_ADMIN = ? AND SOURCE = \'manual\' AND EMBEDDING_DIM > 0 AND DT_EMBEDDED > 0'
        );
        $st->execute([$adminId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $emb = SpeakerEmbedding::unpack(
                isset($row['EMBEDDING']) ? (string)$row['EMBEDDING'] : null,
                (int)$row['EMBEDDING_DIM']
            );
            if (count($emb) < 32) {
                continue;
            }
            $out[] = [
                'id' => (int)$row['ID'],
                'embedding' => $emb,
                'talk_sec' => max(0.0, (float)$row['T1'] - (float)$row['T0']),
            ];
        }
        return $out;
    }
}
