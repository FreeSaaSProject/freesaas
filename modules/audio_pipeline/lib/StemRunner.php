<?php
declare(strict_types=1);

require_once __DIR__ . '/StemCache.php';
require_once __DIR__ . '/StemLemmatizer.php';
require_once __DIR__ . '/CollectRunner.php';

/**
 * Stem/morph batch runner (port of oper recordParser3, simplified).
 */
final class StemRunner
{
    private const KPI_GAP_SEC = 7;
    private const LOOKBACK_DAYS = 10;
    private const MAX_BATCH = 700;

    /**
     * Recalculate SCORE for already-parsed records (no stem). Uses KEY_TYPES already on rows.
     *
     * @param array{day?:string,admin?:int,record?:int,limit?:int} $opts
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function scoreOnly(array $opts = []): array
    {
        if (!Database::tableExists('base_records')) {
            return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'message' => 'tables_missing'];
        }
        JobRunner::applyTimeLimit(isset($opts['time_budget_sec']) ? (int)$opts['time_budget_sec'] : 120);
        $deadline = JobRunner::deadline(isset($opts['time_budget_sec']) ? (int)$opts['time_budget_sec'] : 120);

        $refs = self::loadReferenceData();
        $adminFilter = max(0, (int)($opts['admin'] ?? 0));
        $recordFilter = max(0, (int)($opts['record'] ?? 0));
        $dayRange = self::parseDayRange(isset($opts['day']) ? (string)$opts['day'] : '');
        $limit = max(1, min(5000, (int)($opts['limit'] ?? 2000)));

        $where = ['DT_TRANSCRIBED > 1', 'DT_PARSED > 0'];
        $params = [];
        if ($recordFilter > 0) {
            $where = ['ID = ?'];
            $params = [$recordFilter];
        } else {
            if ($dayRange !== null) {
                $where[] = 'DT_RECORDED > ? AND DT_RECORDED < ?';
                $params[] = $dayRange['start'];
                $params[] = $dayRange['end'];
            }
            if ($adminFilter > 0) {
                $where[] = 'ID_ADMIN = ?';
                $params[] = $adminFilter;
            }
        }
        $sql = 'SELECT ID FROM base_records WHERE ' . implode(' AND ', $where)
            . ' ORDER BY DT_RECORDED ASC LIMIT ' . (int)$limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'nothing_to_score'];
        }
        if (JobRunner::timedOut($deadline)) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'timed_out'];
        }
        self::applyScores($ids, $refs);

        return [
            'ok' => true,
            'processed' => count($ids),
            'skipped' => 0,
            'message' => 'score_done',
            'extra' => ['ids' => count($ids)],
        ];
    }

    public static function libsAvailable(): bool
    {
        $note = '';

        return self::resolveMorphy($note) !== null;
    }

    public static function invalidateCaches(): void
    {
        if (Database::tableExists('base_records_stem_key_cache')) {
            Database::pdo()->exec('TRUNCATE TABLE base_records_stem_key_cache');
        }
    }

    /**
     * @return array{start:int,end:int}|null
     */
    public static function parseDayOption(string $day): ?array
    {
        return self::parseDayRange($day);
    }

    /**
     * @deprecated Global wipe removed — never clears the whole DB.
     * Use resetDay($start, $end, …) or stem?reset=1&day=YYYY-MM-DD|record=ID.
     *
     * @return array{records:int,instances:int,collections:int,collections_datas:int}
     */
    public static function markAllForReparse(): array
    {
        throw new RuntimeException(
            'markAllForReparse is disabled: refuse global SCORE/instances wipe. '
            . 'Reparse a day or record only (StemRunner::resetDay / stem?reset=1&day=…|record=…).'
        );
    }

    /**
     * @return array{records:int,instances:int,collections:int,collections_datas:int,reviews:int,balances:int}
     */
    public static function resetDay(int $dayStart, int $dayEnd, int $adminId = 0, int $recordId = 0): array
    {
        $pdo = Database::pdo();
        $stats = [
            'records' => 0,
            'instances' => 0,
            'collections' => 0,
            'collections_datas' => 0,
            'reviews' => 0,
            'balances' => 0,
        ];
        if (!Database::tableExists('base_records')) {
            return $stats;
        }

        $where = ['DT_TRANSCRIBED > 1'];
        $params = [];
        if ($dayStart > 0 && $dayEnd > 0) {
            $where[] = 'DT_RECORDED > ? AND DT_RECORDED < ?';
            $params[] = $dayStart;
            $params[] = $dayEnd;
        }
        if ($adminId > 0) {
            $where[] = 'ID_ADMIN = ?';
            $params[] = $adminId;
        }
        if ($recordId > 0) {
            $where[] = 'ID = ?';
            $params[] = $recordId;
        }
        $sql = 'SELECT ID, ID_ADMIN, DT_RECORDED, PLAYTIME FROM base_records WHERE ' . implode(' AND ', $where);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $recordIds = [];
        $rangeByAdmin = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['ID'];
            $recordIds[$rid] = $rid;
            $aid = (int)$row['ID_ADMIN'];
            $dt = (int)$row['DT_RECORDED'];
            $end = $dt + (int)($row['PLAYTIME'] ?? 0);
            if ($aid > 0 && $dt > 0) {
                if (!isset($rangeByAdmin[$aid])) {
                    $rangeByAdmin[$aid] = ['min' => $dt, 'max' => $end];
                } else {
                    $rangeByAdmin[$aid]['min'] = min($rangeByAdmin[$aid]['min'], $dt);
                    $rangeByAdmin[$aid]['max'] = max($rangeByAdmin[$aid]['max'], $end);
                }
            }
        }
        $stats['records'] = count($recordIds);
        if ($stats['records'] < 1) {
            return $stats;
        }
        $ids = implode(',', array_map('intval', array_values($recordIds)));

        $pdo->exec(
            "UPDATE base_records SET DT_PARSED = 0, KEY_TYPES = '', OFFERS = '0',
             INTERSECTIONS = '', SCORE = '0', STRINGS = '0', STRINGS_NOK = '0'
             WHERE ID IN ($ids)"
        );
        if (Database::tableExists('base_records_instances')) {
            $stats['instances'] = (int)$pdo->exec("DELETE FROM base_records_instances WHERE ID_RECORD IN ($ids)");
        }
        foreach ($rangeByAdmin as $aid => $range) {
            $stats = self::deleteCollectionsForAdminRange($pdo, (int)$aid, (int)$range['min'], (int)$range['max'], $stats);
        }
        if (Database::tableExists('base_records_reviews')) {
            $stats['reviews'] = (int)$pdo->exec("DELETE FROM base_records_reviews WHERE ID_RECORD IN ($ids)");
        }
        if (Database::tableExists('base_records_balances')) {
            $stats['balances'] = (int)$pdo->exec("DELETE FROM base_records_balances WHERE ID_RECORD IN ($ids)");
        }

        return $stats;
    }

    /**
     * @param array{day?:string,admin?:int,record?:int,limit?:int,collect?:bool,full_collect?:bool} $opts
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function run(array $opts = []): array
    {
        if (!Database::tableExists('base_records')) {
            return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'message' => 'tables_missing'];
        }

        JobRunner::applyTimeLimit(isset($opts['time_budget_sec']) ? (int)$opts['time_budget_sec'] : null);
        $deadline = JobRunner::deadline(isset($opts['time_budget_sec']) ? (int)$opts['time_budget_sec'] : null);

        $morphMessage = '';
        $morphy = self::resolveMorphy($morphMessage);
        if ($morphy === null) {
            return [
                'ok' => false,
                'processed' => 0,
                'skipped' => 0,
                'message' => 'stem_libs_unavailable',
                'extra' => ['morph' => $morphMessage !== '' ? $morphMessage : 'morph_unavailable'],
            ];
        }

        /** @var array{bad_lem_words:array<string,string>,own_names:array<string,int>} $dict */
        $dict = require base_path('modules/audio_pipeline/lib/stem_dict_data.php');
        $badLem = is_array($dict['bad_lem_words'] ?? null) ? $dict['bad_lem_words'] : [];
        $ownNames = is_array($dict['own_names'] ?? null) ? $dict['own_names'] : [];

        StemCache::setVersion(StemCache::buildVersion($badLem, $ownNames));
        StemCache::initRun();

        $refs = self::loadReferenceData();
        if ($refs['keys'] === []) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'no_keys'];
        }

        $lemmatizer = new StemLemmatizer($badLem, $ownNames, $morphy);
        $keyBuild = self::buildKeyArrayEncoded($refs['keys'], $lemmatizer);
        if ($keyBuild['encoded'] === []) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'no_keys_encoded'];
        }

        $adminFilter = max(0, (int)($opts['admin'] ?? 0));
        $recordFilter = max(0, (int)($opts['record'] ?? 0));
        $dayRange = self::parseDayRange(isset($opts['day']) ? (string)$opts['day'] : '');
        $unparsedOnly = !empty($opts['unparsed_only']);
        $limit = (int)($opts['limit'] ?? JobRunner::batchSize());
        $limit = max(1, min(self::MAX_BATCH, $limit > 0 ? $limit : JobRunner::batchSize()));

        $rows = self::fetchRecords($dayRange, $adminFilter, $recordFilter, $limit, $unparsedOnly);
        if ($rows === []) {
            $msg = $morphMessage !== '' ? 'nothing_to_parse:' . $morphMessage : 'nothing_to_parse';
            $out = ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => $msg];
            $collExtra = self::runCollectAfterStem($opts, $dayRange, $adminFilter, 0);
            if ($collExtra !== null) {
                $out['extra'] = ['collect' => $collExtra];
            }
            return $out;
        }

        StemCache::registerScopesFromRecords($rows);

        $records = self::emptyRecordsBucket();
        $processed = 0;
        $skipped = 0;
        $prevBadge = 0;
        $prevBadgeDate = 0;
        $batchIds = [];

        foreach ($rows as $row) {
            if (JobRunner::timedOut($deadline)) {
                break;
            }
            $recordId = (int)$row['ID'];
            $batchIds[] = $recordId;
            $badgeId = (int)($row['ID_DICTOPHONE'] ?? 0);
            $recordDayTs = (int)($row['DT_RECORDED'] ?? 0);
            if ($prevBadge > 0 && $badgeId > 0 && $prevBadge !== $badgeId) {
                StemCache::flushBadgeBundle($prevBadge, $prevBadgeDate);
            }
            if ($badgeId > 0) {
                $prevBadge = $badgeId;
                $prevBadgeDate = $recordDayTs;
            }

            $records['Admins'][$recordId] = (int)($row['ID_ADMIN'] ?? 0);
            $records['DTStarts'][$recordId] = $recordDayTs;
            $records['STRINGS'][$recordId] = (int)($row['STRINGS'] ?? 0);
            $records['STRINGS_NOK'][$recordId] = (int)($row['STRINGS_NOK'] ?? 0);

            $transcription = (string)($row['TRANSCRIPTION'] ?? '');
            if ($transcription === '' || strpos($transcription, '[') === false) {
                $skipped++;
                continue;
            }

            $parsed = self::parseTranscriptionLines(
                $transcription,
                $recordId,
                $recordDayTs,
                $badgeId,
                $refs['trashes'],
                $lemmatizer,
                $records
            );
            if (!$parsed) {
                $skipped++;
            } else {
                $processed++;
            }
        }

        if ($prevBadge > 0) {
            StemCache::flushBadgeBundle($prevBadge, $prevBadgeDate);
        }

        $extra = [
            'batch_records' => count($batchIds),
            'keys_encoded' => count($keyBuild['encoded']),
            'max_phrase_len' => $keyBuild['max_length'],
            'stem_cache' => StemCache::statsText(),
        ];
        if ($morphMessage !== '') {
            $extra['morph'] = $morphMessage;
        }

        if ($batchIds !== []) {
            self::finalizeBatch($batchIds, $keyBuild, $refs, $records, $lemmatizer);
        }

        StemCache::flushWords();
        StemCache::flushAllBadgeBundles();

        $collect = !isset($opts['collect']) || $opts['collect'] !== false;
        if ($collect) {
            $collExtra = self::runCollectAfterStem($opts, $dayRange, $adminFilter, $processed);
            if ($collExtra !== null) {
                $extra['collect'] = $collExtra;
            }
        }

        $message = 'stem_done';
        if ($morphMessage === 'morph_unavailable') {
            $message = 'stem_done_morph_unavailable';
        }

        return [
            'ok' => true,
            'processed' => $processed,
            'skipped' => $skipped,
            'message' => $message,
            'extra' => $extra,
        ];
    }

    /**
     * Build script collections after stem (oper recordParser3 parity / Re-® day).
     * Day-scoped: only that day's admins + DT window. Else incremental unless full_collect.
     *
     * @param array{day?:string,admin?:int,record?:int,limit?:int,collect?:bool,full_collect?:bool} $opts
     * @param array{start:int,end:int}|null $dayRange
     * @return array{ok:bool,message:string,processed:int,collections:int}|null
     */
    private static function runCollectAfterStem(array $opts, ?array $dayRange, int $adminFilter, int $processed): ?array
    {
        $collect = !isset($opts['collect']) || $opts['collect'] !== false;
        if (!$collect) {
            return null;
        }
        $fullCollect = !empty($opts['full_collect']);
        // Cron with nothing new: skip. Day / admin / full: always collect (like Re-®).
        if ($dayRange === null && $adminFilter < 1 && !$fullCollect && $processed < 1) {
            return null;
        }

        $onlyAdmins = null;
        $window = null;
        if ($dayRange !== null) {
            $dayKey = date('Y-m-d', $dayRange['start']);
            $onlyAdmins = self::adminIdsForDay($dayKey);
            $window = [
                'start' => $dayRange['start'],
                'end' => $dayRange['end'],
                'time_budget_sec' => (int)($opts['collect_time_budget_sec'] ?? 90),
            ];
        } elseif ($adminFilter > 0) {
            $onlyAdmins = [$adminFilter];
        }

        $coll = CollectRunner::run($fullCollect && $onlyAdmins === null, $onlyAdmins, $window);
        return [
            'ok' => !empty($coll['ok']),
            'message' => (string)($coll['message'] ?? ''),
            'processed' => (int)($coll['processed'] ?? 0),
            'collections' => (int)(($coll['extra']['collections'] ?? 0)),
        ];
    }

    /**
     * @param-out string $note
     * @return object|null phpMorphy
     */
    private static function resolveMorphy(string &$note)
    {
        $note = 'morph_unavailable';
        $candidates = [];
        $cfgDir = trim((string)Config::get('audio', 'phpmorphy_dir', ''));
        if ($cfgDir !== '') {
            $candidates[] = $cfgDir;
        }
        // Bundled release copy (from oper libs/phpmorphy, LGPL-2.1+)
        $candidates[] = base_path('modules/audio_pipeline/vendor/phpmorphy');
        $candidates[] = base_path('libs/phpmorphy');

        foreach ($candidates as $base) {
            $base = rtrim($base, '/');
            $common = $base . '/src/common.php';
            $dicts = $base . '/dicts';
            if (!is_file($common) || !is_dir($dicts)) {
                continue;
            }
            require_once $common;
            if (!class_exists('phpMorphy', false)) {
                continue;
            }
            @setlocale(LC_CTYPE, ['ru_RU.CP1251', 'Russian_Russia.1251', 'ru_RU.UTF-8', 'ru_RU']);
            try {
                $storage = defined('PHPMORPHY_STORAGE_FILE') ? PHPMORPHY_STORAGE_FILE : 'file';
                $opts = [
                    'storage' => $storage,
                    'predict_by_suffix' => true,
                    'predict_by_db' => true,
                    'graminfo_as_text' => true,
                ];
                $morphy = new phpMorphy($dicts, 'ru_RU', $opts);
                $note = '';

                return $morphy;
            } catch (Throwable $e) {
                $note = 'morph_error:' . $e->getMessage();
                continue;
            }
        }

        return null;
    }

    /**
     * Count transcribed records still awaiting parse for a calendar day.
     */
    public static function countPendingForDay(string $day): int
    {
        $range = self::parseDayRange($day);
        if ($range === null || !Database::tableExists('base_records')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_records
             WHERE DT_TRANSCRIBED > 1
               AND (DT_PARSED < 1 OR DT_PARSED IS NULL)
               AND DT_RECORDED > ? AND DT_RECORDED < ?'
        );
        $st->execute([$range['start'], $range['end']]);

        return (int)$st->fetchColumn();
    }

    /**
     * Admin IDs that have records in the day window (for scoped collect).
     *
     * @return list<int>
     */
    public static function adminIdsForDay(string $day): array
    {
        $range = self::parseDayRange($day);
        if ($range === null || !Database::tableExists('base_records')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT DISTINCT ID_ADMIN FROM base_records
             WHERE DT_TRANSCRIBED > 1 AND ID_ADMIN > 0
               AND DT_RECORDED > ? AND DT_RECORDED < ?'
        );
        $st->execute([$range['start'], $range['end']]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $aid) {
            $out[] = (int)$aid;
        }

        return $out;
    }

    /**
     * @return array{start:int,end:int}|null
     */
    private static function parseDayRange(string $day): ?array
    {
        $day = trim($day);
        if ($day === '') {
            return null;
        }
        $ts = false;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $ts = strtotime($day . ' 00:00:00');
        } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $day)) {
            $parts = explode('-', $day);
            $ts = mktime(0, 0, 0, (int)$parts[1], (int)$parts[0], (int)$parts[2]);
        }
        if ($ts === false || $ts < 1) {
            return null;
        }
        $start = (int)mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
        $end = (int)mktime(23, 59, 59, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRecords(
        ?array $dayRange,
        int $adminId,
        int $recordId,
        int $limit,
        bool $unparsedOnly = false
    ): array {
        $where = ['DT_TRANSCRIBED > 1'];
        $params = [];
        if ($recordId > 0) {
            $where[] = 'ID = ?';
            $params[] = $recordId;
        } elseif ($dayRange !== null) {
            $where[] = 'DT_RECORDED > ? AND DT_RECORDED < ?';
            $params[] = $dayRange['start'];
            $params[] = $dayRange['end'];
            if ($unparsedOnly) {
                $where[] = '(DT_PARSED < 1 OR DT_PARSED IS NULL)';
            }
        } else {
            $since = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') - self::LOOKBACK_DAYS, (int)date('Y'));
            $where[] = '(DT_PARSED < 1 OR DT_PARSED IS NULL)';
            $where[] = 'DT_RECORDED >= ?';
            $params[] = $since;
        }
        if ($adminId > 0) {
            $where[] = 'ID_ADMIN = ?';
            $params[] = $adminId;
        }
        $sql = 'SELECT ID, ID_ADMIN, ID_DICTOPHONE, DT_RECORDED, TRANSCRIPTION,
                       STRINGS, STRINGS_NOK, VOLUME, OFFERS, INTERSECTIONS, SCORE
                FROM base_records WHERE ' . implode(' AND ', $where)
            . ' ORDER BY DT_RECORDED ASC LIMIT ' . (int)$limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{
     *   keys:array<int,array<string,mixed>>,
     *   types:array<int,array<string,mixed>>,
     *   dicts:array<int,array<string,mixed>>,
     *   trashes:list<string>,
     *   operators:array<int,array{ID_STATUS:int}>,
     *   statuses:array<int,array{ID_AUDIO_POSITION:int}>
     * }
     */
    private static function loadReferenceData(): array
    {
        $pdo = Database::pdo();
        $keys = [];
        $types = [];
        $dicts = [];
        $trashes = [];
        $operators = [];
        $statuses = [];

        if (Database::tableExists('base_records_keys')) {
            foreach ($pdo->query(
                'SELECT ID, NAME, ID_TYPE, ID_DICTONARY FROM base_records_keys WHERE DISABLED < 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)$row['ID'];
                $keys[$id] = $row;
            }
        }
        if (Database::tableExists('base_records_types')) {
            foreach ($pdo->query(
                'SELECT ID, ENABLED, KPI_ENABLED, ID_POSITION,
                        SCORE_VALUE, SCORE_TYPE_ID, SCORE_DIVERSITY,
                        SCORE_INSTANCES_MIN, SCORE_INSTANCES_MAX,
                        VOLUME, STRINGS_AMOUNT, MINTERSECT
                 FROM base_records_types'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $types[(int)$row['ID']] = $row;
            }
        }
        if (Database::tableExists('base_records_dictonarys')) {
            foreach ($pdo->query(
                'SELECT ID, IS_REVIEW_REQ, ID_POSITION FROM base_records_dictonarys WHERE DISABLED < 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $dicts[(int)$row['ID']] = $row;
            }
        }
        if (Database::tableExists('base_records_trashes')) {
            foreach ($pdo->query(
                'SELECT NAME FROM base_records_trashes WHERE ENABLED = 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $name = trim((string)($row['NAME'] ?? ''));
                if ($name !== '') {
                    $trashes[] = $name;
                }
            }
        }
        if (Database::tableExists('base_users')) {
            foreach ($pdo->query(
                'SELECT id, ID_STATUS FROM base_users WHERE enabled = \'1\''
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $operators[(int)$row['id']] = ['ID_STATUS' => (int)($row['ID_STATUS'] ?? 0)];
            }
        }
        if (Database::tableExists('base_admin_statuses')) {
            $cols = Database::columnExists('base_admin_statuses', 'ID_AUDIO_POSITION')
                ? 'ID, ID_AUDIO_POSITION' : 'ID';
            foreach ($pdo->query("SELECT $cols FROM base_admin_statuses")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $statuses[(int)$row['ID']] = [
                    'ID_AUDIO_POSITION' => (int)($row['ID_AUDIO_POSITION'] ?? 0),
                ];
            }
        }

        return compact('keys', 'types', 'dicts', 'trashes', 'operators', 'statuses');
    }

    /**
     * @param array<int, array<string, mixed>> $keys
     * @return array{encoded:array<int,list<int>>,max_length:int,min_length:int}
     */
    private static function buildKeyArrayEncoded(array $keys, StemLemmatizer $lemmatizer): array
    {
        $encoded = [];
        $maxLength = 0;
        $minLength = 1000;
        $stemWords = &$lemmatizer->stemWordsRef();
        foreach ($keys as $keyId => $keyData) {
            $name = str_replace('**', ' ', (string)($keyData['NAME'] ?? ''));
            if (strpos($name, '|') !== false) {
                $kData = explode('|', $name);
            } else {
                $kData = $name;
            }
            $textData = StemCache::keyTextForStem($kData);
            if ($textData === '') {
                continue;
            }
            $nameHash = StemCache::keyNameHash($textData);
            $cachedKey = StemCache::getKey((int)$keyId, $nameHash);
            if (is_array($cachedKey) && isset($cachedKey['lemmas'])) {
                $mapped = StemCache::lemmasToContentArrays($cachedKey['lemmas'], [], [], $stemWords);
                $stringResult = $mapped['contentArray'];
            } else {
                $lemmaResult = $lemmatizer->stringProcessLemmas($textData);
                if ($lemmaResult === false) {
                    continue;
                }
                StemCache::setKey((int)$keyId, $nameHash, $lemmaResult['lemmas']);
                $mapped = StemCache::lemmasToContentArrays(
                    $lemmaResult['lemmas'],
                    $lemmaResult['contentStartArray'],
                    $lemmaResult['contentLengthArray'],
                    $stemWords
                );
                $stringResult = $mapped['contentArray'];
            }
            $encoded[(int)$keyId] = array_values($stringResult);
            $len = count($stringResult);
            if ($len > $maxLength) {
                $maxLength = $len;
            }
            if ($len > 0 && $len < $minLength) {
                $minLength = $len;
            }
        }

        return [
            'encoded' => $encoded,
            'max_length' => $maxLength,
            'min_length' => $minLength === 1000 ? 0 : $minLength,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyRecordsBucket(): array
    {
        return [
            'encoded' => [],
            'StartEncoded' => [],
            'LengthEncoded' => [],
            'TimeStarts' => [],
            'TimeEnds' => [],
            'Admins' => [],
            'STRINGS' => [],
            'STRINGS_NOK' => [],
            'STRINGS_IN_RECORD' => [],
            'DTStarts' => [],
        ];
    }

    /**
     * @param list<string> $trashes
     * @param array<string, mixed> $records
     */
    private static function parseTranscriptionLines(
        string $srcTranscription,
        int $recordId,
        int $recordDayTs,
        int $badgeId,
        array $trashes,
        StemLemmatizer $lemmatizer,
        array &$records
    ): bool {
        $transArray = explode('[', $srcTranscription);
        array_shift($transArray);
        $oldString = '';
        $oldString2 = '';
        $oldString3 = '';
        $hadLine = false;

        foreach ($transArray as $num => $transString) {
            if (strpos($transString, ']') === false) {
                continue;
            }
            $transStringArray = explode(']', $transString, 2);
            $stringTime = $transStringArray[0];
            $stringTimeStart = 0;
            $stringTimeEnd = 0;
            if (strpos($stringTime, '->') !== false) {
                $stringTimeSecondsText = explode('->', $stringTime);
                if (strpos($stringTimeSecondsText[0], ':') !== false) {
                    $startParts = explode(':', $stringTimeSecondsText[0]);
                    $stringTimeStart = (int)$startParts[0] * 60 + (int)$startParts[1];
                    $endParts = explode(':', $stringTimeSecondsText[1]);
                    $stringTimeEnd = (int)$endParts[0] * 60 + (int)$endParts[1];
                }
            }
            $stringPureText = $transStringArray[1] ?? '';
            if ($oldString === $stringPureText || $oldString2 === $stringPureText || $oldString3 === $stringPureText) {
                continue;
            }

            $trashFound = false;
            foreach ($trashes as $trashSample) {
                if ($trashSample !== '' && strpos($stringPureText, $trashSample) !== false) {
                    $trashFound = true;
                    break;
                }
            }
            if (mb_strlen($stringPureText, 'UTF-8') > 500) {
                $trashFound = true;
            }
            if ($trashFound) {
                continue;
            }

            $spt = $lemmatizer->stringProcessRecordLine($stringPureText, 0, $recordDayTs, $badgeId);
            if ($spt === false || ($spt['contentArray'] ?? []) === []) {
                continue;
            }

            $records['encoded'][$recordId][$num] = $spt['contentArray'];
            $records['StartEncoded'][$recordId][$num] = $spt['contentStartArray'];
            $records['LengthEncoded'][$recordId][$num] = $spt['contentLengthArray'];
            $records['TimeStarts'][$recordId][$num] = $stringTimeStart;
            $records['TimeEnds'][$recordId][$num] = $stringTimeEnd;
            $records['STRINGS_IN_RECORD'][$recordId] = (int)($records['STRINGS_IN_RECORD'][$recordId] ?? 0) + 1;

            if ($oldString2 !== '') {
                $oldString3 = $oldString2;
            }
            if ($oldString !== '') {
                $oldString2 = $oldString;
            }
            $oldString = $stringPureText;
            $hadLine = true;
        }

        return $hadLine;
    }

    /**
     * @param list<int> $batchIds
     * @param array{encoded:array<int,list<int>>,max_length:int} $keyBuild
     * @param array<string, mixed> $refs
     * @param array<string, mixed> $records
     */
    private static function finalizeBatch(array $batchIds, array $keyBuild, array $refs, array $records, StemLemmatizer $lemmatizer): void
    {
        $pdo = Database::pdo();
        $ids = implode(',', array_map('intval', $batchIds));
        $now = time();

        if (Database::tableExists('base_records_instances')) {
            $pdo->exec("DELETE FROM base_records_instances WHERE ID_RECORD IN ($ids)");
        }

        // Oper parity: clear KEY_TYPES/OFFERS for the whole batch before applying hits.
        $pdo->exec(
            "UPDATE base_records SET DT_PARSED = {$now}, KEY_TYPES = '', OFFERS = '0'
             WHERE ID IN ($ids)"
        );

        $occurrences = self::findPhraseOccurrences(
            $keyBuild['encoded'],
            $records,
            $keyBuild['max_length'],
            $refs
        );

        $parsedIds = [];

        if (!empty($occurrences['instnances'])) {
            $values = [];
            foreach ($occurrences['instnances'] as $recordId => $recordDataExtract) {
                $recordId = (int)$recordId;
                $parsedIds[$recordId] = true;
                $offers = (int)($occurrences['offers'][$recordId] ?? 0);
                $keysByTypeArray = $occurrences['keysByTypeArray'][$recordId] ?? [];
                $keyTypeText = self::formatKeyTypes($keysByTypeArray);

                $stringsWithKeys = count($recordDataExtract);
                $stringsInRecord = (int)($records['STRINGS_IN_RECORD'][$recordId] ?? 0);
                $stringsNoKeys = max(0, $stringsInRecord - $stringsWithKeys);

                $stUp = $pdo->prepare(
                    'UPDATE base_records SET DT_PARSED = ?, KEY_TYPES = ?, OFFERS = ?,
                     STRINGS = ?, STRINGS_NOK = ? WHERE ID = ?'
                );
                $stUp->execute([
                    $now,
                    $keyTypeText,
                    (string)$offers,
                    (string)$stringsInRecord,
                    (string)$stringsNoKeys,
                    $recordId,
                ]);

                if (self::recordNeedsReview($keysByTypeArray, $refs)
                    && Database::tableExists('base_records_reviews')
                ) {
                    $pdo->prepare(
                        'INSERT IGNORE INTO base_records_reviews (ID_RECORD, DT_CREATE) VALUES (?, ?)'
                    )->execute([$recordId, $now]);
                }

                foreach ($recordDataExtract as $lineNumber => $keyFoundData) {
                    foreach ($keyFoundData as $keyFoundId => $founds) {
                        foreach ($founds as $DTSE) {
                            $firstWordIndex = $DTSE[0];
                            $lastWordIndex = $DTSE[count($DTSE) - 1];
                            $startInstance = (int)$records['StartEncoded'][$recordId][$lineNumber][$firstWordIndex];
                            $lastWordStart = (int)$records['StartEncoded'][$recordId][$lineNumber][$lastWordIndex];
                            $lastWordLength = (int)($records['LengthEncoded'][$recordId][$lineNumber][$lastWordIndex] ?? 0);
                            $lengthInstance = ($lastWordStart + $lastWordLength) - $startInstance;
                            $dtStart = (int)($records['TimeStarts'][$recordId][$lineNumber] ?? 0)
                                + (int)($records['DTStarts'][$recordId] ?? 0);
                            $dtEnd = (int)($records['TimeEnds'][$recordId][$lineNumber] ?? 0)
                                + (int)($records['DTStarts'][$recordId] ?? 0);
                            $values[] = sprintf(
                                '(%d,%d,%d,%d,%d,%d,%d,%d)',
                                $dtStart,
                                $dtEnd,
                                (int)$lineNumber,
                                $startInstance,
                                $recordId,
                                (int)($records['Admins'][$recordId] ?? 0),
                                (int)$keyFoundId,
                                max(0, $lengthInstance)
                            );
                        }
                    }
                }
            }
            if ($values !== [] && Database::tableExists('base_records_instances')) {
                $chunks = array_chunk($values, 100);
                foreach ($chunks as $chunk) {
                    $pdo->exec(
                        'INSERT INTO base_records_instances
                         (DT_START, DT_END, STRING_NUM, STRING_POSITION, ID_RECORD, ID_ADMIN, ID_KEY, LENGTH)
                         VALUES ' . implode(',', $chunk)
                    );
                }
            }
        }

        foreach ($batchIds as $rid) {
            if (isset($parsedIds[$rid])) {
                continue;
            }
            $stringsInRecord = (int)($records['STRINGS_IN_RECORD'][$rid] ?? 0);
            $st = $pdo->prepare(
                'UPDATE base_records SET DT_PARSED = ?, STRINGS = ?, STRINGS_NOK = ? WHERE ID = ?'
            );
            $st->execute([
                $now,
                (string)$stringsInRecord,
                (string)$stringsInRecord,
                $rid,
            ]);
        }

        self::applyScores($batchIds, $refs);
    }

    /**
     * Oper recordParser3 rating block: SCORE from enabled types with SCORE_VALUE.
     *
     * @param list<int> $batchIds
     * @param array<string, mixed> $refs
     */
    private static function applyScores(array $batchIds, array $refs): void
    {
        $batchIds = array_values(array_filter(array_map('intval', $batchIds)));
        if ($batchIds === [] || !Database::tableExists('base_records')) {
            return;
        }

        $scoringTypes = [];
        foreach ($refs['types'] as $rtId => $rtData) {
            if ((int)($rtData['ENABLED'] ?? 0) > 0 && (int)($rtData['SCORE_VALUE'] ?? 0) !== 0) {
                $scoringTypes[(int)$rtId] = $rtData;
            }
        }
        if ($scoringTypes === []) {
            return;
        }

        $in = implode(',', $batchIds);
        $rows = Database::pdo()->query(
            "SELECT ID, ID_ADMIN, KEY_TYPES, OFFERS, VOLUME, STRINGS, STRINGS_NOK, INTERSECTIONS, SCORE
             FROM base_records WHERE ID IN ($in)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $upd = Database::pdo()->prepare('UPDATE base_records SET SCORE = ? WHERE ID = ?');
        foreach ($rows as $row) {
            $recordId = (int)$row['ID'];
            $keysAnalytics = self::parseKeyTypesAnalytics((string)($row['KEY_TYPES'] ?? ''));
            $wasIntersect = 0;
            $rawInter = (string)($row['INTERSECTIONS'] ?? '');
            if ($rawInter !== '') {
                $un = @unserialize($rawInter);
                if (is_array($un) && $un !== []) {
                    $wasIntersect = 1;
                }
            }
            $volume = (int)($row['VOLUME'] ?? 0);
            $strings = (int)($row['STRINGS'] ?? 0);
            if ($strings < 1) {
                $strings = (int)($row['STRINGS_NOK'] ?? 0);
            }
            $offers = (int)($row['OFFERS'] ?? 0);

            $recordAdminId = (int)($row['ID_ADMIN'] ?? 0);
            $recordEquivPositions = [0];
            if ($recordAdminId > 0) {
                $recordPositionId = (int)($refs['operators'][$recordAdminId]['ID_STATUS'] ?? 0);
                $recordEquivPositions = self::getEquivalentAudioPositions($recordPositionId, $refs['statuses']);
                if ($recordEquivPositions === []) {
                    $recordEquivPositions = [0];
                }
            }

            $score2 = 0;
            foreach ($scoringTypes as $rtId => $rtData) {
                $typePositionValue = (string)($rtData['ID_POSITION'] ?? '0');
                if (!self::isRecordPositionAllowed($typePositionValue, $recordEquivPositions)) {
                    continue;
                }
                if (self::checkKeyType($rtId, $keysAnalytics, $volume, $strings, $wasIntersect, $offers, $refs['types'])) {
                    $score2 += (int)($rtData['SCORE_VALUE'] ?? 0);
                }
            }
            if ($score2 < 0) {
                $score2 = 0;
            }
            if ($score2 !== (int)($row['SCORE'] ?? 0)) {
                $upd->execute([(string)$score2, $recordId]);
            }
        }
    }

    /**
     * @param array<int, array<int, int>> $keysByTypeArray
     * @param array<string, mixed> $refs
     */
    private static function recordNeedsReview(array $keysByTypeArray, array $refs): bool
    {
        $keys = $refs['keys'] ?? [];
        $dicts = $refs['dicts'] ?? [];
        foreach ($keysByTypeArray as $keyData) {
            if (!is_array($keyData)) {
                continue;
            }
            foreach ($keyData as $keyId => $_) {
                $dictId = (int)($keys[(int)$keyId]['ID_DICTONARY'] ?? 0);
                if ($dictId < 1) {
                    continue;
                }
                if (!empty($dicts[$dictId]['IS_REVIEW_REQ'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array{DIVERSITY:int,INSTANCES:int}>
     */
    private static function parseKeyTypesAnalytics(string $keysData): array
    {
        $keysAnalytics = [];
        if ($keysData === '') {
            return $keysAnalytics;
        }
        foreach (explode('##', $keysData) as $keyData) {
            $keyData = str_replace('#', '', $keyData);
            $keyData = trim($keyData);
            if ($keyData === '') {
                continue;
            }
            $parts = explode('-', $keyData);
            if (count($parts) < 3) {
                continue;
            }
            $typeId = (int)$parts[0];
            if ($typeId < 1) {
                continue;
            }
            $keysAnalytics[$typeId] = [
                'DIVERSITY' => (int)$parts[1],
                'INSTANCES' => (int)$parts[2],
            ];
        }

        return $keysAnalytics;
    }

    /**
     * @param array<int, array{DIVERSITY:int,INSTANCES:int}> $keysAnalytics
     * @param array<int, array<string, mixed>> $types
     */
    private static function checkKeyType(
        int $keyTypeId,
        array $keysAnalytics,
        int $volume,
        int $strings,
        int $intersect,
        int $offers,
        array $types
    ): bool {
        if (!isset($types[$keyTypeId])) {
            return false;
        }
        $keyTypeData = $types[$keyTypeId];
        $testKeyType = true;
        $anyCheck = 0;

        $linked = (int)($keyTypeData['SCORE_TYPE_ID'] ?? 0);
        if ($linked > 0) {
            $testKeyType = self::checkKeyType($linked, $keysAnalytics, $volume, $strings, $intersect, $offers, $types);
        }
        if (!$testKeyType) {
            return false;
        }

        $scoreDiversity = (int)($keyTypeData['SCORE_DIVERSITY'] ?? 0);
        if ($scoreDiversity > 0) {
            $anyCheck++;
            if (!isset($keysAnalytics[$keyTypeId])) {
                return false;
            }
            if ($scoreDiversity >= (int)$keysAnalytics[$keyTypeId]['DIVERSITY']) {
                return false;
            }
        }

        $instMin = (int)($keyTypeData['SCORE_INSTANCES_MIN'] ?? 0);
        if ($instMin < 0) {
            $anyCheck++;
            if (isset($keysAnalytics[$keyTypeId])) {
                return false;
            }
        } elseif ($instMin > 0) {
            $anyCheck++;
            if (!isset($keysAnalytics[$keyTypeId])) {
                if (!((int)($keyTypeData['KPI_ENABLED'] ?? 0) > 0 && $offers > 0)) {
                    return false;
                }
            } elseif ((int)$keysAnalytics[$keyTypeId]['INSTANCES'] < $instMin) {
                return false;
            }
        }

        $instMax = (int)($keyTypeData['SCORE_INSTANCES_MAX'] ?? 0);
        if ($instMin > 0 && $instMax > 0) {
            $anyCheck++;
            if (!isset($keysAnalytics[$keyTypeId])) {
                return false;
            }
            if ((int)$keysAnalytics[$keyTypeId]['INSTANCES'] > $instMax) {
                return false;
            }
        }

        $volReq = (int)($keyTypeData['VOLUME'] ?? 0);
        if ($volReq > 0) {
            $anyCheck++;
            if ($volReq >= $volume) {
                return false;
            }
        }

        $stringsReq = (int)($keyTypeData['STRINGS_AMOUNT'] ?? 0);
        if ($stringsReq > 0) {
            $anyCheck++;
            if ($stringsReq >= $strings) {
                return false;
            }
        }

        $mIntersect = (int)($keyTypeData['MINTERSECT'] ?? 0);
        if ($mIntersect > 0) {
            $anyCheck++;
            if ($intersect) {
                if ($instMin < 0) {
                    return false;
                }
            } elseif ($instMin > 0) {
                return false;
            }
        }

        return $anyCheck > 0;
    }

    /**
     * @param array<int, array<int, array<int, int>>> $keysByTypeArray
     */
    private static function formatKeyTypes(array $keysByTypeArray): string
    {
        $keyTypeText = '';
        foreach ($keysByTypeArray as $keyType => $keyData) {
            $keysAmountOfType = count($keyData);
            $keysAmount = 0;
            foreach ($keyData as $keyAmount) {
                $keysAmount += (int)$keyAmount;
            }
            $keyTypeText .= '#' . (int)$keyType . '-' . $keysAmountOfType . '-' . $keysAmount . '#';
        }

        return $keyTypeText;
    }

    /**
     * @param array<int, list<int>> $keyArrayEncoded
     * @param array<string, mixed> $records
     * @param array<string, mixed> $refs
     * @return array{
     *   instnances:array<int, array<int, array<int, list<list<int>>>>>,
     *   founds:int,
     *   offers:array<int,int>,
     *   offersTotal:int,
     *   statsKeysTimeSkip:int,
     *   keysByTypeArray:array<int, array<int, array<int,int>>>
     * }
     */
    private static function findPhraseOccurrences(array $keyArrayEncoded, array $records, int $maxLength, array $refs): array
    {
        $keyHashes = [];
        foreach ($keyArrayEncoded as $keyId => $phrase) {
            $hash = self::createHash($phrase);
            if (!isset($keyHashes[$hash])) {
                $keyHashes[$hash] = (int)$keyId;
            }
        }

        $instnances = [];
        $founds = 0;
        $offers = [];
        $offersTotal = 0;
        $statsKeysTimeSkip = 0;
        $keysByTypeArray = [];

        foreach ($records['encoded'] ?? [] as $recordId => $record) {
            $keysLastTime = 0;
            $offers[(int)$recordId] = 0;
            foreach ($record as $lineNumber => $line) {
                $lineIds = array_values($line);
                $i = 0;
                $stringTimeSeconds = (int)($records['TimeStarts'][$recordId][$lineNumber] ?? 0);
                $stringTimeEnd = (int)($records['TimeEnds'][$recordId][$lineNumber] ?? 0);

                while ($i < count($lineIds)) {
                    $found = false;
                    for ($length = 1; $length <= $maxLength; $length++) {
                        if ($i + $length > count($lineIds)) {
                            break;
                        }
                        $subArray = array_slice($lineIds, $i, $length);
                        $hash = self::createHash($subArray);
                        if (!isset($keyHashes[$hash])) {
                            continue;
                        }
                        $phraseKey = (int)$keyHashes[$hash];
                        $keyMeta = $refs['keys'][$phraseKey] ?? null;
                        if ($keyMeta === null) {
                            continue;
                        }
                        $keyType = (int)($keyMeta['ID_TYPE'] ?? 0);
                        $typeRow = $refs['types'][$keyType] ?? null;
                        if ($typeRow === null) {
                            continue;
                        }
                        $isKpi = (int)($typeRow['KPI_ENABLED'] ?? 0) > 0;
                        $canBeAddedByTime = true;
                        if ($isKpi && $stringTimeSeconds && self::KPI_GAP_SEC && $keysLastTime) {
                            if ($stringTimeSeconds - $keysLastTime < self::KPI_GAP_SEC) {
                                $canBeAddedByTime = false;
                            }
                        }

                        $typePositionValue = (string)($typeRow['ID_POSITION'] ?? '0');
                        $dictId = (int)($keyMeta['ID_DICTONARY'] ?? 0);
                        $dictRow = $refs['dicts'][$dictId] ?? null;
                        $dictonaryPositionValue = (string)($dictRow['ID_POSITION'] ?? '0');
                        $canBeAddedByPosition = self::isRecordPositionGlobal($typePositionValue)
                            && self::isRecordPositionGlobal($dictonaryPositionValue);
                        $recordAdminId = (int)($records['Admins'][$recordId] ?? 0);
                        if (!$canBeAddedByPosition && $recordAdminId > 0) {
                            $recordPositionId = (int)($refs['operators'][$recordAdminId]['ID_STATUS'] ?? 0);
                            $recordAllowedPositions = self::getEquivalentAudioPositions($recordPositionId, $refs['statuses']);
                            $recordAllowedPositions[] = 0;
                            if (self::isRecordPositionAllowed($typePositionValue, $recordAllowedPositions)
                                && self::isRecordPositionAllowed($dictonaryPositionValue, $recordAllowedPositions)
                            ) {
                                $canBeAddedByPosition = true;
                            }
                        }

                        if ($canBeAddedByTime && $canBeAddedByPosition) {
                            if (!isset($keysByTypeArray[$recordId][$keyType])) {
                                $keysByTypeArray[$recordId][$keyType] = [];
                            }
                            if (!isset($keysByTypeArray[$recordId][$keyType][$phraseKey])) {
                                $keysByTypeArray[$recordId][$keyType][$phraseKey] = 0;
                            }
                            $keysByTypeArray[$recordId][$keyType][$phraseKey]++;
                            $instnances[$recordId][$lineNumber][$phraseKey][] = range($i, $i + $length - 1);
                            $founds++;
                            if ($isKpi) {
                                $offers[$recordId]++;
                                $keysLastTime = $stringTimeEnd;
                                $offersTotal++;
                            }
                        } else {
                            $statsKeysTimeSkip++;
                        }

                        $found = true;
                        $i += $length;
                        break;
                    }
                    if (!$found) {
                        $i++;
                    }
                }
            }
        }

        return [
            'instnances' => $instnances,
            'founds' => $founds,
            'offers' => $offers,
            'offersTotal' => $offersTotal,
            'statsKeysTimeSkip' => $statsKeysTimeSkip,
            'keysByTypeArray' => $keysByTypeArray,
        ];
    }

    /**
     * @param list<int> $array
     */
    private static function createHash(array $array): string
    {
        sort($array);

        return implode(',', $array);
    }

    private static function isRecordPositionGlobal(string $value): bool
    {
        $value = trim($value);

        return $value === '' || $value === '0';
    }

    /**
     * @param list<int>|array<int,int> $allowedPositions
     */
    private static function isRecordPositionAllowed(string $storedValue, array $allowedPositions): bool
    {
        if (self::isRecordPositionGlobal($storedValue)) {
            return true;
        }
        $allowedIndex = [];
        foreach ($allowedPositions as $positionKey => $positionValue) {
            $positionId = (int)$positionValue;
            if ((int)$positionKey > 0 && $positionValue) {
                $positionId = (int)$positionKey;
            }
            if ($positionId > 0) {
                $allowedIndex[$positionId] = 1;
            }
        }
        foreach (self::parseRecordPositionIds($storedValue) as $positionId) {
            if (isset($allowedIndex[$positionId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function parseRecordPositionIds(string $value): array
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return [];
        }
        $value = str_replace('#', '', $value);
        $positions = [];
        foreach (explode(',', $value) as $part) {
            $positionId = (int)trim($part);
            if ($positionId > 0) {
                $positions[$positionId] = $positionId;
            }
        }

        return array_values($positions);
    }

    /**
     * @param array<int, array{ID_AUDIO_POSITION:int}> $statuses
     * @return list<int>
     */
    private static function getEquivalentAudioPositions(int $positionId, array $statuses, array $visited = []): array
    {
        static $cache = [];
        if (isset($cache[$positionId])) {
            return $cache[$positionId];
        }
        if ($positionId <= 0) {
            $cache[$positionId] = [$positionId];

            return [$positionId];
        }
        if (in_array($positionId, $visited, true)) {
            $cache[$positionId] = [$positionId];

            return [$positionId];
        }
        $result = [$positionId];
        $visited[] = $positionId;
        $audioPositionId = (int)($statuses[$positionId]['ID_AUDIO_POSITION'] ?? 0);
        if ($audioPositionId > 0 && $audioPositionId !== $positionId) {
            $result = array_unique(array_merge($result, self::getEquivalentAudioPositions($audioPositionId, $statuses, $visited)));
        }
        foreach ($statuses as $otherId => $otherData) {
            $otherAudio = (int)($otherData['ID_AUDIO_POSITION'] ?? 0);
            if ($otherAudio === $positionId && $otherId !== $positionId) {
                $result = array_unique(array_merge($result, self::getEquivalentAudioPositions((int)$otherId, $statuses, $visited)));
            }
        }
        $cache[$positionId] = array_values($result);

        return $cache[$positionId];
    }

    /**
     * @param array{collections:int,collections_datas:int} $stats
     * @return array{collections:int,collections_datas:int}
     */
    private static function deleteCollectionsForAdminRange(PDO $pdo, int $adminId, int $minDt, int $maxDt, array $stats): array
    {
        if (!Database::tableExists('base_records_collections') || $adminId < 1 || $minDt < 1) {
            return $stats;
        }
        $st = $pdo->prepare(
            'SELECT ID FROM base_records_collections
             WHERE ID_ADMIN = ? AND DT_START >= ? AND DT_END <= ?'
        );
        $st->execute([$adminId, $minDt, $maxDt]);
        $colIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($colIds === []) {
            return $stats;
        }
        $in = implode(',', $colIds);
        if (Database::tableExists('base_records_collections_datas')) {
            $stats['collections_datas'] += (int)$pdo->exec(
                "DELETE FROM base_records_collections_datas WHERE ID_COLLECTION IN ($in)"
            );
        }
        $stats['collections'] += (int)$pdo->exec("DELETE FROM base_records_collections WHERE ID IN ($in)");

        return $stats;
    }
}
