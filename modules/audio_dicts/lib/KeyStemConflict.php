<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Detect stem-window "eating" conflicts before adding a key.
 * Mirrors StemRunner left-to-right shortest-first matching without changing it:
 * - shorter lemma prefix at the same start wins;
 * - a match whose span covers the start of another key blocks that key.
 *
 * Scope = dictionaries that share positions with the target dict (+ globals).
 */
final class KeyStemConflict
{
    /** @var array<string,list<array{id:int,name:string,id_dictonary:int,dict_name:string,tokens:list<string>}>> scopeKey => rows */
    private static $catalogByScope = [];

    /** @var array<int,list<int>>|null dict_id => position ids */
    private static $dictPositions = null;

    /** @var object|null|false StemLemmatizer (request-local; false = not loaded) */
    private static $lemmatizer = false;

    /** @var array<string,list<string>> phrase => tokens */
    private static $tokenCache = [];

    /**
     * @return list<array{
     *   id:int,name:string,id_dictonary:int,dict_name:string,
     *   relation:string,shared_lemmas:list<string>
     * }>
     */
    public static function find(string $phrase, int $dictId, int $excludeKeyId = 0, int $limit = 20): array
    {
        // Same tokenizer as catalogForScope (normalize-only): Morphy×N on 8k keys
        // was blowing coach verdicts / key-add preview on shared hosting.
        $tokens = self::tokenizeFast($phrase);
        if ($tokens === [] || $dictId < 1 || !Database::tableExists('base_records_keys')) {
            return [];
        }
        $scope = self::scopeDictIds($dictId);
        if ($scope === []) {
            return [];
        }
        $catalog = self::catalogForScope($scope);
        $out = [];
        $seen = [];
        foreach ($catalog as $row) {
            $kid = (int)$row['id'];
            if ($excludeKeyId > 0 && $kid === $excludeKeyId) {
                continue;
            }
            $other = is_array($row['tokens'] ?? null) ? $row['tokens'] : [];
            if ($other === []) {
                continue;
            }
            // Exact bag already handled by findOverlaps exact.
            if ($tokens === $other) {
                continue;
            }
            $hit = self::classify($tokens, $other);
            if ($hit === null) {
                continue;
            }
            $fp = $kid . '|' . $hit['relation'];
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $out[] = [
                'id' => $kid,
                'name' => (string)$row['name'],
                'id_dictonary' => (int)$row['id_dictonary'],
                'dict_name' => (string)$row['dict_name'],
                'relation' => $hit['relation'],
                'shared_lemmas' => $hit['shared'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public static function hasConflicts(string $phrase, int $dictId, int $excludeKeyId = 0): bool
    {
        return self::find($phrase, $dictId, $excludeKeyId, 1) !== [];
    }

    /** Clear request-local caches after key/dict writes. */
    public static function clearCache(): void
    {
        self::$catalogByScope = [];
        self::$dictPositions = null;
        self::$lemmatizer = false;
        self::$tokenCache = [];
    }

    /**
     * Ordered lemma/token list for a phrase (morphy when available).
     * Request-local lemmatizer + phrase cache — never call makeLemmatizer per line.
     *
     * @return list<string>
     */
    public static function tokenize(string $phrase): array
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return [];
        }
        $cacheKey = mb_substr($phrase, 0, 400);
        if (isset(self::$tokenCache[$cacheKey])) {
            return self::$tokenCache[$cacheKey];
        }
        $out = self::tokenizeUncached($phrase);
        // Cap cache so day-long transcripts cannot grow without bound.
        if (count(self::$tokenCache) < 4000) {
            self::$tokenCache[$cacheKey] = $out;
        }

        return $out;
    }

    /**
     * Fast normalize-only tokens (no Morphy). Prefer for bulk transcript scans.
     *
     * @return list<string>
     */
    public static function tokenizeFast(string $phrase): array
    {
        $norm = class_exists('RecordsKeysRepository', false)
            ? RecordsKeysRepository::normalizeName($phrase)
            : mb_strtolower(trim($phrase), 'UTF-8');
        if ($norm === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s+/u', $norm) ?: [] as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function tokenizeUncached(string $phrase): array
    {
        // Prefer pipeline lemmatizer (same as stem matching), once per request.
        if (class_exists('StemRunner', false) && class_exists('StemLemmatizer', false)) {
            try {
                if (self::$lemmatizer === false) {
                    self::$lemmatizer = StemRunner::makeLemmatizer();
                }
                $lem = self::$lemmatizer;
                if ($lem !== null) {
                    $text = str_replace('**', ' ', $phrase);
                    if (strpos($text, '|') !== false) {
                        $parts = explode('|', $text);
                        $text = implode(' ', array_map('trim', $parts));
                    }
                    if (class_exists('StemCache', false)) {
                        $text = StemCache::keyTextForStem($text);
                    }
                    $res = $lem->stringProcessLemmas($text);
                    if (is_array($res) && !empty($res['lemmas'])) {
                        $out = [];
                        foreach ($res['lemmas'] as $lemWord) {
                            $w = mb_strtolower(trim((string)$lemWord), 'UTF-8');
                            if ($w !== '') {
                                $out[] = $w;
                            }
                        }
                        if ($out !== []) {
                            return $out;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Fall through to normalize tokens.
            }
        }

        return self::tokenizeFast($phrase);
    }

    /**
     * @param list<string> $a candidate tokens
     * @param list<string> $b existing key tokens
     * @return array{relation:string,shared:list<string>}|null
     */
    private static function classify(array $a, array $b): ?array
    {
        $na = count($a);
        $nb = count($b);
        if ($na < 1 || $nb < 1) {
            return null;
        }

        // Shorter proper prefix at same start → shorter wins in stem scanner.
        if ($na < $nb && self::isPrefix($a, $b)) {
            return ['relation' => 'stem_prefix_eats', 'shared' => $a];
        }
        if ($nb < $na && self::isPrefix($b, $a)) {
            return ['relation' => 'stem_prefix_eaten', 'shared' => $b];
        }

        // Suffix of A == prefix of B → A match consumes B's start.
        $join = self::joinOverlap($a, $b);
        if ($join !== null) {
            return ['relation' => 'stem_join_eats', 'shared' => $join];
        }
        $joinRev = self::joinOverlap($b, $a);
        if ($joinRev !== null) {
            return ['relation' => 'stem_join_eaten', 'shared' => $joinRev];
        }

        return null;
    }

    /**
     * @param list<string> $prefix
     * @param list<string> $full
     */
    private static function isPrefix(array $prefix, array $full): bool
    {
        $n = count($prefix);
        if ($n < 1 || $n >= count($full)) {
            return false;
        }
        for ($i = 0; $i < $n; $i++) {
            if ($prefix[$i] !== $full[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Longest suffix of $left that equals a prefix of $right (length >= 1).
     * Ignores full equality (already skipped) and trivial single-char tokens alone when both sides longer? Keep k>=1.
     *
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>|null
     */
    private static function joinOverlap(array $left, array $right): ?array
    {
        $nl = count($left);
        $nr = count($right);
        $max = min($nl, $nr);
        // Prefer longest join; require at least 1 shared token and not the entire shorter key as pure prefix (handled above).
        for ($k = $max; $k >= 1; $k--) {
            $ok = true;
            for ($i = 0; $i < $k; $i++) {
                if ($left[$nl - $k + $i] !== $right[$i]) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            // Full containment as prefix already classified; skip identical join of whole shorter into longer start when it's pure prefix.
            if ($k === $nl && $nl < $nr && self::isPrefix($left, $right)) {
                continue;
            }
            if ($k === $nr && $nr < $nl && self::isPrefix($right, $left)) {
                continue;
            }
            // Avoid flagging when join is the entire keys equal (already skipped).
            if ($k === $nl && $k === $nr) {
                continue;
            }

            return array_slice($left, $nl - $k);
        }

        return null;
    }

    /**
     * Dict ids in position scope of $dictId (including self).
     *
     * @return array<int,true>
     */
    private static function scopeDictIds(int $dictId): array
    {
        $map = self::dictPositions();
        $targetPos = $map[$dictId] ?? [];
        $out = [];
        foreach ($map as $did => $posIds) {
            $did = (int)$did;
            if ($did < 1) {
                continue;
            }
            // Global dict (empty positions) always in scope.
            if ($posIds === []) {
                $out[$did] = true;
                continue;
            }
            // Target global → every dict (position-scoped keys still compete with globals in stem).
            if ($targetPos === []) {
                $out[$did] = true;
                continue;
            }
            foreach ($posIds as $pid) {
                if (in_array((int)$pid, $targetPos, true)) {
                    $out[$did] = true;
                    break;
                }
            }
        }
        // Always include target dict.
        $out[$dictId] = true;

        return $out;
    }

    /** @return array<int,list<int>> */
    private static function dictPositions(): array
    {
        if (self::$dictPositions !== null) {
            return self::$dictPositions;
        }
        if (class_exists('RecordsDictsRepository', false)) {
            self::$dictPositions = RecordsDictsRepository::positionMap(false);
        } else {
            self::$dictPositions = [];
        }

        return self::$dictPositions;
    }

    /**
     * Keys only for dicts in $scope (never Morphy-tokenize the whole key table).
     *
     * @param array<int,true> $scope
     * @return list<array{id:int,name:string,id_dictonary:int,dict_name:string,tokens:list<string>}>
     */
    private static function catalogForScope(array $scope): array
    {
        $ids = array_keys($scope);
        sort($ids);
        $scopeKey = implode(',', $ids);
        if (isset(self::$catalogByScope[$scopeKey])) {
            return self::$catalogByScope[$scopeKey];
        }
        $out = [];
        if ($ids === [] || !Database::tableExists('base_records_keys')) {
            self::$catalogByScope[$scopeKey] = $out;

            return $out;
        }
        // Chunk IN lists; tokenize with Morphy once per key (request-cached).
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT k.ID AS id, k.NAME AS name, k.ID_DICTONARY AS id_dictonary,
                           d.NAME AS dict_name
                    FROM base_records_keys k
                    LEFT JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY
                    WHERE k.DISABLED < 1 AND k.ID_DICTONARY IN ($placeholders)
                    LIMIT 8000";
            $st = Database::pdo()->prepare($sql);
            $st->execute($chunk);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $name = (string)($row['name'] ?? '');
                // Fast tokens are enough for prefix/join conflict heuristics and
                // avoid Morphy×N on every coach near-miss / key-add preview.
                $tokens = self::tokenizeFast($name);
                if ($tokens === []) {
                    continue;
                }
                $out[] = [
                    'id' => (int)$row['id'],
                    'name' => $name,
                    'id_dictonary' => (int)$row['id_dictonary'],
                    'dict_name' => (string)($row['dict_name'] ?? ''),
                    'tokens' => $tokens,
                ];
            }
        }
        // Cap scope caches (coach may hit several dict scopes).
        if (count(self::$catalogByScope) < 32) {
            self::$catalogByScope[$scopeKey] = $out;
        }

        return $out;
    }
}
