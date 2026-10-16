<?php
declare(strict_types=1);

/**
 * Universal transcript garbage detector for parsers (stem, phrases, UI).
 *
 * Covers dictionary hits, overlong lines, and ASR hallucination loops
 * (token/phrase repetition). Can auto-fill base_records_trashes.
 */
final class TranscriptGarbage
{
    /** Consecutive identical tokens → treat line as trash. */
    private const TOKEN_RUN_MIN = 6;

    /** Min tokens before low-uniqueness ratio applies. */
    private const LOW_UNIQ_MIN_TOKENS = 12;

    /** Unique/total ≤ this → trash (ASR loops with tiny vocab). */
    private const LOW_UNIQ_RATIO = 0.2;

    /** How many times a 2–4-gram unit must repeat to count as a loop. */
    private const NGRAM_REPS_MIN = 4;

    /**
     * @param list<string> $trashPhrases
     * @return array{trash:bool,reason:string,candidate:string}
     */
    public static function analyzeLine(string $text, array $trashPhrases = []): array
    {
        $text = rtrim($text, "\r\n");
        if ($text === '') {
            return ['trash' => false, 'reason' => '', 'candidate' => ''];
        }
        if (self::matchesTrashPhrase($text, $trashPhrases)) {
            return ['trash' => true, 'reason' => 'phrase', 'candidate' => ''];
        }
        if (self::isLongTrashLine($text)) {
            return [
                'trash' => true,
                'reason' => 'long',
                'candidate' => self::candidatePhrase($text),
            ];
        }
        if (self::isCountingLine($text)) {
            return [
                'trash' => true,
                'reason' => 'counting',
                'candidate' => self::candidatePhrase($text),
            ];
        }
        $loop = self::detectLoop($text);
        if ($loop !== null) {
            return [
                'trash' => true,
                'reason' => (string)$loop['kind'],
                'candidate' => self::candidateFromLoop($text, $loop),
            ];
        }
        return ['trash' => false, 'reason' => '', 'candidate' => ''];
    }

    /** @param list<string> $trashPhrases */
    public static function isTrashLine(string $text, array $trashPhrases = []): bool
    {
        return self::analyzeLine($text, $trashPhrases)['trash'];
    }

    /**
     * True when a worker posted an error marker as if it were a transcript
     * (e.g. " failed to read audio data"). Real cabin output uses [MM:SS->MM:SS] lines.
     * Callers clear TRANSCRIPTION but still stamp DT_TRANSCRIBED so claim does not loop.
     */
    public static function isAsrErrorTranscription(string $result): bool
    {
        $raw = (string)$result;
        $t = trim($raw);
        if ($t === '') {
            return false;
        }
        // Normal Whisper client format — never treat as error noise.
        if (preg_match('/\[\d{1,2}:\d{2}->\d{1,2}:\d{2}\]/', $t) === 1) {
            return false;
        }
        $lower = mb_strtolower($t, 'UTF-8');
        $needles = [
            'failed to read',
            'whisper-server',
            'whisper server',
            'пустой ответ',
            'ffmpeg',
            'ffprobe',
            'runtimeerror',
            'connection refused',
            'connection reset',
            'http 4',
            'http 5',
            'timeout',
            'traceback',
            'exception:',
            'error:',
        ];
        foreach ($needles as $n) {
            if (strpos($lower, $n) !== false) {
                return true;
            }
        }
        // upload_error_marker: leading space + single short line, no timestamps.
        if ($raw !== '' && $raw[0] === ' '
            && strpos($t, "\n") === false
            && mb_strlen($t, 'UTF-8') <= 300
        ) {
            return true;
        }

        return false;
    }

    /**
     * Distinctive needle for base_records_trashes (substring match).
     */
    public static function candidatePhrase(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= 80) {
            return $text;
        }
        return mb_substr($text, 0, 80, 'UTF-8');
    }

    /**
     * Scan raw transcriptions; return new candidates not already covered by $trashPhrases.
     *
     * @param list<string> $transcriptions
     * @param list<string> $trashPhrases
     * @return list<string>
     */
    public static function collectCandidates(array $transcriptions, array $trashPhrases = []): array
    {
        $found = [];
        foreach ($transcriptions as $src) {
            $src = (string)$src;
            if ($src === '') {
                continue;
            }
            foreach (self::iterLineTexts($src) as $text) {
                $info = self::analyzeLine($text, $trashPhrases);
                if (!$info['trash'] || $info['reason'] === 'phrase') {
                    continue;
                }
                $cand = (string)$info['candidate'];
                if ($cand === '' || self::matchesTrashPhrase($cand, $trashPhrases)
                    || isset($found[$cand])
                ) {
                    continue;
                }
                $found[$cand] = $cand;
            }
        }
        return array_values($found);
    }

    /**
     * Detect + ensure into base_records_trashes. Returns count newly inserted.
     *
     * @param list<string> $transcriptions
     * @param list<string>|null $trashPhrases null = load enabled from DB
     */
    public static function ensureFromTranscriptions(array $transcriptions, ?array $trashPhrases = null): int
    {
        if ($trashPhrases === null) {
            $trashPhrases = class_exists('RecordsRepository', false)
                ? RecordsRepository::trashPhrases()
                : [];
        }
        $cands = self::collectCandidates($transcriptions, $trashPhrases);
        if ($cands === [] || !class_exists('RecordsTrashesRepository', false)) {
            return 0;
        }
        return RecordsTrashesRepository::ensureMany($cands);
    }

    /**
     * Ensure a single surface sample into the trash dictionary.
     */
    public static function ensureSample(string $sample): int
    {
        $sample = self::candidatePhrase($sample);
        if ($sample === '' || !class_exists('RecordsTrashesRepository', false)) {
            return 0;
        }
        $before = RecordsTrashesRepository::findByName($sample);
        RecordsTrashesRepository::ensure($sample, true);
        return $before ? 0 : 1;
    }

    /** Fast “>500 chars” check avoiding mb_strlen on short strings. */
    public static function isLongTrashLine(string $text): bool
    {
        $bytes = strlen($text);
        if ($bytes <= 500) {
            return false;
        }
        if ($bytes > 2000) {
            return true;
        }
        return mb_strlen($text, 'UTF-8') > 500;
    }

    /** @param list<string> $trashPhrases */
    public static function matchesTrashPhrase(string $text, array $trashPhrases): bool
    {
        foreach ($trashPhrases as $sample) {
            $sample = (string)$sample;
            if ($sample !== '' && strpos($text, $sample) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when an N-gram lemma slice is pure repetition (ну ну ну / 1927 1927 1927).
     *
     * @param list<string> $lemmas
     */
    public static function isRepeatedLemmaSlice(array $lemmas): bool
    {
        $n = count($lemmas);
        if ($n < 2) {
            return false;
        }
        $first = (string)$lemmas[0];
        if ($first === '') {
            return false;
        }
        for ($i = 1; $i < $n; $i++) {
            if ((string)$lemmas[$i] !== $first) {
                return false;
            }
        }
        return true;
    }

    /** Token is a bare integer (ASR count hallucinations: 49, 50, 51…). */
    public static function isNumericToken(string $tok): bool
    {
        $tok = trim($tok);
        if ($tok === '') {
            return false;
        }
        return (bool)preg_match('/^\d{1,6}$/u', $tok);
    }

    /**
     * True when every lemma in the N-gram is numeric (e.g. «52 52 53»).
     * Such triples must not enter top-phrases.
     *
     * @param list<string> $lemmas
     */
    public static function isNumericLemmaSlice(array $lemmas): bool
    {
        if (count($lemmas) < 2) {
            return false;
        }
        foreach ($lemmas as $tok) {
            if (!self::isNumericToken((string)$tok)) {
                return false;
            }
        }
        return true;
    }

    /**
     * ASR “counting out loud” / digit-list hallucinations (often &lt;500 chars).
     * Examples: «8, 9, 10, … 52, 53, 52, 53…»
     */
    public static function isCountingLine(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        // Dense comma-separated integers (4+ commas with digits).
        if (preg_match('/(?:\d{1,6}\s*,\s*){4,}\d{1,6}/u', $text)) {
            return true;
        }
        $toks = self::tokenize($text);
        $n = count($toks);
        if ($n < 6) {
            return false;
        }
        $num = 0;
        foreach ($toks as $t) {
            if (self::isNumericToken($t)) {
                $num++;
            }
        }
        // Mostly numbers → counting trash (keep “на 5 минут” style lines).
        return $num >= 6 && ($num / $n) >= 0.7;
    }

    /**
     * @return list<string> plain line texts (bracket timestamps stripped)
     */
    public static function iterLineTexts(string $src): array
    {
        $src = str_replace(["\r\n", "\r"], "\n", $src);
        $out = [];
        if (strpos($src, '[') !== false) {
            $parts = explode('[', $src);
            array_shift($parts);
            foreach ($parts as $chunk) {
                if (strpos($chunk, ']') === false) {
                    continue;
                }
                $pieces = explode(']', $chunk, 2);
                $text = rtrim((string)($pieces[1] ?? ''), "\r\n");
                if ($text !== '') {
                    $out[] = $text;
                }
            }
            return $out;
        }
        foreach (preg_split('/\n+/', $src) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * @return array{kind:string,unit:string,run:int}|null
     */
    private static function detectLoop(string $text): ?array
    {
        $toks = self::tokenize($text);
        $n = count($toks);
        if ($n < self::TOKEN_RUN_MIN) {
            return null;
        }

        $maxRun = 1;
        $run = 1;
        $runTok = $toks[0];
        for ($i = 1; $i < $n; $i++) {
            if ($toks[$i] === $toks[$i - 1]) {
                $run++;
                if ($run > $maxRun) {
                    $maxRun = $run;
                    $runTok = $toks[$i];
                }
            } else {
                $run = 1;
            }
        }
        if ($maxRun >= self::TOKEN_RUN_MIN) {
            return ['kind' => 'token_loop', 'unit' => $runTok, 'run' => $maxRun];
        }

        $uniq = count(array_unique($toks));
        if ($n >= self::LOW_UNIQ_MIN_TOKENS && ($uniq / $n) <= self::LOW_UNIQ_RATIO) {
            return ['kind' => 'low_uniq', 'unit' => $toks[0], 'run' => $n];
        }

        foreach ([2, 3, 4] as $k) {
            if ($n < $k * self::NGRAM_REPS_MIN) {
                continue;
            }
            $best = 0;
            $bestUnit = '';
            for ($start = 0; $start <= min($k, $n - $k * self::NGRAM_REPS_MIN); $start++) {
                $unit = array_slice($toks, $start, $k);
                $reps = 1;
                $pos = $start + $k;
                while ($pos + $k <= $n) {
                    $slice = array_slice($toks, $pos, $k);
                    if ($slice !== $unit) {
                        break;
                    }
                    $reps++;
                    $pos += $k;
                }
                if ($reps > $best) {
                    $best = $reps;
                    $bestUnit = implode(' ', $unit);
                }
            }
            if ($best >= self::NGRAM_REPS_MIN) {
                return ['kind' => 'ngram_loop', 'unit' => $bestUnit, 'run' => $best];
            }
        }

        return null;
    }

    /**
     * @param array{kind:string,unit:string,run:int} $loop
     */
    private static function candidateFromLoop(string $text, array $loop): string
    {
        $unit = trim((string)$loop['unit']);
        if ($unit === '') {
            return self::candidatePhrase($text);
        }
        // Prefer a surface fragment that appears in the original line (case-sensitive strpos).
        $surface = self::findSurfaceRepeat($text, $unit);
        if ($surface !== '') {
            return self::candidatePhrase($surface);
        }
        return self::candidatePhrase($text);
    }

    /**
     * Build "unit, unit, unit" style needle from original casing when possible.
     */
    private static function findSurfaceRepeat(string $text, string $unitNorm): string
    {
        $unitNorm = mb_strtolower($unitNorm, 'UTF-8');
        $parts = preg_split('/\s+/u', $unitNorm) ?: [];
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
        if ($parts === []) {
            return '';
        }
        // Single-token loop: take first three comma-separated occurrences from text.
        if (count($parts) === 1) {
            $tok = $parts[0];
            if (preg_match(
                '/(' . preg_quote($tok, '/') . '(?:\s*[,.]?\s*' . preg_quote($tok, '/') . '){2,4})/ui',
                $text,
                $m
            )) {
                return (string)$m[1];
            }
        }
        return '';
    }

    /** @return list<string> */
    private static function tokenize(string $text): array
    {
        $norm = mb_strtolower(trim($text), 'UTF-8');
        $norm = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $norm) ?? $norm;
        $parts = preg_split('/\s+/u', trim($norm)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = (string)$p;
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
