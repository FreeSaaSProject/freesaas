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
    private const LOOKBACK_DAYS = 10;
    private const MAX_BATCH = 700;

    /**
     * Reuse morph/refs/key encoding within one PHP request (multi-batch reparse).
     * Lemmatizer must stay the same instance so stem-word IDs match key encodings.
     *
     * @var array{
     *   version:string,
     *   morph_message:string,
     *   morphy:object,
     *   refs:array,
     *   lemmatizer:StemLemmatizer,
     *   key_build:array{encoded:array,max_length:int,min_length:int}
     * }|null
     */
    private static $prepared = null;

    /** @var array<string,string> ASR-loop candidates pending flush into base_records_trashes */
    private static $pendingTrashCandidates = [];

    /**
     * Recalculate SCORE via script collections (collect + RatingAggregates).
     * Writes base_records.KEY_TYPES (legacy name) as dict-hit chip cache; SCORE from collections.
     *
     * @param array{day?:string,admin?:int,record?:int,limit?:int,time_budget_sec?:int} $opts
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function scoreOnly(array $opts = []): array
    {
        if (!Database::tableExists('base_records')) {
            return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'message' => 'tables_missing'];
        }
        JobRunner::applyTimeLimit(isset($opts['time_budget_sec']) ? (int)$opts['time_budget_sec'] : 120);

        $adminFilter = max(0, (int)($opts['admin'] ?? 0));
        $recordFilter = max(0, (int)($opts['record'] ?? 0));
        $dayRange = self::parseDayRange(isset($opts['day']) ? (string)$opts['day'] : '');

        if ($recordFilter > 0) {
            $st = Database::pdo()->prepare('SELECT ID_ADMIN, DT_RECORDED FROM base_records WHERE ID = ? LIMIT 1');
            $st->execute([$recordFilter]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'nothing_to_score'];
            }
            $adminFilter = (int)$row['ID_ADMIN'];
            $day = date('Y-m-d', (int)$row['DT_RECORDED']);
            $dayRange = self::parseDayRange($day);
        }

        $enabledRecords = 0;
        if ($dayRange !== null) {
            $enabledRecords = self::enableDisabledCoverageRecords($dayRange['start'], $dayRange['end'], $adminFilter);
        }

        $onlyAdmins = null;
        $window = null;
        if ($dayRange !== null) {
            $onlyAdmins = $adminFilter > 0
                ? [$adminFilter]
                : self::adminIdsForDay(date('Y-m-d', $dayRange['start']));
            $window = [
                'start' => $dayRange['start'],
                'end' => $dayRange['end'],
                'time_budget_sec' => (int)($opts['time_budget_sec'] ?? 90),
            ];
        } elseif ($adminFilter > 0) {
            $onlyAdmins = [$adminFilter];
        } else {
            return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'message' => 'need_day_or_admin'];
        }

        $coll = CollectRunner::run(false, $onlyAdmins, $window);

        $disabledRecords = 0;
        if ($dayRange !== null) {
            $disabledRecords = self::disableOutsideCoverageAfterCollect(
                $dayRange['start'],
                $dayRange['end'],
                $adminFilter
            );
        }

        $extra = [
            'collections' => (int)(($coll['extra']['collections'] ?? 0)),
            'admins' => count($onlyAdmins ?? []),
        ];
        if ($enabledRecords > 0) {
            $extra['enabled_records'] = $enabledRecords;
        }
        if ($disabledRecords > 0) {
            $extra['disabled_records'] = $disabledRecords;
        }

        return [
            'ok' => !empty($coll['ok']),
            'processed' => (int)($coll['processed'] ?? 0),
            'skipped' => 0,
            'message' => 'score_via_collect',
            'extra' => $extra,
        ];
    }

    /**
     * Shared lemmatizer for phrase mining / other workers.
     */
    public static function makeLemmatizer(): ?StemLemmatizer
    {
        $note = '';
        $morphy = self::resolveMorphy($note);
        if ($morphy === null) {
            return null;
        }
        $dict = require base_path('modules/audio_pipeline/lib/stem_dict_data.php');
        $badLem = is_array($dict['badLemWords'] ?? null) ? $dict['badLemWords'] : [];
        $ownNames = is_array($dict['ownNames'] ?? null) ? $dict['ownNames'] : [];
        StemCache::setVersion(StemCache::buildVersion($badLem, $ownNames));
        StemCache::initRun();

        return new StemLemmatizer($badLem, $ownNames, $morphy);
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
     * Does not touch base_records_reviews (inspected / favorites survive reparse).
     *
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
        // Keep base_records_reviews: already-inspected rows (DT_REVIEW>0) and favorites
        // must survive reparse. finalizeBatch only INSERT IGNORE / upserts DT_CREATE for
        // newly triggered review-required keys.
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

        $prepared = self::prepareRunContext();
        if ($prepared === null) {
            return [
                'ok' => false,
                'processed' => 0,
                'skipped' => 0,
                'message' => 'stem_libs_unavailable',
                'extra' => ['morph' => 'morph_unavailable'],
            ];
        }
        $morphMessage = $prepared['morph_message'];
        $refs = $prepared['refs'];
        if ($refs['keys'] === []) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'no_keys'];
        }
        $lemmatizer = $prepared['lemmatizer'];
        $keyBuild = $prepared['key_build'];
        if ($keyBuild['encoded'] === []) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'no_keys_encoded'];
        }
        StemCache::initRun();

        $adminFilter = max(0, (int)($opts['admin'] ?? 0));
        $recordFilter = max(0, (int)($opts['record'] ?? 0));
        $dayRange = self::parseDayRange(isset($opts['day']) ? (string)$opts['day'] : '');
        $unparsedOnly = !empty($opts['unparsed_only']);
        $limit = (int)($opts['limit'] ?? JobRunner::batchSize());
        $limit = max(1, min(self::MAX_BATCH, $limit > 0 ? $limit : JobRunner::batchSize()));

        // Re-enable DISABLED coverage-role records for the day so stem/collect sees full audio.
        // Optional re-disable after collect: AudioCoverageConfig::disable_outside_coverage.
        $enabledRecords = 0;
        if ($dayRange !== null) {
            $enabledRecords = self::enableDisabledCoverageRecords($dayRange['start'], $dayRange['end'], $adminFilter);
        }

        $rows = self::fetchRecords($dayRange, $adminFilter, $recordFilter, $limit, $unparsedOnly);
        if ($rows === []) {
            $msg = $morphMessage !== '' ? 'nothing_to_parse:' . $morphMessage : 'nothing_to_parse';
            $out = ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => $msg];
            $extraEmpty = [];
            if ($enabledRecords > 0) {
                $extraEmpty['enabled_records'] = $enabledRecords;
            }
            $collExtra = self::runCollectAfterStem($opts, $dayRange, $adminFilter, 0);
            if ($collExtra !== null) {
                $extraEmpty['collect'] = $collExtra;
            }
            if ($extraEmpty !== []) {
                $out['extra'] = $extraEmpty;
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
        if ($enabledRecords > 0) {
            $extra['enabled_records'] = $enabledRecords;
        }
        if ($morphMessage !== '') {
            $extra['morph'] = $morphMessage;
        }

        $timedOut = JobRunner::timedOut($deadline);
        try {
            if ($batchIds !== []) {
                self::finalizeBatch($batchIds, $keyBuild, $refs, $records, $lemmatizer);
            }

            $trashAdded = self::flushPendingTrashCandidates();
            if ($trashAdded > 0) {
                $extra['trash_added'] = $trashAdded;
            }

            StemCache::flushWords();
            StemCache::flushAllBadgeBundles();

            $collect = !isset($opts['collect']) || $opts['collect'] !== false;
            // Skip collect when the stem budget is already spent — next idle poll will continue.
            if ($collect && !JobRunner::timedOut($deadline)) {
                $remain = max(15, (int)ceil($deadline - microtime(true)));
                $opts['collect_time_budget_sec'] = min(
                    (int)($opts['collect_time_budget_sec'] ?? 90),
                    $remain
                );
                $collExtra = self::runCollectAfterStem($opts, $dayRange, $adminFilter, $processed);
                if ($collExtra !== null) {
                    $extra['collect'] = $collExtra;
                }
            } elseif ($collect && $timedOut) {
                $extra['collect_skipped'] = 'stem_budget_exhausted';
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, '1317') !== false || stripos($msg, 'interrupted') !== false) {
                $extra['error'] = 'query_interrupted';
                return [
                    'ok' => true,
                    'processed' => $processed,
                    'skipped' => $skipped,
                    'message' => 'stem_partial_timeout',
                    'extra' => $extra,
                ];
            }
            throw $e;
        }

        $message = $timedOut ? 'stem_partial_timeout' : 'stem_done';
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
        $budget = (int)($opts['collect_time_budget_sec'] ?? 90);
        $budget = max(10, min(300, $budget > 0 ? $budget : 90));
        $window = ['time_budget_sec' => $budget];
        if ($dayRange !== null) {
            $dayKey = date('Y-m-d', $dayRange['start']);
            $onlyAdmins = self::adminIdsForDay($dayKey);
            $window['start'] = $dayRange['start'];
            $window['end'] = $dayRange['end'];
        } elseif ($adminFilter > 0) {
            $onlyAdmins = [$adminFilter];
        } elseif (!$fullCollect && $processed > 0) {
            // Idle stem after ASR: limit collect to admins touched in the recent lookback
            // so we never open a full-table orphan sweep / all-admins rebuild.
            $onlyAdmins = self::adminIdsForDay(date('Y-m-d'));
            if ($onlyAdmins === []) {
                $onlyAdmins = self::adminIdsForDay(date('Y-m-d', time() - 86400));
            }
            $dayStart = strtotime(date('Y-m-d 00:00:00'));
            $window['start'] = (int)$dayStart;
            $window['end'] = (int)$dayStart + 86400 - 1;
        }

        $coll = CollectRunner::run($fullCollect && $onlyAdmins === null, $onlyAdmins, $window);
        $out = [
            'ok' => !empty($coll['ok']),
            'message' => (string)($coll['message'] ?? ''),
            'processed' => (int)($coll['processed'] ?? 0),
            'collections' => (int)(($coll['extra']['collections'] ?? 0)),
        ];
        if ($dayRange !== null) {
            $disabled = self::disableOutsideCoverageAfterCollect(
                $dayRange['start'],
                $dayRange['end'],
                $adminFilter > 0 ? $adminFilter : 0
            );
            if ($disabled > 0) {
                $out['disabled_records'] = $disabled;
            }
        }

        return $out;
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
     * Re-enable DISABLED records for staff with DISABLE_NON_PROCEDURE_TIME
     * so stem/collect can rate the full day. Optional re-disable: disableOutsideCoverageAfterCollect.
     *
     * @deprecated Use enableDisabledCoverageRecords
     */
    public static function enableDisabledMassageRecords(int $dayStart, int $dayEnd, int $adminFilter = 0): int
    {
        return self::enableDisabledCoverageRecords($dayStart, $dayEnd, $adminFilter);
    }

    public static function enableDisabledCoverageRecords(int $dayStart, int $dayEnd, int $adminFilter = 0): int
    {
        if ($dayStart < 1 || $dayEnd < $dayStart || !Database::tableExists('base_records')) {
            return 0;
        }
        $adminIds = self::coverageAdminIds();
        if ($adminIds === []) {
            return 0;
        }
        if ($adminFilter > 0) {
            if (!in_array($adminFilter, $adminIds, true)) {
                return 0;
            }
            $adminIds = [$adminFilter];
        }
        $in = implode(',', array_map('intval', $adminIds));
        $st = Database::pdo()->prepare(
            "UPDATE base_records SET DISABLED = 0
             WHERE DISABLED = 1
               AND ID_ADMIN IN ($in)
               AND DT_RECORDED > ?
               AND DT_RECORDED < ?"
        );
        $st->execute([$dayStart, $dayEnd]);

        return (int)$st->rowCount();
    }

    /**
     * After collect: disable day records that do not overlap rated collections,
     * paid procedure windows (± buffer), or pending review. Setting-gated.
     */
    public static function disableOutsideCoverageAfterCollect(int $dayStart, int $dayEnd, int $adminFilter = 0): int
    {
        if ($dayStart < 1 || $dayEnd < $dayStart || !Database::tableExists('base_records')) {
            return 0;
        }
        if (!class_exists('AudioCoverageConfig', false)) {
            require_once __DIR__ . '/AudioCoverageConfig.php';
        }
        if (!class_exists('AudioCoverageProfiles', false)) {
            require_once __DIR__ . '/AudioCoverageProfiles.php';
        }
        $adminIds = self::coverageAdminIds();
        if ($adminIds === []) {
            return 0;
        }
        if ($adminFilter > 0) {
            if (!in_array($adminFilter, $adminIds, true)) {
                return 0;
            }
            $adminIds = [$adminFilter];
        }

        $disabled = 0;
        foreach ($adminIds as $aid) {
            $cfg = AudioCoverageConfig::forAdmin($aid);
            if (empty($cfg['disable_outside_coverage'])) {
                continue;
            }
            $disabled += self::disableOutsideCoverageForAdmin(
                $aid,
                $dayStart,
                $dayEnd,
                $cfg
            );
        }

        return $disabled;
    }

    /**
     * @param array{match_procedures?:bool,procedure_buffer_sec?:int,min_procedure_sec?:int} $cfg
     */
    private static function disableOutsideCoverageForAdmin(
        int $adminId,
        int $dayStart,
        int $dayEnd,
        array $cfg
    ): int {
        $procBuffer = max(0, (int)($cfg['procedure_buffer_sec'] ?? 1600));
        $minProcSec = max(0, (int)($cfg['min_procedure_sec'] ?? 360));
        $matchProc = !empty($cfg['match_procedures']);
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID, DT_RECORDED, PLAYTIME FROM base_records
             WHERE ID_ADMIN = ? AND DISABLED < 1
               AND DT_RECORDED > ? AND DT_RECORDED < ?
               AND DT_TRANSCRIBED > 1'
        );
        $st->execute([$adminId, $dayStart, $dayEnd]);
        $records = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($records === []) {
            return 0;
        }

        $keep = [];

        // Pending review («Тр.Осм»)
        if (Database::tableExists('base_records_reviews')) {
            $ids = array_map(static fn ($r) => (int)$r['ID'], $records);
            $in = implode(',', array_map('intval', $ids));
            if ($in !== '') {
                $revSql = "SELECT ID_RECORD FROM base_records_reviews
                           WHERE ID_RECORD IN ($in)
                             AND (DT_REVIEW IS NULL OR DT_REVIEW < 1)";
                foreach ($pdo->query($revSql)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
                    $keep[(int)$rid] = true;
                }
            }
        }

        // Rated collections
        $windows = [];
        if (Database::tableExists('base_records_collections')) {
            $ratedCond = Database::columnExists('base_records_collections', 'RATED')
                ? 'AND RATED = 1' : '';
            $cst = $pdo->prepare(
                "SELECT DT_START, DT_END FROM base_records_collections
                 WHERE ID_ADMIN = ? {$ratedCond}
                   AND DT_START <= ? AND (DT_END = 0 OR DT_END >= ?)"
            );
            $cst->execute([$adminId, $dayEnd, $dayStart]);
            foreach ($cst->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $ws = (int)$c['DT_START'];
                $we = (int)$c['DT_END'];
                if ($we < 1) {
                    $we = $ws;
                }
                $windows[] = [$ws, $we];
            }
        }

        // Paid procedure windows (soft procedures module; only when match_procedures on)
        if ($matchProc
            && ModuleManager::isEnabled('procedures')
            && Database::tableExists('base_procedures')
        ) {
            $opCol = Database::columnExists('base_procedures', 'ID_OPERATOR')
                ? 'ID_OPERATOR' : 'ID_ADMIN';
            $pst = $pdo->prepare(
                "SELECT DTSTART, DTEND, SUMM FROM base_procedures
                 WHERE {$opCol} = ? AND ENABLED = 1 AND SUMM > 0
                   AND DTSTART <= ? AND (DTEND = 0 OR DTEND >= ?)"
            );
            $pst->execute([$adminId, $dayEnd + $procBuffer, $dayStart - $procBuffer]);
            foreach ($pst->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                $ps = (int)$p['DTSTART'];
                $pe = (int)$p['DTEND'];
                if ($pe < 1) {
                    $pe = $ps;
                }
                $dur = max(0, $pe - $ps);
                if ($minProcSec > 0 && $dur < $minProcSec) {
                    continue;
                }
                $windows[] = [$ps - $procBuffer, $pe + $procBuffer];
            }
        }

        $toDisable = [];
        foreach ($records as $row) {
            $rid = (int)$row['ID'];
            if (isset($keep[$rid])) {
                continue;
            }
            $rs = (int)$row['DT_RECORDED'];
            $re = $rs + max(0, (int)$row['PLAYTIME']);
            $overlap = false;
            foreach ($windows as [$ws, $we]) {
                if ($rs < $we && $re > $ws) {
                    $overlap = true;
                    break;
                }
            }
            if (!$overlap) {
                $toDisable[] = $rid;
            }
        }
        if ($toDisable === []) {
            return 0;
        }
        $in = implode(',', array_map('intval', $toDisable));
        $pdo->exec("UPDATE base_records SET DISABLED = 1 WHERE ID IN ($in) AND DISABLED < 1");

        return count($toDisable);
    }

    /** @return list<int> staff with DISABLE_NON_PROCEDURE_TIME on their status */
    private static function coverageAdminIds(): array
    {
        $pdo = Database::pdo();
        if (!Database::tableExists('base_users')
            || !Database::tableExists('base_admin_statuses')
            || !Database::columnExists('base_admin_statuses', 'DISABLE_NON_PROCEDURE_TIME')
        ) {
            return [];
        }
        $statusIds = $pdo->query(
            'SELECT ID FROM base_admin_statuses WHERE DISABLE_NON_PROCEDURE_TIME = 1'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $statusIds = array_values(array_unique(array_filter(
            array_map('intval', $statusIds),
            static fn ($i) => $i > 0
        )));
        if ($statusIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $statusIds));
        $rows = $pdo->query(
            "SELECT id FROM base_users WHERE enabled = '1' AND ID_STATUS IN ($in)"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out = [];
        foreach ($rows as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $out[] = $n;
            }
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
        $dicts = [];
        $trashes = [];
        $operators = [];
        $statuses = [];

        if (Database::tableExists('base_records_keys')) {
            foreach ($pdo->query(
                'SELECT ID, NAME, ID_DICTONARY FROM base_records_keys WHERE DISABLED < 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)$row['ID'];
                $keys[$id] = $row;
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

        return compact('keys', 'dicts', 'trashes', 'operators', 'statuses');
    }

    /**
     * Load morph + dictionaries + key encodings once per request.
     *
     * @return array{
     *   version:string,
     *   morph_message:string,
     *   morphy:object,
     *   refs:array,
     *   lemmatizer:StemLemmatizer,
     *   key_build:array{encoded:array,max_length:int,min_length:int}
     * }|null
     */
    private static function prepareRunContext(): ?array
    {
        /** @var array{bad_lem_words:array<string,string>,own_names:array<string,int>} $dict */
        $dict = require base_path('modules/audio_pipeline/lib/stem_dict_data.php');
        $badLem = is_array($dict['bad_lem_words'] ?? null) ? $dict['bad_lem_words'] : [];
        $ownNames = is_array($dict['own_names'] ?? null) ? $dict['own_names'] : [];
        $version = StemCache::buildVersion($badLem, $ownNames);
        StemCache::setVersion($version);

        if (self::$prepared !== null && self::$prepared['version'] === $version) {
            return self::$prepared;
        }

        $morphMessage = '';
        $morphy = self::resolveMorphy($morphMessage);
        if ($morphy === null) {
            self::$prepared = null;
            return null;
        }

        $refs = self::loadReferenceData();
        $lemmatizer = new StemLemmatizer($badLem, $ownNames, $morphy);
        $keyBuild = self::buildKeyArrayEncoded($refs['keys'], $lemmatizer);
        self::$prepared = [
            'version' => $version,
            'morph_message' => $morphMessage,
            'morphy' => $morphy,
            'refs' => $refs,
            'lemmatizer' => $lemmatizer,
            'key_build' => $keyBuild,
        ];
        return self::$prepared;
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
    private static function flushPendingTrashCandidates(): int
    {
        if (self::$pendingTrashCandidates === []) {
            return 0;
        }
        $cands = array_values(self::$pendingTrashCandidates);
        self::$pendingTrashCandidates = [];
        if (!class_exists('RecordsTrashesRepository', false)) {
            return 0;
        }
        return RecordsTrashesRepository::ensureMany($cands);
    }

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
        // Oper parity + TranscriptHighlighter: STRING_NUM is 1-based explode index
        // (unset keeps keys 1..N; array_shift would reindex to 0 and shift all highlights).
        unset($transArray[0]);
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

            if (class_exists('TranscriptGarbage', false)) {
                $g = TranscriptGarbage::analyzeLine($stringPureText, $trashes);
                if ($g['trash']) {
                    if ($g['candidate'] !== '' && $g['reason'] !== 'phrase') {
                        self::$pendingTrashCandidates[$g['candidate']] = $g['candidate'];
                    }
                    continue;
                }
            } else {
                $trashFound = false;
                foreach ($trashes as $trashSample) {
                    if ($trashSample !== '' && strpos($stringPureText, $trashSample) !== false) {
                        $trashFound = true;
                        break;
                    }
                }
                if (!$trashFound && mb_strlen($stringPureText, 'UTF-8') > 500) {
                    $trashFound = true;
                }
                if ($trashFound) {
                    continue;
                }
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
                    // New queue row only; never clear DT_REVIEW / DT_SELECT on existing rows.
                    // Favorite-only rows (DT_CREATE=0) get queued without wiping inspection.
                    $pdo->prepare(
                        'INSERT INTO base_records_reviews (ID_RECORD, ID_REVIEWER, DT_CREATE, DT_REVIEW, DT_SELECT)
                         VALUES (?, 0, ?, 0, 0)
                         ON DUPLICATE KEY UPDATE
                           DT_CREATE = IF(DT_CREATE < 1, VALUES(DT_CREATE), DT_CREATE)'
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

        // SCORE: CollectRunner after stem. KEY_TYPES: denormalized ##dictId-diversity-instances##
        // (legacy column name; do not recompute on read — docs/agents/audio.md).
        unset($refs);
    }

    /**
     * @param array<int, array<int, int>> $keysByDictArray dictId => [keyId => count]
     * @param array<string, mixed> $refs
     */
    private static function recordNeedsReview(array $keysByDictArray, array $refs): bool
    {
        $keys = $refs['keys'] ?? [];
        $dicts = $refs['dicts'] ?? [];
        foreach ($keysByDictArray as $keyData) {
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
     * @param array<int, array<int, int>> $keysByDictArray
     */
    private static function formatKeyTypes(array $keysByDictArray): string
    {
        $keyTypeText = '';
        foreach ($keysByDictArray as $dictId => $keyData) {
            $keysAmountOfType = count($keyData);
            $keysAmount = 0;
            foreach ($keyData as $keyAmount) {
                $keysAmount += (int)$keyAmount;
            }
            $keyTypeText .= '#' . (int)$dictId . '-' . $keysAmountOfType . '-' . $keysAmount . '#';
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
     *   keysByTypeArray:array<int, array<int, array<int,int>>>  (keyed by dictionary id)
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
            $offers[(int)$recordId] = 0;
            foreach ($record as $lineNumber => $line) {
                $lineIds = array_values($line);
                $i = 0;

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
                        $dictId = (int)($keyMeta['ID_DICTONARY'] ?? 0);
                        $dictRow = $refs['dicts'][$dictId] ?? null;
                        $dictonaryPositionValue = (string)($dictRow['ID_POSITION'] ?? '0');
                        $canBeAddedByPosition = self::isRecordPositionGlobal($dictonaryPositionValue);
                        $recordAdminId = (int)($records['Admins'][$recordId] ?? 0);
                        if (!$canBeAddedByPosition && $recordAdminId > 0) {
                            $recordPositionId = (int)($refs['operators'][$recordAdminId]['ID_STATUS'] ?? 0);
                            $recordAllowedPositions = self::getEquivalentAudioPositions($recordPositionId, $refs['statuses']);
                            $recordAllowedPositions[] = 0;
                            if (self::isRecordPositionAllowed($dictonaryPositionValue, $recordAllowedPositions)) {
                                $canBeAddedByPosition = true;
                            }
                        }

                        if ($canBeAddedByPosition) {
                            if ($dictId > 0) {
                                if (!isset($keysByTypeArray[$recordId][$dictId])) {
                                    $keysByTypeArray[$recordId][$dictId] = [];
                                }
                                if (!isset($keysByTypeArray[$recordId][$dictId][$phraseKey])) {
                                    $keysByTypeArray[$recordId][$dictId][$phraseKey] = 0;
                                }
                                $keysByTypeArray[$recordId][$dictId][$phraseKey]++;
                            }
                            $instnances[$recordId][$lineNumber][$phraseKey][] = range($i, $i + $length - 1);
                            $founds++;
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
