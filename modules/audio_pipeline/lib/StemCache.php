<?php
declare(strict_types=1);

/**
 * Stem cache: words and keys in MySQL; line bundles per badge/day as JSON files.
 */
final class StemCache
{
    private const TABLE_WORD = 'base_records_stem_word_cache';
    private const TABLE_KEY = 'base_records_stem_key_cache';

    private static string $version = '';

    /** @var array<string, array{lemma:string,no_stem:int}> */
    private static array $wordRun = [];

    /** @var array<string, array{lemma:string,no_stem:int}> */
    private static array $wordPending = [];

    /** @var array<string, array<string, mixed>> */
    private static array $badgeBundles = [];

    /** @var array<string, array{badge_id:int,day_ts:int,record_ids:list<int>,scope_hash?:string}> */
    private static array $badgeDayScopes = [];

    /** @var array<string, int> */
    private static array $stats = [
        'word_hit' => 0,
        'word_miss' => 0,
        'word_mem_hit' => 0,
        'word_db_hit' => 0,
        'word_flushed' => 0,
        'line_hit' => 0,
        'line_miss' => 0,
        'line_hit_badge' => 0,
        'badge_bundle_load' => 0,
        'badge_bundle_save' => 0,
        'key_hit' => 0,
        'key_miss' => 0,
    ];

    public static function setVersion(string $version): void
    {
        self::$version = $version;
    }

    public static function getVersion(): string
    {
        return self::$version;
    }

    /**
     * @param array<string, string> $badLemWords
     * @param array<string, int> $ownNames
     */
    public static function buildVersion(array $badLemWords, array $ownNames): string
    {
        $parts = [
            'v4_badge',
            md5(serialize($badLemWords)),
            md5(serialize($ownNames)),
            'phpmorphy_ru',
            'lingua_stem_ru',
        ];

        return md5(implode('|', $parts));
    }

    public static function statsText(): string
    {
        $s = self::$stats;

        return ' stemCache: words hit=' . $s['word_hit'] . ' miss=' . $s['word_miss']
            . ' mem=' . $s['word_mem_hit'] . ' db=' . $s['word_db_hit'] . ' flushed=' . $s['word_flushed']
            . ', lines hit=' . $s['line_hit'] . ' miss=' . $s['line_miss']
            . ' (badge=' . $s['line_hit_badge']
            . ' loads=' . $s['badge_bundle_load'] . ' saves=' . $s['badge_bundle_save'] . ')'
            . ', keys hit=' . $s['key_hit'] . ' miss=' . $s['key_miss'];
    }

    public static function initRun(): void
    {
        self::$wordRun = [];
        self::$wordPending = [];
        self::$badgeBundles = [];
        self::$badgeDayScopes = [];
    }

    public static function wordHash(string $word): string
    {
        return md5(mb_strtoupper(trim($word), 'UTF-8'));
    }

    public static function lineHash(string $lineText): string
    {
        return md5($lineText . self::getVersion());
    }

    public static function keyNameHash(string $keyText): string
    {
        $keyText = str_replace('**', ' ', $keyText);

        return md5(trim($keyText));
    }

    public static function keyTextForStem($kData): string
    {
        if (is_array($kData)) {
            foreach ($kData as $alt) {
                $alt = trim(str_replace('**', ' ', (string)$alt));
                if ($alt !== '') {
                    return $alt;
                }
            }

            return '';
        }

        return is_string($kData) ? $kData : '';
    }

    /**
     * @param array<int, string> $lemmas
     * @param array<int, int> $starts
     * @param array<int, int> $lengths
     * @param array<string, int> $stemWords
     * @return array{contentArray:array<int,int>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>}
     */
    public static function lemmasToContentArrays(array $lemmas, array $starts, array $lengths, array &$stemWords): array
    {
        $contentArray = [];
        foreach ($lemmas as $i => $lemma) {
            if (!isset($stemWords[$lemma])) {
                $stemWords[$lemma] = count($stemWords) + 1;
            }
            $contentArray[$i] = $stemWords[$lemma];
        }

        return [
            'contentArray' => $contentArray,
            'contentStartArray' => $starts,
            'contentLengthArray' => $lengths,
        ];
    }

    public static function linesBaseDir(): string
    {
        require_once base_path('modules/audio/lib/AudioSettingsRepository.php');
        $cfg = AudioSettingsRepository::storageConfig();
        $dir = trim((string)($cfg['stem_cache_dir'] ?? ''));
        if ($dir === '') {
            $dir = (string)Config::get('audio', 'stem_cache_dir', 'var/audio_stem_cache');
        }
        if ($dir !== '' && $dir[0] !== '/') {
            $dir = base_path($dir);
        }

        return rtrim($dir, '/') . '/lines';
    }

    private static function lineDateSegments(int $recordDate): string
    {
        if ($recordDate < 1) {
            return '';
        }

        return date('Y/m/d', $recordDate);
    }

    public static function dayStartTs(int $dtRecorded): int
    {
        if ($dtRecorded < 1) {
            return 0;
        }

        return (int)mktime(0, 0, 0, (int)date('n', $dtRecorded), (int)date('j', $dtRecorded), (int)date('Y', $dtRecorded));
    }

    /**
     * @param list<int> $recordIds
     */
    public static function buildRecordScopeHash(array $recordIds): string
    {
        if ($recordIds === []) {
            return '';
        }
        $ids = array_values(array_unique(array_map('intval', $recordIds)));
        sort($ids, SORT_NUMERIC);

        return md5(implode(',', $ids));
    }

    /**
     * @param array<int|string, array<string, mixed>> $recordsById
     */
    public static function registerScopesFromRecords(array $recordsById): void
    {
        self::$badgeDayScopes = [];
        foreach ($recordsById as $recordId => $row) {
            $badgeId = isset($row['ID_DICTOPHONE']) ? (int)$row['ID_DICTOPHONE'] : 0;
            if ($badgeId < 1) {
                continue;
            }
            $dayTs = self::dayStartTs((int)($row['DT_RECORDED'] ?? 0));
            if ($dayTs < 1) {
                continue;
            }
            $scopeKey = $badgeId . '_' . $dayTs;
            if (!isset(self::$badgeDayScopes[$scopeKey])) {
                self::$badgeDayScopes[$scopeKey] = [
                    'badge_id' => $badgeId,
                    'day_ts' => $dayTs,
                    'record_ids' => [],
                ];
            }
            self::$badgeDayScopes[$scopeKey]['record_ids'][] = (int)$recordId;
        }
        foreach (self::$badgeDayScopes as $scopeKey => $scope) {
            self::$badgeDayScopes[$scopeKey]['scope_hash'] = self::buildRecordScopeHash($scope['record_ids']);
        }
    }

    private static function badgeScopeKey(int $badgeId, int $recordDate): string
    {
        $dayTs = self::dayStartTs($recordDate);
        if ($badgeId < 1 || $dayTs < 1) {
            return '';
        }

        return $badgeId . '_' . $dayTs;
    }

    /**
     * @return array{record_ids:list<int>,scope_hash:string}
     */
    private static function getBadgeDayScope(int $badgeId, int $recordDate): array
    {
        $scopeKey = self::badgeScopeKey($badgeId, $recordDate);
        if ($scopeKey === '' || !isset(self::$badgeDayScopes[$scopeKey])) {
            return ['record_ids' => [], 'scope_hash' => ''];
        }

        return [
            'record_ids' => self::$badgeDayScopes[$scopeKey]['record_ids'],
            'scope_hash' => (string)(self::$badgeDayScopes[$scopeKey]['scope_hash'] ?? ''),
        ];
    }

    private static function ensureLineDir(string $filePath): bool
    {
        $dir = dirname($filePath);
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0755, true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{lemmas:array<int,string>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>}|false
     */
    private static function normalizeLineEntry(array $data)
    {
        if (!isset($data['lemmas']) || !is_array($data['lemmas'])) {
            return false;
        }

        return [
            'lemmas' => $data['lemmas'],
            'contentStartArray' => isset($data['starts']) && is_array($data['starts']) ? $data['starts'] : [],
            'contentLengthArray' => isset($data['lengths']) && is_array($data['lengths']) ? $data['lengths'] : [],
        ];
    }

    /**
     * @return string|false
     */
    private static function badgeBundlePath(int $badgeId, int $recordDate)
    {
        if ($badgeId < 1 || $recordDate < 1) {
            return false;
        }
        $dateSeg = self::lineDateSegments($recordDate);
        if ($dateSeg === '') {
            return false;
        }

        return self::linesBaseDir() . '/' . $dateSeg . '/badge_' . $badgeId . '.json';
    }

    public static function loadBadgeBundle(int $badgeId, int $recordDate): bool
    {
        $scopeKey = self::badgeScopeKey($badgeId, $recordDate);
        if ($scopeKey === '') {
            return false;
        }
        if (isset(self::$badgeBundles[$scopeKey])) {
            return true;
        }
        $dayScope = self::getBadgeDayScope($badgeId, $recordDate);
        $bundle = [
            'badge_id' => $badgeId,
            'recordDate' => self::dayStartTs($recordDate),
            'scope_hash' => $dayScope['scope_hash'],
            'record_ids' => $dayScope['record_ids'],
            'lines' => [],
            'dirty' => 0,
        ];
        $path = self::badgeBundlePath($badgeId, $recordDate);
        if ($path !== false && is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['version']) && $data['version'] === self::getVersion()) {
                    if (isset($data['lines']) && is_array($data['lines'])) {
                        $bundle['lines'] = $data['lines'];
                    }
                    if (isset($data['record_ids']) && is_array($data['record_ids'])) {
                        $bundle['record_ids'] = array_map('intval', $data['record_ids']);
                    }
                    if (!empty($data['scope_hash'])) {
                        $bundle['scope_hash'] = (string)$data['scope_hash'];
                    }
                }
            }
            self::$stats['badge_bundle_load']++;
        }
        self::$badgeBundles[$scopeKey] = $bundle;

        return true;
    }

    private static function syncBadgeBundleScope(int $badgeId, int $recordDate): void
    {
        $scopeKey = self::badgeScopeKey($badgeId, $recordDate);
        if ($scopeKey === '' || !isset(self::$badgeBundles[$scopeKey])) {
            return;
        }
        $dayScope = self::getBadgeDayScope($badgeId, $recordDate);
        self::$badgeBundles[$scopeKey]['scope_hash'] = $dayScope['scope_hash'];
        self::$badgeBundles[$scopeKey]['record_ids'] = $dayScope['record_ids'];
    }

    public static function flushBadgeBundle(int $badgeId, int $recordDate = 0): bool
    {
        $scopeKey = self::badgeScopeKey($badgeId, $recordDate);
        if ($badgeId < 1 || $scopeKey === '' || !isset(self::$badgeBundles[$scopeKey])) {
            return false;
        }
        $bundle = &self::$badgeBundles[$scopeKey];
        if (empty($bundle['dirty'])) {
            return true;
        }
        self::syncBadgeBundleScope($badgeId, $recordDate);
        if ($recordDate < 1) {
            $recordDate = (int)$bundle['recordDate'];
        }
        $path = self::badgeBundlePath($badgeId, $recordDate);
        if ($path === false) {
            return false;
        }
        if (!self::ensureLineDir($path)) {
            return false;
        }
        $payload = [
            'version' => self::getVersion(),
            'scope_hash' => $bundle['scope_hash'],
            'record_ids' => array_values($bundle['record_ids']),
            'lines' => $bundle['lines'],
        ];
        $ok = @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE)) !== false;
        if ($ok) {
            $bundle['dirty'] = 0;
            self::$stats['badge_bundle_save']++;
        }

        return $ok;
    }

    public static function flushAllBadgeBundles(): void
    {
        foreach (self::$badgeBundles as $bundle) {
            self::flushBadgeBundle((int)$bundle['badge_id'], (int)$bundle['recordDate']);
        }
    }

    public static function rememberWord(string $word, string $lemma, int $noStem = 0): void
    {
        $wordHash = self::wordHash($word);
        $row = [
            'lemma' => $lemma,
            'no_stem' => $noStem > 0 ? 1 : 0,
        ];
        self::$wordRun[$wordHash] = $row;
        self::$wordPending[$wordHash] = $row;
    }

    public static function flushWords(): int
    {
        if (self::$wordPending === []) {
            return 0;
        }
        $version = self::getVersion();
        if ($version === '') {
            self::$wordPending = [];

            return 0;
        }
        if (!Database::tableExists(self::TABLE_WORD)) {
            self::$wordPending = [];

            return 0;
        }

        $pdo = Database::pdo();
        $flushed = 0;
        $dt = time();
        $chunks = array_chunk(self::$wordPending, 80, true);
        $st = $pdo->prepare(
            'INSERT INTO `' . self::TABLE_WORD . '` (WORD_HASH, STEM_VERSION, LEMMA, NO_STEM, DT_UPDATED)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE LEMMA=VALUES(LEMMA), NO_STEM=VALUES(NO_STEM), DT_UPDATED=VALUES(DT_UPDATED)'
        );
        foreach ($chunks as $chunk) {
            foreach ($chunk as $wordHash => $row) {
                if ($row['lemma'] === '') {
                    continue;
                }
                $st->execute([$wordHash, $version, $row['lemma'], (int)$row['no_stem'], $dt]);
                $flushed++;
            }
        }
        self::$wordPending = [];
        self::$stats['word_flushed'] += $flushed;

        return $flushed;
    }

    /**
     * @return array{lemma:string,no_stem:bool}|false
     */
    public static function getWord(string $word)
    {
        $version = self::getVersion();
        if ($version === '') {
            return false;
        }
        $wordHash = self::wordHash($word);

        if (isset(self::$wordRun[$wordHash])) {
            self::$stats['word_hit']++;
            self::$stats['word_mem_hit']++;
            $row = self::$wordRun[$wordHash];

            return [
                'lemma' => $row['lemma'],
                'no_stem' => $row['no_stem'] > 0,
            ];
        }

        if (!Database::tableExists(self::TABLE_WORD)) {
            self::$stats['word_miss']++;

            return false;
        }

        $st = Database::pdo()->prepare(
            'SELECT LEMMA, NO_STEM FROM `' . self::TABLE_WORD . '`
             WHERE WORD_HASH = ? AND STEM_VERSION = ? LIMIT 1'
        );
        $st->execute([$wordHash, $version]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cached = [
                'lemma' => (string)$row['LEMMA'],
                'no_stem' => (int)$row['NO_STEM'] > 0,
            ];
            self::$wordRun[$wordHash] = [
                'lemma' => $cached['lemma'],
                'no_stem' => $cached['no_stem'] ? 1 : 0,
            ];
            self::$stats['word_hit']++;
            self::$stats['word_db_hit']++;

            return $cached;
        }
        self::$stats['word_miss']++;

        return false;
    }

    /**
     * @return array{lemmas:array<int,string>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>}|false
     */
    public static function getLine(string $lineText, int $recordDate = 0, int $idBadge = 0)
    {
        $lineHash = self::lineHash($lineText);
        if ($idBadge > 0 && $recordDate > 0) {
            self::loadBadgeBundle($idBadge, $recordDate);
            $scopeKey = self::badgeScopeKey($idBadge, $recordDate);
            if ($scopeKey !== '' && isset(self::$badgeBundles[$scopeKey]['lines'][$lineHash])) {
                $entry = self::normalizeLineEntry(self::$badgeBundles[$scopeKey]['lines'][$lineHash]);
                if (is_array($entry)) {
                    self::$stats['line_hit']++;
                    self::$stats['line_hit_badge']++;

                    return $entry;
                }
            }
        }
        self::$stats['line_miss']++;

        return false;
    }

    /**
     * @param array{lemmas:array<int,string>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>} $lemmaResult
     */
    public static function setLine(string $lineText, array $lemmaResult, int $recordDate = 0, int $idBadge = 0): bool
    {
        if ($idBadge < 1 || $recordDate < 1) {
            return false;
        }
        $lineHash = self::lineHash($lineText);
        self::loadBadgeBundle($idBadge, $recordDate);
        $scopeKey = self::badgeScopeKey($idBadge, $recordDate);
        if ($scopeKey === '') {
            return false;
        }
        self::$badgeBundles[$scopeKey]['lines'][$lineHash] = [
            'lemmas' => $lemmaResult['lemmas'],
            'starts' => $lemmaResult['contentStartArray'],
            'lengths' => $lemmaResult['contentLengthArray'],
        ];
        self::$badgeBundles[$scopeKey]['dirty'] = 1;
        self::syncBadgeBundleScope($idBadge, $recordDate);

        return true;
    }

    /**
     * @return array{lemmas:array<int,string>}|false
     */
    public static function getKey(int $keyId, string $nameHash)
    {
        $version = self::getVersion();
        if ($version === '' || $keyId < 1 || !Database::tableExists(self::TABLE_KEY)) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT NAME_HASH, PAYLOAD FROM `' . self::TABLE_KEY . '`
             WHERE KEY_ID = ? AND STEM_VERSION = ? LIMIT 1'
        );
        $st->execute([$keyId, $version]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            self::$stats['key_miss']++;

            return false;
        }
        if ($row['NAME_HASH'] !== $nameHash) {
            self::$stats['key_miss']++;

            return false;
        }
        $data = json_decode((string)$row['PAYLOAD'], true);
        if (!is_array($data) || !isset($data['lemmas']) || !is_array($data['lemmas'])) {
            self::$stats['key_miss']++;

            return false;
        }
        self::$stats['key_hit']++;

        return $data;
    }

    /**
     * @param array<int, string> $lemmas
     */
    public static function setKey(int $keyId, string $nameHash, array $lemmas): bool
    {
        $version = self::getVersion();
        if ($version === '' || $keyId < 1 || !Database::tableExists(self::TABLE_KEY)) {
            return false;
        }
        $payload = json_encode(['lemmas' => array_values($lemmas)], JSON_UNESCAPED_UNICODE);
        $st = Database::pdo()->prepare(
            'INSERT INTO `' . self::TABLE_KEY . '` (KEY_ID, NAME_HASH, STEM_VERSION, PAYLOAD, DT_UPDATED)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE NAME_HASH=VALUES(NAME_HASH), STEM_VERSION=VALUES(STEM_VERSION),
             PAYLOAD=VALUES(PAYLOAD), DT_UPDATED=VALUES(DT_UPDATED)'
        );

        return $st->execute([$keyId, $nameHash, $version, $payload, time()]);
    }

    public static function invalidateKey(int $keyId): bool
    {
        if ($keyId < 1 || !Database::tableExists(self::TABLE_KEY)) {
            return false;
        }
        $st = Database::pdo()->prepare('DELETE FROM `' . self::TABLE_KEY . '` WHERE KEY_ID = ?');

        return $st->execute([$keyId]);
    }
}
