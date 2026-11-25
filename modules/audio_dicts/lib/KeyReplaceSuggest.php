<?php
declare(strict_types=1);

/**
 * Fast replacement hint for an ASR variant key (no LLM, no latency).
 * Candidates: canonical keys of the same dictionary only (enabled, not variants themselves).
 * The hint is stored as REPLACE_SUGGEST / state `suggested`; it is never applied without a human.
 */
final class KeyReplaceSuggest
{
    /** Minimum normalized similarity (0..1) to offer a candidate. */
    public const MIN_SCORE = 0.6;

    /**
     * @return array{id:int,name:string,score:float}|null
     */
    public static function heuristic(int $dictId, string $name, int $selfId = 0): ?array
    {
        if ($dictId < 1 || !RecordsKeysRepository::hasReplaceColumns()) {
            return null;
        }
        $needle = RecordsKeysRepository::normalizeName($name);
        if (mb_strlen($needle, 'UTF-8') < 4) {
            return null;
        }
        $needleTokens = self::prefixes($needle);
        if ($needleTokens === []) {
            return null;
        }
        $best = null;
        foreach (self::canonicalPool($dictId, $selfId) as $cand) {
            $norm = (string)$cand['norm'];
            if ($norm === $needle) {
                continue;
            }
            $la = mb_strlen($needle, 'UTF-8');
            $lb = mb_strlen($norm, 'UTF-8');
            if ($lb < 4 || abs($la - $lb) > 0.4 * max($la, $lb)) {
                continue;
            }
            // Cheap gate: the two phrases must share a word stem (first 4 chars of some token).
            if (array_intersect($needleTokens, self::prefixes($norm)) === []) {
                continue;
            }
            $score = self::ratio($needle, $norm);
            if ($score >= self::MIN_SCORE && ($best === null || $score > $best['score'])) {
                $best = ['id' => (int)$cand['id'], 'name' => (string)$cand['name'], 'score' => round($score, 3)];
            }
        }

        return $best;
    }

    /**
     * Alternatives for the review popup: canonical keys of the same dictionary.
     * Keys already used as canonical (have variants) first; inside each group by similarity, then name.
     * `$q` narrows by a substring of the normalized name.
     *
     * @return list<array{id:int,name:string,variants:int,score:float}>
     */
    public static function alternatives(int $keyId, string $q = '', int $limit = 40): array
    {
        $key = RecordsKeysRepository::find($keyId);
        if ($key === null || !RecordsKeysRepository::hasReplaceColumns()) {
            return [];
        }
        $dictId = (int)$key['id_dictonary'];
        $needle = RecordsKeysRepository::normalizeName((string)$key['name']);
        $qn = RecordsKeysRepository::normalizeName($q);

        $st = Database::pdo()->prepare(
            'SELECT ID_REPLACE AS id, COUNT(*) AS c FROM base_records_keys
             WHERE ID_DICTONARY = ? AND ID_REPLACE > 0 GROUP BY ID_REPLACE'
        );
        $st->execute([$dictId]);
        $variants = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $variants[(int)$r['id']] = (int)$r['c'];
        }

        $out = [];
        foreach (self::canonicalPool($dictId, $keyId) as $cand) {
            $norm = (string)$cand['norm'];
            if ($qn !== '' && mb_strpos($norm, $qn, 0, 'UTF-8') === false) {
                continue;
            }
            $out[] = [
                'id' => (int)$cand['id'],
                'name' => (string)$cand['name'],
                'variants' => $variants[(int)$cand['id']] ?? 0,
                'score' => round(self::ratio($needle, $norm), 3),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if (($a['variants'] > 0) !== ($b['variants'] > 0)) {
                return $a['variants'] > 0 ? -1 : 1;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp($a['name'], $b['name']);
        });

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Canonical keys of the dictionary, normalized once per request.
     *
     * @return list<array{id:int,name:string,norm:string}>
     */
    private static function canonicalPool(int $dictId, int $selfId): array
    {
        static $cache = [];
        $key = $dictId . ':' . $selfId;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $out = [];
        foreach (RecordsKeysRepository::canonicalCandidates($dictId, $selfId, 5000) as $c) {
            $norm = RecordsKeysRepository::normalizeName((string)$c['name']);
            if ($norm !== '') {
                $out[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'norm' => $norm];
            }
        }
        $cache[$key] = $out;

        return $out;
    }

    /** @return list<string> first 4 chars of each word of length >= 3 */
    private static function prefixes(string $norm): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (mb_strlen($w, 'UTF-8') >= 3) {
                $out[] = mb_substr($w, 0, 4, 'UTF-8');
            }
        }

        return array_values(array_unique($out));
    }

    /** 1 - Levenshtein / max length, on characters (UTF-8 safe). */
    private static function ratio(string $a, string $b): float
    {
        $ca = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cb = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $la = count($ca);
        $lb = count($cb);
        if ($la === 0 || $lb === 0) {
            return 0.0;
        }
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $ca[$i - 1] === $cb[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }

        return 1.0 - ($prev[$lb] / max($la, $lb));
    }
}
