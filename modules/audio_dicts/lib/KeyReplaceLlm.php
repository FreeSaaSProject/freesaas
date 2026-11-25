<?php
declare(strict_types=1);

/**
 * LLM check of one dictionary key: keep (correct term) or replace (ASR error of a canonical key).
 * Result is only stored as a suggestion (state `suggested`); a human accepts it. Soft-depends on llm.
 */
final class KeyReplaceLlm
{
    private const CANDIDATES = 300;
    private const CONTEXTS = 3;

    public static function isReady(): bool
    {
        return DictDescrSuggest::isReady();
    }

    /**
     * @return array{ok:bool,state:string,suggest:string,error:string}
     */
    public static function analyzeKey(int $keyId): array
    {
        $out = ['ok' => false, 'state' => '', 'suggest' => '', 'error' => ''];
        $row = RecordsKeysRepository::find($keyId);
        if ($row === null) {
            $out['error'] = t('audio_dicts.err.not_found', ['id' => $keyId]);

            return $out;
        }
        if (!self::isReady()) {
            $out['error'] = t('audio_dicts.dicts.suggest_llm_missing');

            return $out;
        }
        $dictId = (int)$row['id_dictonary'];
        $candidates = RecordsKeysRepository::canonicalCandidates($dictId, $keyId, self::CANDIDATES);
        if ($candidates === []) {
            // Nothing to replace with yet: keep pending so a later run can pick it up.
            $out['error'] = t('audio_dicts.replace.llm.no_candidates');

            return $out;
        }
        $dict = RecordsDictsRepository::find($dictId);
        $payload = [
            'phrase' => (string)$row['name'],
            'dictionary' => [
                'name' => (string)($dict['name'] ?? ''),
                'descr' => (string)($dict['descr'] ?? ''),
            ],
            'candidates' => array_map(static fn (array $c): string => $c['name'], $candidates),
            'transcript_contexts' => self::contexts($keyId),
        ];
        $res = LlmClient::complete([
            ['role' => 'system', 'content' => 'You check entries of a speech-recognition (ASR) dictionary for a Russian '
                . 'spa/massage reception. Decide for "phrase": decision "keep" when the phrase is a correct term of its own, '
                . 'or decision "replace" when it is a wrong ASR recognition of exactly one entry from "candidates". '
                . 'For replace, "to" must be copied exactly from candidates. If unsure, use keep. '
                . 'Return JSON only: {"decision":"keep|replace","to":"","confidence":0.0}.'],
            ['role' => 'user', 'content' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ], [
            'json' => true,
            'temperature' => 0.1,
            'max_tokens' => 300,
            'timeout' => 60,
        ]);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            $out['error'] = (string)($res['error'] ?? t('audio_dicts.dicts.suggest_failed'));

            return $out;
        }
        $decision = strtolower(trim((string)($res['json']['decision'] ?? '')));
        if ($decision === 'keep') {
            $out['ok'] = true;
            $out['state'] = 'keep';

            return $out;
        }
        if ($decision !== 'replace') {
            $out['error'] = t('audio_dicts.replace.llm.bad_answer');

            return $out;
        }
        $to = self::matchCandidate((string)($res['json']['to'] ?? ''), $candidates);
        if ($to === '') {
            // Model named something outside the list: do not invent a link, keep pending.
            $out['error'] = t('audio_dicts.replace.llm.bad_target');

            return $out;
        }
        $out['ok'] = true;
        $out['state'] = 'suggested';
        $out['suggest'] = $to;

        return $out;
    }

    /**
     * @param list<array{id:int,name:string}> $candidates
     */
    private static function matchCandidate(string $to, array $candidates): string
    {
        $to = trim($to);
        if ($to === '') {
            return '';
        }
        $norm = RecordsKeysRepository::normalizeName($to);
        foreach ($candidates as $c) {
            if ($c['name'] === $to) {
                return $c['name'];
            }
        }
        foreach ($candidates as $c) {
            if (RecordsKeysRepository::normalizeName($c['name']) === $norm && $norm !== '') {
                return $c['name'];
            }
        }

        return '';
    }

    /**
     * Short transcript lines where the key was recognised (for the model, not stored).
     *
     * @return list<string>
     */
    private static function contexts(int $keyId): array
    {
        if ($keyId < 1 || !Database::tableExists('base_records_instances') || !Database::tableExists('base_records')) {
            return [];
        }
        $limit = self::CONTEXTS;
        $st = Database::pdo()->prepare(
            "SELECT r.TRANSCRIPTION AS t, i.STRING_NUM AS n
             FROM base_records_instances i
             JOIN base_records r ON r.ID = i.ID_RECORD
             WHERE i.ID_KEY = ?
             ORDER BY i.ID DESC LIMIT {$limit}"
        );
        $st->execute([$keyId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $parts = explode('[', (string)$row['t']);
            $n = (int)$row['n'];
            $line = (string)($parts[$n] ?? '');
            $pos = strpos($line, ']');
            if ($pos !== false) {
                $line = substr($line, $pos + 1);
            }
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line !== '') {
                $out[] = mb_substr($line, 0, 240, 'UTF-8');
            }
        }

        return $out;
    }
}
