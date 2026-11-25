<?php
declare(strict_types=1);

/**
 * Read-time ASR correction of Whisper-style transcripts ("[MM:SS->MM:SS]text" lines).
 *
 * Source text in base_records.TRANSCRIPTION is never rewritten. Key hits recorded in
 * base_records_instances (line, position, length) whose key points to a canonical key
 * (base_records_keys.ID_REPLACE, state approved) are substituted by the canonical phrase.
 * Used by TranscriptHighlighter (UI, with a marker) and by text feeds for LLM / agents.
 */
final class TranscriptCorrector
{
    /**
     * Phrase text of a key NAME: `**` → space, first `|` alternative, strip `*`,
     * trim edge punctuation (keeps inner hyphens).
     */
    public static function textFromKeyName(string $name): string
    {
        $name = str_replace('**', ' ', $name);
        if (strpos($name, '|') !== false) {
            $name = (string)explode('|', $name, 2)[0];
        }
        $name = str_replace('*', '', $name);
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? $name;
        $name = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $name) ?? $name;

        return trim($name);
    }

    /**
     * Compute non-overlapping substitutions for one transcript line.
     *
     * @param list<array{pos:int,len:int,replace:string}> $hits replace = canonical key NAME (raw)
     * @return list<array{start:int,end:int,text:string,orig:string}> char offsets (mb), sorted
     */
    public static function lineEdits(string $text, array $hits): array
    {
        if ($text === '' || $hits === []) {
            return [];
        }
        usort($hits, static function (array $a, array $b): int {
            return $a['pos'] <=> $b['pos'];
        });
        $lenText = mb_strlen($text, 'UTF-8');
        $cursor = 0;
        $edits = [];
        foreach ($hits as $hit) {
            $repl = self::textFromKeyName((string)($hit['replace'] ?? ''));
            if ($repl === '') {
                continue;
            }
            $start = max(0, (int)$hit['pos']);
            $len = (int)$hit['len'];
            if ($len < 1 || $start >= $lenText) {
                continue;
            }
            $end = min($lenText, $start + $len);
            if (mb_substr($text, $start, 1, 'UTF-8') === ' ') {
                $start++;
            }
            if ($start >= $end) {
                continue;
            }
            // Never cut a word: widen to the surrounding word (hyphen-joined parts included).
            while ($start > 0 && self::isWordChar(mb_substr($text, $start - 1, 1, 'UTF-8'))
                && self::isWordChar(mb_substr($text, $start, 1, 'UTF-8'))) {
                $start--;
            }
            while ($end < $lenText) {
                $ch = mb_substr($text, $end, 1, 'UTF-8');
                $prev = mb_substr($text, $end - 1, 1, 'UTF-8');
                if (self::isWordChar($ch)) {
                    if (!self::isWordChar($prev) && $prev !== '-') {
                        break;
                    }
                    $end++;
                    continue;
                }
                if ($ch === '-' && self::isWordChar($prev) && $end + 1 < $lenText
                    && self::isWordChar(mb_substr($text, $end + 1, 1, 'UTF-8'))) {
                    $end++;
                    continue;
                }
                break;
            }
            if ($start < $cursor) {
                continue; // overlap with the previous edit
            }
            $frag = mb_substr($text, $start, $end - $start, 'UTF-8');
            // Keep edge punctuation of the matched span (key NAME may carry a trailing dot).
            $lead = '';
            $tail = '';
            if (preg_match('/^([^\p{L}\p{N}]+)/u', $frag, $m)) {
                $lead = $m[1];
            }
            if (preg_match('/([^\p{L}\p{N}]+)$/u', $frag, $m)) {
                $tail = $m[1];
            }
            $core = mb_substr($frag, mb_strlen($lead, 'UTF-8'), mb_strlen($frag, 'UTF-8') - mb_strlen($lead, 'UTF-8') - mb_strlen($tail, 'UTF-8'), 'UTF-8');
            if ($core === '') {
                continue;
            }
            $coreStart = $start + mb_strlen($lead, 'UTF-8');
            $coreEnd = $coreStart + mb_strlen($core, 'UTF-8');
            $new = self::matchCase($core, $repl);
            if ($new === $core) {
                $cursor = $coreEnd;
                continue;
            }
            $edits[] = ['start' => $coreStart, 'end' => $coreEnd, 'text' => $new, 'orig' => $core];
            $cursor = $coreEnd;
        }

        return $edits;
    }

    /** Apply edits from lineEdits() to a line (plain text). */
    public static function applyEdits(string $text, array $edits): string
    {
        if ($edits === []) {
            return $text;
        }
        $out = '';
        $cursor = 0;
        foreach ($edits as $e) {
            $out .= mb_substr($text, $cursor, $e['start'] - $cursor, 'UTF-8') . $e['text'];
            $cursor = $e['end'];
        }

        return $out . mb_substr($text, $cursor, null, 'UTF-8');
    }

    /**
     * Group instances (RecordsRepository::instancesForRecords rows) by line for one record.
     *
     * @param list<array<string,mixed>> $instances
     * @return array<int,list<array{pos:int,len:int,replace:string}>>
     */
    public static function hitsByLine(array $instances): array
    {
        $by = [];
        foreach ($instances as $inst) {
            $repl = trim((string)($inst['replace_name'] ?? ''));
            if ($repl === '') {
                continue;
            }
            $line = (int)($inst['string_num'] ?? 0);
            if ($line < 1) {
                continue;
            }
            $by[$line][] = [
                'pos' => (int)($inst['string_position'] ?? 0),
                'len' => (int)($inst['length'] ?? 0),
                'replace' => $repl,
            ];
        }

        return $by;
    }

    /**
     * Corrected transcript text (no markers). Line numbering matches StemRunner / highlighter.
     *
     * @param list<array<string,mixed>> $instances rows of ONE record
     */
    public static function applyToText(string $transcription, array $instances): string
    {
        $by = self::hitsByLine($instances);
        if ($by === [] || $transcription === '' || strpos($transcription, '[') === false) {
            return $transcription;
        }
        $parts = explode('[', $transcription);
        $head = array_shift($parts);
        $out = (string)$head;
        $num = 0;
        foreach ($parts as $chunk) {
            $num++;
            $out .= '[';
            if (!isset($by[$num]) || strpos($chunk, ']') === false) {
                $out .= $chunk;
                continue;
            }
            $pieces = explode(']', $chunk, 2);
            $text = (string)($pieces[1] ?? '');
            $body = rtrim($text, "\r\n");
            $trail = substr($text, strlen($body));
            $edits = self::lineEdits($body, $by[$num]);
            $out .= $pieces[0] . ']' . self::applyEdits($body, $edits) . $trail;
        }

        return $out;
    }

    /**
     * Corrected text for many records (one instances query).
     *
     * @param array<int,string> $transcriptions record id => raw text
     * @return array<int,string>
     */
    public static function applyToRecords(array $transcriptions): array
    {
        $ids = array_keys($transcriptions);
        if ($ids === [] || !class_exists('RecordsRepository', false)) {
            return $transcriptions;
        }
        $byRec = [];
        foreach (RecordsRepository::instancesForRecords($ids) as $inst) {
            if (trim((string)($inst['replace_name'] ?? '')) === '') {
                continue;
            }
            $byRec[(int)$inst['id_record']][] = $inst;
        }
        $out = [];
        foreach ($transcriptions as $rid => $text) {
            $out[$rid] = isset($byRec[$rid]) ? self::applyToText((string)$text, $byRec[$rid]) : (string)$text;
        }

        return $out;
    }

    /** Corrected text of one record by id (raw text given to avoid a second SELECT). */
    public static function forRecord(int $recordId, string $transcription): string
    {
        if ($recordId < 1 || $transcription === '') {
            return $transcription;
        }
        $res = self::applyToRecords([$recordId => $transcription]);

        return (string)($res[$recordId] ?? $transcription);
    }

    /** Match first-letter case of the original fragment. */
    public static function matchCase(string $original, string $replacement): string
    {
        if ($replacement === '' || $original === '') {
            return $replacement;
        }
        $o = mb_substr($original, 0, 1, 'UTF-8');
        $r = mb_substr($replacement, 0, 1, 'UTF-8');
        $rest = mb_substr($replacement, 1, null, 'UTF-8');
        if ($o !== mb_strtolower($o, 'UTF-8') && $o === mb_strtoupper($o, 'UTF-8')) {
            return mb_strtoupper($r, 'UTF-8') . $rest;
        }

        return mb_strtolower($r, 'UTF-8') . $rest;
    }

    private static function isWordChar(string $ch): bool
    {
        return $ch !== '' && preg_match('/^[\p{L}\p{N}]$/u', $ch) === 1;
    }
}
