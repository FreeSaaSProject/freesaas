<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Classify missed red day-quality parts per rated session.
 * Deterministic first; leftovers become semantic windows for LLM.
 */
final class CoachVerdicts
{
    /**
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $facts
     * @return array{
     *   verdicts:list<array<string,mixed>>,
     *   summary:array<string,int>,
     *   asr_proposals:list<array<string,mixed>>,
     *   semantic_windows:list<array<string,mixed>>
     * }
     */
    public static function build(array $catalog, array $facts): array
    {
        $summary = [
            'no_audio' => 0,
            'session_gap' => 0,
            'stem_conflict' => 0,
            'asr_near' => 0,
            'semantic' => 0,
            'not_said' => 0,
            'total' => 0,
        ];
        $verdicts = [];
        $asrProposals = [];
        $semanticWindows = [];
        $seenAsr = [];

        $adminId = (int)($facts['admin_id'] ?? 0);
        $date = (string)($facts['date'] ?? '');
        $dicts = is_array($catalog['dicts'] ?? null) ? $catalog['dicts'] : [];
        $partsById = self::indexParts($catalog);

        $redParts = is_array($facts['quality_red'] ?? null) ? $facts['quality_red'] : [];
        foreach ($redParts as $red) {
            if (!is_array($red)) {
                continue;
            }
            $scriptId = (int)($red['script_id'] ?? 0);
            $partId = (int)($red['part_id'] ?? 0);
            $partName = (string)($red['part_name'] ?? ($partsById[$partId]['name'] ?? ('#' . $partId)));
            $scriptName = (string)($red['script_name'] ?? '');
            $dictIds = is_array($red['dict_ids'] ?? null)
                ? array_values(array_map('intval', $red['dict_ids']))
                : (is_array($partsById[$partId]['dict_ids'] ?? null)
                    ? array_values(array_map('intval', $partsById[$partId]['dict_ids']))
                    : []);
            $samples = self::samplesForDicts($dicts, $dictIds);
            $missed = is_array($red['missed_sessions'] ?? null) ? $red['missed_sessions'] : [];

            foreach ($missed as $sess) {
                if (!is_array($sess)) {
                    continue;
                }
                $row = self::classifyOne(
                    $adminId,
                    $date,
                    $scriptId,
                    $scriptName,
                    $partId,
                    $partName,
                    $dictIds,
                    $samples,
                    $sess,
                    $dicts
                );
                $verdicts[] = $row;
                $kind = (string)($row['verdict'] ?? 'not_said');
                if (isset($summary[$kind])) {
                    $summary[$kind]++;
                }
                $summary['total']++;

                if ($kind === 'asr_near' && !empty($row['candidate_phrase']) && $dictIds !== []) {
                    $phrase = trim((string)$row['candidate_phrase']);
                    $dictId = (int)($row['target_dict_id'] ?? $dictIds[0]);
                    $fp = $dictId . '|' . mb_strtolower($phrase, 'UTF-8');
                    if ($phrase !== '' && !isset($seenAsr[$fp])) {
                        $seenAsr[$fp] = true;
                        // Stem conflict already classified as stem_conflict — skip proposal.
                        $asrProposals[] = [
                            'id_script' => $scriptId,
                            'id_part' => $partId,
                            'action' => 'add_key',
                            'id_dict' => $dictId,
                            'new_dict_name' => '',
                            'phrases' => [$phrase],
                            'rationale' => (string)($row['quote'] ?? 'asr_near'),
                            'confidence' => (float)($row['confidence'] ?? 0.7),
                            'link_part' => 0,
                            'verdict' => 'asr_near',
                            'collection_id' => (int)($row['collection_id'] ?? 0),
                            'record_id' => (int)($row['record_id'] ?? 0),
                        ];
                    }
                }
                if ($kind === 'semantic' && !empty($row['window'])) {
                    $semanticWindows[] = $row['window'];
                }
            }
        }

        return [
            'verdicts' => $verdicts,
            'summary' => $summary,
            'asr_proposals' => $asrProposals,
            'semantic_windows' => $semanticWindows,
        ];
    }

    /**
     * @param array<string,mixed> $catalog
     * @return array<int,array<string,mixed>>
     */
    private static function indexParts(array $catalog): array
    {
        $out = [];
        foreach (is_array($catalog['scripts'] ?? null) ? $catalog['scripts'] : [] as $script) {
            foreach (is_array($script['parts'] ?? null) ? $script['parts'] : [] as $part) {
                $pid = (int)($part['id'] ?? 0);
                if ($pid > 0) {
                    $out[$pid] = $part;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $dicts
     * @param list<int> $dictIds
     * @return list<array{dict_id:int,phrase:string,tokens:list<string>}>
     */
    private static function samplesForDicts(array $dicts, array $dictIds): array
    {
        $out = [];
        foreach ($dictIds as $did) {
            $did = (int)$did;
            if ($did < 1) {
                continue;
            }
            $d = $dicts[(string)$did] ?? null;
            if (!is_array($d)) {
                continue;
            }
            foreach (is_array($d['sample_keys'] ?? null) ? $d['sample_keys'] : [] as $ph) {
                $ph = trim((string)$ph);
                if ($ph === '') {
                    continue;
                }
                $tokens = class_exists('KeyStemConflict', false)
                    ? KeyStemConflict::tokenizeFast($ph)
                    : self::fallbackTokens($ph);
                if ($tokens === []) {
                    continue;
                }
                $out[] = ['dict_id' => $did, 'phrase' => $ph, 'tokens' => $tokens];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $dictIds
     * @param list<array{dict_id:int,phrase:string,tokens:list<string>}> $samples
     * @param array<string,mixed> $sess
     * @param array<string,array<string,mixed>> $dicts
     * @return array<string,mixed>
     */
    private static function classifyOne(
        int $adminId,
        string $date,
        int $scriptId,
        string $scriptName,
        int $partId,
        string $partName,
        array $dictIds,
        array $samples,
        array $sess,
        array $dicts
    ): array {
        $cid = (int)($sess['collection_id'] ?? $sess['id'] ?? 0);
        $dtStart = (int)($sess['dt_start'] ?? 0);
        $dtEnd = (int)($sess['dt_end'] ?? 0);
        if ($dtEnd < 1) {
            $dtEnd = $dtStart + 600;
        }
        $base = [
            'script_id' => $scriptId,
            'script_name' => $scriptName,
            'part_id' => $partId,
            'part_name' => $partName,
            'collection_id' => $cid,
            'dt_start' => $dtStart,
            'dt_end' => $dtEnd,
            'record_id' => 0,
            'quote' => '',
            'confidence' => 0.0,
            'candidate_phrase' => '',
            'target_dict_id' => $dictIds[0] ?? 0,
            'href_record' => '',
            'href_collection' => $cid > 0 ? '/audio/collections/' . $cid : '',
        ];

        $records = self::recordsInRange($adminId, $dtStart - 30, $dtEnd + 30, 6);
        if ($records === []) {
            return array_merge($base, [
                'verdict' => 'no_audio',
                'note' => 'no overlapping transcribed records',
            ]);
        }

        // Start-gate failure / unfinished chain without mid content → session_gap.
        $chain = is_array($sess['chain'] ?? null) ? $sess['chain'] : [];
        if (self::looksLikeSessionGap($chain, $partId)) {
            return array_merge($base, [
                'verdict' => 'session_gap',
                'record_id' => (int)($records[0]['id'] ?? 0),
                'href_record' => self::recordHref((int)($records[0]['id'] ?? 0)),
                'note' => 'session incomplete or start gates unmet',
            ]);
        }

        $lines = [];
        foreach ($records as $rec) {
            foreach (self::transcriptLines((int)$rec['id']) as $line) {
                $lines[] = [
                    'record_id' => (int)$rec['id'],
                    'text' => $line,
                    'tokens' => class_exists('KeyStemConflict', false)
                        ? KeyStemConflict::tokenizeFast($line)
                        : self::fallbackTokens($line),
                ];
            }
        }
        if ($lines === []) {
            return array_merge($base, [
                'verdict' => 'no_audio',
                'record_id' => (int)($records[0]['id'] ?? 0),
                'href_record' => self::recordHref((int)($records[0]['id'] ?? 0)),
                'note' => 'empty transcript lines',
            ]);
        }

        $best = self::bestNearMiss($lines, $samples);
        if ($best !== null) {
            $dictId = (int)$best['dict_id'];
            $phrase = (string)$best['candidate'];
            // Would adding this phrase eat another dict of the same role?
            if ($dictId > 0 && class_exists('KeyStemConflict', false)
                && KeyStemConflict::hasConflicts($phrase, $dictId)
            ) {
                $conflicts = KeyStemConflict::find($phrase, $dictId, 0, 3);
                return array_merge($base, [
                    'verdict' => 'stem_conflict',
                    'record_id' => (int)$best['record_id'],
                    'href_record' => self::recordHref((int)$best['record_id']),
                    'quote' => (string)$best['quote'],
                    'candidate_phrase' => $phrase,
                    'target_dict_id' => $dictId,
                    'confidence' => (float)$best['score'],
                    'conflicts' => $conflicts,
                    'note' => 'near match but stem would eat / be eaten',
                ]);
            }

            return array_merge($base, [
                'verdict' => 'asr_near',
                'record_id' => (int)$best['record_id'],
                'href_record' => self::recordHref((int)$best['record_id']),
                'quote' => (string)$best['quote'],
                'candidate_phrase' => $phrase,
                'target_dict_id' => $dictId,
                'confidence' => (float)$best['score'],
                'note' => 'lemma near-miss vs sample',
            ]);
        }

        // Semantic only with same-line evidence vs sample keys (never whole-session
        // bag of common lemmas — that marked greetings as "цена допа").
        $sem = self::findSemanticEvidence($lines, $samples);
        if ($sem !== null) {
            $recId = (int)$sem['record_id'];
            $window = [
                'kind' => 'semantic',
                'script_id' => $scriptId,
                'script_name' => $scriptName,
                'part_ids' => [$partId],
                'record_id' => $recId,
                'collection_id' => $cid,
                'lines' => $sem['lines'],
                'hint' => 'Red day-quality part missing. Evidence lines may paraphrase sample_keys '
                    . 'for part "' . $partName . '". Propose ASR-faithful forms only; else empty.',
            ];

            return array_merge($base, [
                'verdict' => 'semantic',
                'record_id' => $recId,
                'href_record' => self::recordHref($recId),
                'quote' => (string)$sem['quote'],
                'window' => $window,
                'note' => 'same-line overlap with samples; needs LLM',
            ]);
        }

        $recId = (int)($lines[0]['record_id'] ?? 0);

        return array_merge($base, [
            'verdict' => 'not_said',
            'record_id' => $recId,
            'href_record' => self::recordHref($recId),
            // Do not dump greeting as "quote" — it misleads the operator.
            'quote' => '',
            'note' => 'no evidence of the required phrase',
        ]);
    }

    /**
     * @param list<array{part_id?:int,hit?:bool,role?:string}> $chain
     */
    private static function looksLikeSessionGap(array $chain, int $missedPartId): bool
    {
        if ($chain === []) {
            return false;
        }
        $anyHit = false;
        $triggerMiss = false;
        foreach ($chain as $chip) {
            $hit = !empty($chip['hit']);
            if ($hit) {
                $anyHit = true;
            }
            $role = (string)($chip['role'] ?? '');
            if (($role === 'trigger' || (int)($chip['start_gate'] ?? 0) > 0) && !$hit) {
                $triggerMiss = true;
            }
        }
        // Missing a mid part while triggers also missing → session structure issue.
        if ($triggerMiss && !$anyHit) {
            return true;
        }
        // Very short chain with almost nothing hit.
        $hits = 0;
        foreach ($chain as $chip) {
            if (!empty($chip['hit'])) {
                $hits++;
            }
        }
        if ($hits <= 1 && count($chain) >= 3) {
            // Only greeting-ish content — still treat as gap for late parts.
            foreach ($chain as $chip) {
                if ((int)($chip['part_id'] ?? 0) === $missedPartId && empty($chip['hit'])) {
                    $role = (string)($chip['role'] ?? 'ordinary');
                    if ($role !== 'trigger') {
                        return $hits < 2;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{record_id:int,text:string,tokens:list<string>}> $lines
     * @param list<array{dict_id:int,phrase:string,tokens:list<string>}> $samples
     * @return array{record_id:int,quote:string,candidate:string,dict_id:int,score:float}|null
     */
    private static function bestNearMiss(array $lines, array $samples): ?array
    {
        if ($samples === []) {
            return null;
        }
        $best = null;
        foreach ($lines as $line) {
            $lt = is_array($line['tokens'] ?? null) ? $line['tokens'] : [];
            if ($lt === [] || count($lt) > 16) {
                continue;
            }
            foreach ($samples as $sample) {
                $st = $sample['tokens'];
                if ($st === []) {
                    continue;
                }
                // Exact token sequence match → already should have hit; skip.
                if ($lt === $st) {
                    continue;
                }
                $score = self::nearScore($lt, $st);
                if ($score < 0.55) {
                    continue;
                }
                if ($best === null || $score > $best['score']) {
                    $best = [
                        'record_id' => (int)$line['record_id'],
                        'quote' => (string)$line['text'],
                        'candidate' => self::trimCandidate((string)$line['text'], 8),
                        'dict_id' => (int)$sample['dict_id'],
                        'score' => $score,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Jaccard + edit-friendly nearness on lemma bags / sequences.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function nearScore(array $a, array $b): float
    {
        $na = count($a);
        $nb = count($b);
        if ($na < 1 || $nb < 1) {
            return 0.0;
        }
        // One lemma off (insert/delete/substitute) on short phrases.
        if (abs($na - $nb) <= 1 && max($na, $nb) <= 8) {
            $shared = 0;
            $used = [];
            foreach ($a as $tok) {
                foreach ($b as $j => $bt) {
                    if (isset($used[$j])) {
                        continue;
                    }
                    if ($tok === $bt) {
                        $shared++;
                        $used[$j] = true;
                        break;
                    }
                }
            }
            $den = max($na, $nb);
            $ratio = $den > 0 ? $shared / $den : 0.0;
            if ($shared >= max(1, $den - 1) && $ratio >= 0.5) {
                return 0.55 + 0.4 * $ratio;
            }
        }
        // Contiguous window of a inside longer line close to b.
        if ($na >= $nb && $nb >= 2) {
            for ($i = 0; $i <= $na - $nb; $i++) {
                $slice = array_slice($a, $i, $nb);
                $eq = 0;
                for ($k = 0; $k < $nb; $k++) {
                    if ($slice[$k] === $b[$k]) {
                        $eq++;
                    }
                }
                if ($eq >= $nb - 1 && $eq >= 2) {
                    return 0.6 + 0.35 * ($eq / $nb);
                }
            }
        }
        // Bag Jaccard.
        $sa = array_fill_keys($a, true);
        $sb = array_fill_keys($b, true);
        $inter = 0;
        foreach ($sa as $k => $_) {
            if (isset($sb[$k])) {
                $inter++;
            }
        }
        $union = count($sa) + count($sb) - $inter;
        if ($union < 1) {
            return 0.0;
        }
        $j = $inter / $union;
        if ($j >= 0.7 && $inter >= 2) {
            return 0.55 + 0.3 * $j;
        }

        return 0.0;
    }

    /**
     * Same-line evidence that speech may paraphrase a sample (for LLM), not a
     * whole-session bag match of common lemmas.
     *
     * @param list<array{record_id:int,text:string,tokens:list<string>}> $lines
     * @param list<array{dict_id:int,phrase:string,tokens:list<string>}> $samples
     * @return array{record_id:int,quote:string,lines:list<string>}|null
     */
    private static function findSemanticEvidence(array $lines, array $samples): ?array
    {
        if ($samples === []) {
            return null;
        }
        $stop = self::semanticStopwords();
        $hits = [];
        foreach ($lines as $line) {
            $lt = is_array($line['tokens'] ?? null) ? $line['tokens'] : [];
            if ($lt === [] || count($lt) > 24) {
                continue;
            }
            $text = (string)($line['text'] ?? '');
            $hasDigit = (bool)preg_match('/\d/u', $text);
            foreach ($samples as $sample) {
                $st = $sample['tokens'];
                if ($st === []) {
                    continue;
                }
                $content = [];
                foreach ($st as $tok) {
                    $tok = (string)$tok;
                    if (mb_strlen($tok, 'UTF-8') < 3 || isset($stop[$tok])) {
                        continue;
                    }
                    $content[] = $tok;
                }
                if ($content === []) {
                    continue;
                }
                $shared = 0;
                $lineSet = array_fill_keys($lt, true);
                foreach ($content as $tok) {
                    if (isset($lineSet[$tok])) {
                        $shared++;
                    }
                }
                // Price-like samples: digit in line + ≥1 content lemma (рубл/стои/…).
                if ($hasDigit && $shared >= 1) {
                    $hits[] = $line;
                    break;
                }
                // Non-price: need ≥2 content lemmas from the *same* sample on this line.
                if ($shared >= 2 && count($content) >= 2) {
                    $hits[] = $line;
                    break;
                }
                // Single strong multi-char content word (≥5) + another non-stop from sample.
                if ($shared >= 1 && $hasDigit === false) {
                    $strong = false;
                    foreach ($content as $tok) {
                        if (mb_strlen($tok, 'UTF-8') >= 5 && isset($lineSet[$tok])) {
                            $strong = true;
                            break;
                        }
                    }
                    if ($strong && $shared >= 2) {
                        $hits[] = $line;
                        break;
                    }
                }
            }
            if (count($hits) >= 6) {
                break;
            }
        }
        if ($hits === []) {
            return null;
        }
        $quoteLines = [];
        $recId = 0;
        foreach ($hits as $h) {
            $quoteLines[] = (string)$h['text'];
            if ($recId < 1) {
                $recId = (int)$h['record_id'];
            }
        }

        return [
            'record_id' => $recId,
            'quote' => $quoteLines[0] ?? '',
            'lines' => array_slice($quoteLines, 0, 8),
        ];
    }

    /** @return array<string,true> */
    private static function semanticStopwords(): array
    {
        static $stop = null;
        if ($stop !== null) {
            return $stop;
        }
        $words = [
            'всего', 'будет', 'этот', 'эта', 'это', 'тут', 'там', 'как', 'что',
            'или', 'для', 'меня', 'вас', 'ваш', 'ваш', 'сегодня', 'просто',
            'можно', 'нужно', 'давай', 'сейчас', 'тогда', 'очень', 'только',
            'ещё', 'еще', 'уже', 'здесь', 'когда', 'после', 'перед', 'через',
            'мастер', 'релакс', 'добрый', 'утро', 'вечер', 'день', 'зовут',
        ];
        $stop = [];
        foreach ($words as $w) {
            $stop[$w] = true;
        }

        return $stop;
    }

    private static function trimCandidate(string $text, int $maxWords): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $parts = preg_split('/\s+/u', $text) ?: [];
        if (count($parts) > $maxWords) {
            $parts = array_slice($parts, 0, $maxWords);
        }

        return mb_substr(implode(' ', $parts), 0, 255);
    }

    /** @return list<string> */
    private static function fallbackTokens(string $phrase): array
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
     * @return list<array{id:int,dt:int}>
     */
    private static function recordsInRange(int $adminId, int $fromTs, int $toTs, int $limit): array
    {
        if ($adminId < 1 || !Database::tableExists('base_records') || $toTs < $fromTs) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, DT_RECORDED AS dt FROM base_records
             WHERE ID_ADMIN=? AND DT_RECORDED <= ? AND (DT_RECORDED + COALESCE(PLAYTIME, 600)) >= ?
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION != ''
             ORDER BY DT_RECORDED ASC
             LIMIT $limit"
        );
        $st->execute([$adminId, $toTs, $fromTs]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    private static function transcriptLines(int $recordId): array
    {
        if ($recordId < 1 || !Database::tableExists('base_records')) {
            return [];
        }
        $st = Database::pdo()->prepare('SELECT TRANSCRIPTION FROM base_records WHERE ID=? LIMIT 1');
        $st->execute([$recordId]);
        $src = (string)$st->fetchColumn();
        if ($src === '' || strpos($src, '[') === false) {
            return [];
        }
        $parts = explode('[', $src);
        array_shift($parts);
        $lines = [];
        $prev = '';
        foreach ($parts as $chunk) {
            if (strpos($chunk, ']') === false) {
                continue;
            }
            $pieces = explode(']', $chunk, 2);
            $text = rtrim($pieces[1] ?? '', "\r\n");
            if ($text === '' || $text === $prev) {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 300) {
                continue;
            }
            $prev = $text;
            $lines[] = $text;
            if (count($lines) >= 80) {
                break;
            }
        }

        return $lines;
    }

    private static function recordHref(int $recordId): string
    {
        return $recordId > 0 ? '/audio/' . $recordId : '';
    }
}
