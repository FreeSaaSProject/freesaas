<?php
declare(strict_types=1);

/**
 * Extract stable N-gram phrases from a day's records for one admin (recordTopper core).
 */
final class PhraseExtractor
{
    /** Max occurrence samples stored per phrase/day (recordTopper parity ~10; UI shows ≤5). */
    private const SAMPLE_CAP = 5;

    /**
     * @return list<array{
     *   hash:string,lemmas:string,words:int,example:string,count:int,
     *   records:list<int>,is_stop:bool,matched_key_id:int,
     *   samples:list<array{record_id:int,before:string,hit:string,after:string}>
     * }>
     */
    public static function extractForAdminDay(int $adminId, string $day, ?StemLemmatizer $lemmatizer = null): array
    {
        if ($adminId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
            || !Database::tableExists('base_records')
        ) {
            return [];
        }
        if ($lemmatizer === null) {
            $lemmatizer = class_exists('StemRunner', false) ? StemRunner::makeLemmatizer() : null;
        }
        if ($lemmatizer === null) {
            return [];
        }

        $dayStart = strtotime($day . ' 00:00:00');
        if ($dayStart === false) {
            return [];
        }
        $dayEnd = $dayStart + 86400 - 1;
        $n = AudioPhrasesConfig::words();
        $minInst = AudioPhrasesConfig::minInstances();
        $delta = AudioPhrasesConfig::deltaSurround();
        $stop = array_fill_keys(AudioPhrasesConfig::stopwords(), true);
        $trashes = class_exists('RecordsRepository', false)
            ? RecordsRepository::trashPhrases()
            : [];
        $autoTrash = [];

        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_RECORDED AS dt, TRANSCRIPTION AS transcription, ID_DICTOPHONE AS badge
             FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED < 1)
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION <> ""
             ORDER BY DT_RECORDED ASC
             LIMIT 500'
        );
        $st->execute([$adminId, $dayStart, $dayEnd]);
        $records = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($records === []) {
            return [];
        }

        /** @var array<string, array{lemmas:list<string>,count:int,records:array<int,true>,example:string,samples:list<array{record_id:int,before:string,hit:string,after:string}>,sample_keys:array<string,true>}> $buckets */
        $buckets = [];
        $dayTs = (int)$dayStart;

        foreach ($records as $rec) {
            $rid = (int)$rec['id'];
            $badge = (int)($rec['badge'] ?? 0);
            $lines = self::splitTranscript((string)($rec['transcription'] ?? ''));
            $prev = '';
            $prev2 = '';
            $prev3 = '';
            $lineNo = 0;
            foreach ($lines as $line) {
                $lineNo++;
                $text = $line['text'];
                if ($text === '' || $text === $prev || $text === $prev2 || $text === $prev3) {
                    continue;
                }
                // Filter ASR junk / dictionary trash before lemmatize + N-grams.
                if (class_exists('TranscriptGarbage', false)) {
                    $g = TranscriptGarbage::analyzeLine($text, $trashes);
                    if ($g['trash']) {
                        if ($g['candidate'] !== '' && $g['reason'] !== 'phrase') {
                            $autoTrash[$g['candidate']] = $g['candidate'];
                        }
                        continue;
                    }
                } elseif (mb_strlen($text, 'UTF-8') > 500) {
                    continue;
                }
                // Prefer line cache lemmas (strings), not stemWord IDs from contentArray.
                $lemmaResult = self::lemmasForLine($lemmatizer, $text, $dayTs, $badge);
                if ($lemmaResult === null || ($lemmaResult['lemmas'] ?? []) === []) {
                    continue;
                }
                /** @var list<string> $lemmas */
                $lemmas = [];
                foreach ($lemmaResult['lemmas'] as $tok) {
                    $tok = mb_strtolower(trim((string)$tok), 'UTF-8');
                    if ($tok !== '') {
                        $lemmas[] = $tok;
                    }
                }
                $starts = $lemmaResult['contentStartArray'] ?? [];
                $lengths = $lemmaResult['contentLengthArray'] ?? [];
                $len = count($lemmas);
                if ($len < $n) {
                    if ($prev2 !== '') {
                        $prev3 = $prev2;
                    }
                    if ($prev !== '') {
                        $prev2 = $prev;
                    }
                    $prev = $text;
                    continue;
                }
                for ($i = 0; $i <= $len - $n; $i++) {
                    $slice = array_slice($lemmas, $i, $n);
                    if (class_exists('TranscriptGarbage', false)
                        && (TranscriptGarbage::isRepeatedLemmaSlice($slice)
                            || TranscriptGarbage::isNumericLemmaSlice($slice))
                    ) {
                        continue;
                    }
                    $sorted = $slice;
                    sort($sorted, SORT_STRING);
                    $hash = sha1(implode('|', $sorted));
                    if (!isset($buckets[$hash])) {
                        $example = self::surfaceSlice($text, $starts, $lengths, $i, $n, $delta);
                        if ($example === '') {
                            $example = implode(' ', $slice);
                        }
                        $buckets[$hash] = [
                            'lemmas' => $slice,
                            'count' => 0,
                            'records' => [],
                            'example' => $example,
                            'samples' => [],
                            'sample_keys' => [],
                        ];
                    }
                    $buckets[$hash]['count']++;
                    $buckets[$hash]['records'][$rid] = true;
                    if ($buckets[$hash]['example'] === '') {
                        $buckets[$hash]['example'] = implode(' ', $slice);
                    }
                    if (count($buckets[$hash]['samples']) < self::SAMPLE_CAP) {
                        // Prefer one sample per record (avoid sliding N-grams on the same line).
                        $ridKey = 'r:' . $rid;
                        if (isset($buckets[$hash]['sample_keys'][$ridKey])) {
                            continue;
                        }
                        $occ = self::occurrenceParts($text, $starts, $lengths, $i, $n);
                        if ($occ !== null) {
                            $buckets[$hash]['sample_keys'][$ridKey] = true;
                            $buckets[$hash]['samples'][] = [
                                'record_id' => $rid,
                                'before' => $occ['before'],
                                'hit' => $occ['hit'],
                                'after' => $occ['after'],
                            ];
                        }
                    }
                }
                if ($prev2 !== '') {
                    $prev3 = $prev2;
                }
                if ($prev !== '') {
                    $prev2 = $prev;
                }
                $prev = $text;
            }
        }

        // Keys allowed for this admin's position (exact + containment match).
        $keyIndex = self::keyMatchIndex($adminId);
        $out = [];
        foreach ($buckets as $hash => $b) {
            if ((int)$b['count'] < $minInst) {
                continue;
            }
            $isStop = true;
            foreach ($b['lemmas'] as $w) {
                if (!isset($stop[$w])) {
                    $isStop = false;
                    break;
                }
            }
            if ($stop === []) {
                $isStop = false;
            }
            $example = mb_substr((string)$b['example'], 0, 1000);
            $matchedKey = self::matchKeyId($b['lemmas'], $example, $keyIndex);
            $out[] = [
                'hash' => $hash,
                'lemmas' => implode(' ', $b['lemmas']),
                'words' => count($b['lemmas']),
                'example' => $example,
                'count' => (int)$b['count'],
                'records' => array_map('intval', array_keys($b['records'])),
                'is_stop' => $isStop,
                'matched_key_id' => $matchedKey,
                'samples' => $b['samples'] ?? [],
            ];
        }

        // Fold longer surface variants into a shorter sense-root (same day harvest).
        $out = self::collapseExtensions($out);
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        // Auto-fill trash dictionary so stem/collect/UI share the same needles.
        if ($autoTrash !== [] && class_exists('RecordsTrashesRepository', false)) {
            RecordsTrashesRepository::ensureMany(array_values($autoTrash));
        }

        return $out;
    }

    /**
     * @return array{lemmas:array<int,string>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>}|null
     */
    private static function lemmasForLine(StemLemmatizer $lemmatizer, string $text, int $recordDate, int $badgeId): ?array
    {
        $cached = StemCache::getLine($text, $recordDate, $badgeId);
        if (is_array($cached) && isset($cached['lemmas']) && is_array($cached['lemmas'])) {
            return [
                'lemmas' => $cached['lemmas'],
                'contentStartArray' => is_array($cached['contentStartArray'] ?? null)
                    ? $cached['contentStartArray'] : [],
                'contentLengthArray' => is_array($cached['contentLengthArray'] ?? null)
                    ? $cached['contentLengthArray'] : [],
            ];
        }
        $lemmaResult = $lemmatizer->stringProcessLemmas($text, 0);
        if ($lemmaResult === false) {
            return null;
        }
        StemCache::setLine($text, $lemmaResult, $recordDate, $badgeId);
        return $lemmaResult;
    }

    /**
     * Surface-form snippet around N-gram from original line offsets.
     *
     * @param array<int,int> $starts
     * @param array<int,int> $lengths
     */
    private static function surfaceSlice(
        string $text,
        array $starts,
        array $lengths,
        int $i,
        int $n,
        int $delta
    ): string {
        $fromIdx = max(0, $i - $delta);
        $toIdx = $i + $n - 1 + $delta;
        if (!isset($starts[$fromIdx], $lengths[$toIdx]) && !isset($starts[$i], $lengths[$i + $n - 1])) {
            return '';
        }
        $start = (int)($starts[$fromIdx] ?? $starts[$i] ?? 0);
        $endIdx = isset($starts[$toIdx], $lengths[$toIdx]) ? $toIdx : ($i + $n - 1);
        $end = (int)($starts[$endIdx] ?? 0) + (int)($lengths[$endIdx] ?? 0);
        if ($end <= $start) {
            return '';
        }
        return trim(mb_substr($text, $start, $end - $start, 'UTF-8'));
    }

    /**
     * Full-line occurrence with hit span for UI highlight (oper recordTopper style).
     *
     * @param array<int,int> $starts
     * @param array<int,int> $lengths
     * @return array{before:string,hit:string,after:string}|null
     */
    private static function occurrenceParts(
        string $text,
        array $starts,
        array $lengths,
        int $i,
        int $n
    ): ?array {
        $last = $i + $n - 1;
        if (!isset($starts[$i], $lengths[$last])) {
            return null;
        }
        $hitStart = (int)$starts[$i];
        $hitEnd = (int)$starts[$last] + (int)$lengths[$last];
        if ($hitEnd <= $hitStart) {
            return null;
        }
        $before = mb_substr($text, 0, $hitStart, 'UTF-8');
        $hit = mb_substr($text, $hitStart, $hitEnd - $hitStart, 'UTF-8');
        $after = mb_substr($text, $hitEnd, null, 'UTF-8');
        if (trim($hit) === '') {
            return null;
        }
        return [
            'before' => $before,
            'hit' => $hit,
            'after' => $after,
        ];
    }

    /**
     * @return list<array{text:string}>
     */
    private static function splitTranscript(string $src): array
    {
        $src = str_replace(["\r\n", "\r"], "\n", $src);
        $parts = preg_split('/\n+/', $src) ?: [];
        $out = [];
        foreach ($parts as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\[[^\]]*\](.*)$/u', $line, $m)) {
                $text = trim((string)$m[1]);
            } else {
                $text = $line;
            }
            if ($text !== '') {
                $out[] = ['text' => $text];
            }
        }
        return $out;
    }

    /**
     * Keys whose dictionary matches admin audio position (or global dicts).
     * Full catalog is lemmatized once per request; per-admin views only filter by dict.
     *
     * @return array{
     *   by_sorted: array<string,int>,
     *   keys: list<array{id:int,tokens:list<string>,norm:string,tok_n:int,norm_len:int}>
     * }
     */
    private static function keyMatchIndex(int $adminId = 0): array
    {
        static $adminCache = [];
        $cacheKey = $adminId > 0 ? $adminId : 0;
        if (isset($adminCache[$cacheKey])) {
            return $adminCache[$cacheKey];
        }
        $full = self::keyCatalogFull();
        if ($full['keys'] === [] && $full['rows'] === []) {
            return $adminCache[$cacheKey] = ['by_sorted' => [], 'keys' => []];
        }
        $allowedDictIds = null;
        if ($adminId > 0 && class_exists('RecordsDictsRepository', false)) {
            $allowedDictIds = array_fill_keys(
                array_map('intval', array_keys(RecordsDictsRepository::optionsForAdmin($adminId, false))),
                true
            );
        }
        if ($allowedDictIds === null) {
            return $adminCache[$cacheKey] = [
                'by_sorted' => $full['by_sorted'],
                'keys' => $full['keys'],
            ];
        }
        $bySorted = [];
        $keys = [];
        foreach ($full['rows'] as $row) {
            $dictId = (int)$row['id_dictonary'];
            if ($dictId > 0 && !isset($allowedDictIds[$dictId])) {
                continue;
            }
            $sortedKey = (string)$row['sorted'];
            if ($sortedKey !== '') {
                $bySorted[$sortedKey] = (int)$row['id'];
            }
            if ((int)$row['norm_len'] >= 4) {
                $keys[] = [
                    'id' => (int)$row['id'],
                    'tokens' => $row['tokens'],
                    'norm' => (string)$row['norm'],
                    'tok_n' => (int)$row['tok_n'],
                    'norm_len' => (int)$row['norm_len'],
                ];
            }
        }
        usort($keys, static function (array $a, array $b): int {
            if ($a['norm_len'] !== $b['norm_len']) {
                return $b['norm_len'] <=> $a['norm_len'];
            }
            return $b['tok_n'] <=> $a['tok_n'];
        });
        return $adminCache[$cacheKey] = ['by_sorted' => $bySorted, 'keys' => $keys];
    }

    /**
     * @return array{
     *   by_sorted: array<string,int>,
     *   keys: list<array{id:int,tokens:list<string>,norm:string,tok_n:int,norm_len:int}>,
     *   rows: list<array{id:int,id_dictonary:int,tokens:list<string>,sorted:string,norm:string,tok_n:int,norm_len:int}>
     * }
     */
    private static function keyCatalogFull(): array
    {
        static $full = null;
        if ($full !== null) {
            return $full;
        }
        $empty = ['by_sorted' => [], 'keys' => [], 'rows' => []];
        if (!Database::tableExists('base_records_keys') || !class_exists('StemRunner', false)) {
            return $full = $empty;
        }
        $lem = StemRunner::makeLemmatizer();
        if ($lem === null) {
            return $full = $empty;
        }
        $sql = 'SELECT k.ID AS id, k.NAME AS name, k.ID_DICTONARY AS id_dictonary
                FROM base_records_keys k
                WHERE k.DISABLED < 1
                LIMIT 8000';
        $st = Database::pdo()->query($sql);
        if (!$st) {
            return $full = $empty;
        }
        $bySorted = [];
        $keys = [];
        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = str_replace('**', ' ', (string)$row['name']);
            $lemmaResult = $lem->stringProcessLemmas($name, 0);
            if ($lemmaResult === false || ($lemmaResult['lemmas'] ?? []) === []) {
                continue;
            }
            $toks = [];
            foreach ($lemmaResult['lemmas'] as $t) {
                $t = mb_strtolower(trim((string)$t), 'UTF-8');
                if ($t !== '') {
                    $toks[] = $t;
                }
            }
            if ($toks === []) {
                continue;
            }
            $id = (int)$row['id'];
            $sorted = $toks;
            sort($sorted, SORT_STRING);
            $sortedKey = implode('|', $sorted);
            $bySorted[$sortedKey] = $id;
            $norm = self::normalizeSurface($name);
            $normLen = $norm !== '' ? mb_strlen($norm, 'UTF-8') : 0;
            $entry = [
                'id' => $id,
                'id_dictonary' => (int)($row['id_dictonary'] ?? 0),
                'tokens' => $toks,
                'sorted' => $sortedKey,
                'norm' => $norm,
                'tok_n' => count($toks),
                'norm_len' => $normLen,
            ];
            $rows[] = $entry;
            if ($normLen >= 4) {
                $keys[] = [
                    'id' => $id,
                    'tokens' => $toks,
                    'norm' => $norm,
                    'tok_n' => count($toks),
                    'norm_len' => $normLen,
                ];
            }
        }
        usort($keys, static function (array $a, array $b): int {
            if ($a['norm_len'] !== $b['norm_len']) {
                return $b['norm_len'] <=> $a['norm_len'];
            }
            return $b['tok_n'] <=> $a['tok_n'];
        });
        return $full = ['by_sorted' => $bySorted, 'keys' => $keys, 'rows' => $rows];
    }

    /**
     * Exact bag match, then ordered lemma containment, then surface containment in example.
     *
     * @param list<string> $lemmaTokens
     * @param array{by_sorted: array<string,int>, keys: list<array{id:int,tokens:list<string>,norm:string,tok_n:int,norm_len:int}>} $index
     */
    private static function matchKeyId(array $lemmaTokens, string $example, array $index): int
    {
        if ($lemmaTokens === [] && trim($example) === '') {
            return 0;
        }
        $sorted = $lemmaTokens;
        sort($sorted, SORT_STRING);
        $sortedKey = implode('|', $sorted);
        if ($sortedKey !== '' && isset($index['by_sorted'][$sortedKey])) {
            return (int)$index['by_sorted'][$sortedKey];
        }

        $exNorm = self::normalizeSurface($example);
        $bestId = 0;
        $bestScore = 0;
        foreach ($index['keys'] as $key) {
            $tokN = (int)$key['tok_n'];
            if ($tokN >= 2 && $lemmaTokens !== [] && self::isContiguousSubseq($key['tokens'], $lemmaTokens)) {
                $score = 1000 + $tokN * 10 + (int)$key['norm_len'];
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestId = (int)$key['id'];
                }
                continue;
            }
            if ($exNorm !== '' && (int)$key['norm_len'] >= 4
                && mb_strpos($exNorm, (string)$key['norm'], 0, 'UTF-8') !== false
            ) {
                $score = 500 + (int)$key['norm_len'];
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestId = (int)$key['id'];
                }
            }
        }
        return $bestId;
    }

    /**
     * Fold longer examples into a shorter sense-root when one surface contains the other.
     * Also fold siblings that share the same matched key into the closest-to-key row.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function collapseExtensions(array $items): array
    {
        if (count($items) < 2) {
            return $items;
        }

        // 1) Surface containment: shorter example inside longer → keep shorter root.
        $n = count($items);
        $absorbed = array_fill(0, $n, false);
        $norms = [];
        for ($i = 0; $i < $n; $i++) {
            $norms[$i] = self::normalizeSurface((string)($items[$i]['example'] ?? ''));
        }
        // Process shorter roots first so chains fold toward the sense-root.
        $order = range(0, $n - 1);
        usort($order, static function (int $a, int $b) use ($norms): int {
            $la = mb_strlen($norms[$a], 'UTF-8');
            $lb = mb_strlen($norms[$b], 'UTF-8');
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            return $a <=> $b;
        });
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
                if ($extLen <= $rootLen) {
                    continue;
                }
                // Extension must grow, but not explode into an unrelated long line.
                if (($extLen - $rootLen) > 80) {
                    continue;
                }
                if (mb_strpos($extNorm, $rootNorm, 0, 'UTF-8') === false) {
                    continue;
                }
                $items[$rootIdx] = self::mergePhraseHits($items[$rootIdx], $items[$extIdx]);
                $absorbed[$extIdx] = true;
            }
        }

        $collapsed = [];
        for ($i = 0; $i < $n; $i++) {
            if (!$absorbed[$i]) {
                $collapsed[] = $items[$i];
            }
        }

        // 2) Same matched key → keep the row whose example is closest to the key surface.
        $byKey = [];
        foreach ($collapsed as $idx => $row) {
            $kid = (int)($row['matched_key_id'] ?? 0);
            if ($kid < 1) {
                continue;
            }
            $byKey[$kid][] = $idx;
        }
        $drop = [];
        foreach ($byKey as $idxs) {
            if (count($idxs) < 2) {
                continue;
            }
            $best = $idxs[0];
            $bestLen = mb_strlen(self::normalizeSurface((string)($collapsed[$best]['example'] ?? '')), 'UTF-8');
            foreach ($idxs as $idx) {
                $len = mb_strlen(self::normalizeSurface((string)($collapsed[$idx]['example'] ?? '')), 'UTF-8');
                // Prefer shorter surface (closer to key wording).
                if ($len < $bestLen) {
                    $best = $idx;
                    $bestLen = $len;
                }
            }
            foreach ($idxs as $idx) {
                if ($idx === $best) {
                    continue;
                }
                $collapsed[$best] = self::mergePhraseHits($collapsed[$best], $collapsed[$idx]);
                $drop[$idx] = true;
            }
        }
        if ($drop === []) {
            return $collapsed;
        }
        $final = [];
        foreach ($collapsed as $idx => $row) {
            if (!isset($drop[$idx])) {
                $final[] = $row;
            }
        }
        return $final;
    }

    /**
     * @param array<string,mixed> $into
     * @param array<string,mixed> $from
     * @return array<string,mixed>
     */
    private static function mergePhraseHits(array $into, array $from): array
    {
        $into['count'] = (int)($into['count'] ?? 0) + (int)($from['count'] ?? 0);
        $recs = [];
        foreach (array_merge(
            is_array($into['records'] ?? null) ? $into['records'] : [],
            is_array($from['records'] ?? null) ? $from['records'] : []
        ) as $rid) {
            $recs[(int)$rid] = (int)$rid;
        }
        $into['records'] = array_values($recs);
        $samples = is_array($into['samples'] ?? null) ? $into['samples'] : [];
        $seen = [];
        foreach ($samples as $s) {
            if (!is_array($s)) {
                continue;
            }
            $seen['r:' . (int)($s['record_id'] ?? 0)] = true;
        }
        foreach (is_array($from['samples'] ?? null) ? $from['samples'] : [] as $s) {
            if (!is_array($s) || count($samples) >= self::SAMPLE_CAP) {
                break;
            }
            $k = 'r:' . (int)($s['record_id'] ?? 0);
            if (isset($seen[$k])) {
                continue;
            }
            $samples[] = $s;
            $seen[$k] = true;
        }
        $into['samples'] = $samples;
        if ((int)($into['matched_key_id'] ?? 0) < 1 && (int)($from['matched_key_id'] ?? 0) > 0) {
            $into['matched_key_id'] = (int)$from['matched_key_id'];
        }
        if (!empty($from['is_stop'])) {
            $into['is_stop'] = !empty($into['is_stop']);
        }
        return $into;
    }

    /** @param list<string> $needle @param list<string> $haystack */
    private static function isContiguousSubseq(array $needle, array $haystack): bool
    {
        $n = count($needle);
        $h = count($haystack);
        if ($n < 1 || $n > $h) {
            return false;
        }
        for ($i = 0; $i <= $h - $n; $i++) {
            $ok = true;
            for ($j = 0; $j < $n; $j++) {
                if ($haystack[$i + $j] !== $needle[$j]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeSurface(string $text): string
    {
        if (class_exists('RecordsKeysRepository', false)) {
            return RecordsKeysRepository::normalizeName($text);
        }
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = str_replace(['ё', 'Ё'], ['е', 'е'], $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
