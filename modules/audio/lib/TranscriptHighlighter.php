<?php
declare(strict_types=1);

/**
 * Parse Whisper-style transcript lines and highlight key hits from base_records_instances.
 *
 * Line format: [MM:SS->MM:SS]text  (STRING_NUM is 1-based explode index).
 */
final class TranscriptHighlighter
{
    /**
     * @param list<array{string_num:int,string_position:int,id_key:int,length:int,key_name?:string,type_color?:string,type_bg?:string}> $instances
     * @param list<string> $trashPhrases
     * @param array{
     *   window_from?:int,
     *   window_to?:int,
     *   dt_recorded?:int,
     *   intersections?:list<array{start:int,end:int,id?:int|string,match?:int|string}>,
     *   compact?:bool
     * } $opts Absolute unix window (oper: collection DT ±15s) filters lines via dt_recorded + line offset.
     * @return array{html:string,lines:int,trash:int,keys:int,trash_candidates:list<string>,was_intersect:bool}
     */
    public static function render(
        string $transcription,
        array $instances,
        array $trashPhrases = [],
        string $search = '',
        array $opts = []
    ): array {
        $out = [
            'html' => '',
            'lines' => 0,
            'trash' => 0,
            'keys' => 0,
            'trash_candidates' => [],
            'was_intersect' => false,
        ];
        $src = (string)$transcription;
        if ($src === '' || strpos($src, '[') === false) {
            $out['html'] = '<p class="muted">' . te('audio.transcript.empty') . '</p>';
            return $out;
        }

        $windowFrom = max(0, (int)($opts['window_from'] ?? 0));
        $windowTo = max(0, (int)($opts['window_to'] ?? 0));
        $dtRecorded = max(0, (int)($opts['dt_recorded'] ?? 0));
        $compact = !empty($opts['compact']);
        /** @var list<array{start:int,end:int,id:string,match:string}> $intersections */
        $intersections = [];
        foreach (is_array($opts['intersections'] ?? null) ? $opts['intersections'] : [] as $ix) {
            if (!is_array($ix)) {
                continue;
            }
            $intersections[] = [
                'start' => (int)($ix['start'] ?? 0),
                'end' => (int)($ix['end'] ?? 0),
                'id' => (string)($ix['id'] ?? ''),
                'match' => (string)($ix['match'] ?? ''),
            ];
        }

        /** @var array<int,list<array{pos:int,len:int,name:string,color:string,bg:string}>> $byLine */
        $byLine = [];
        foreach ($instances as $inst) {
            $line = (int)($inst['string_num'] ?? 0);
            if ($line < 1) {
                continue;
            }
            $byLine[$line][] = [
                'pos' => (int)($inst['string_position'] ?? 0),
                'len' => (int)($inst['length'] ?? 0),
                'name' => (string)($inst['key_name'] ?? ''),
                'color' => (string)($inst['type_color'] ?? '#111'),
                'bg' => (string)($inst['type_bg'] ?? '#fde68a'),
            ];
        }

        $parts = explode('[', $src);
        array_shift($parts);
        $html = '';
        $num = 0;
        $prevTexts = ['', '', ''];

        foreach ($parts as $chunk) {
            $num++;
            if (strpos($chunk, ']') === false) {
                $html .= '<div class="transcript-line is-error">' . te('audio.transcript.bad_bracket') . '</div>';
                continue;
            }
            $pieces = explode(']', $chunk, 2);
            $timeRaw = (string)($pieces[0] ?? '');
            $text = rtrim((string)($pieces[1] ?? ''), "\r\n");

            $timeStart = 0;
            $timeEnd = 0;
            if (strpos($timeRaw, '->') !== false) {
                [$a, $b] = explode('->', $timeRaw, 2);
                $timeStart = self::mmssToSeconds(trim($a));
                $timeEnd = self::mmssToSeconds(trim($b));
            }

            $absTs = $dtRecorded > 0 ? $dtRecorded + $timeStart : 0;
            if ($absTs > 0 && $windowFrom > 0 && $absTs < $windowFrom) {
                continue;
            }
            if ($absTs > 0 && $windowTo > 0 && $absTs > $windowTo) {
                continue;
            }

            if ($text !== '' && ($text === $prevTexts[0] || $text === $prevTexts[1] || $text === $prevTexts[2])) {
                continue;
            }

            $g = TranscriptGarbage::analyzeLine($text, $trashPhrases);
            if ($g['trash']) {
                $out['trash']++;
                if ($g['reason'] !== 'phrase' && $g['candidate'] !== '') {
                    $out['trash_candidates'][$g['candidate']] = $g['candidate'];
                }
                continue;
            }

            $hits = $byLine[$num] ?? [];
            $highlighted = self::highlightLine($text, $hits, $out['keys']);

            if ($search !== '' && mb_stripos($text, $search, 0, 'UTF-8') !== false) {
                $highlighted .= ' <b class="transcript-search">' . e($search) . '</b>';
            }

            $ixMark = '';
            $lineCls = 'transcript-line';
            if ($absTs > 0 && $intersections !== []) {
                foreach ($intersections as $ix) {
                    if ($ix['start'] > 0 && $ix['end'] > 0 && $absTs >= $ix['start'] && $absTs <= $ix['end']) {
                        $out['was_intersect'] = true;
                        $lineCls .= ' is-intersect';
                        $label = $ix['id'];
                        if ($ix['match'] !== '') {
                            $label .= ($label !== '' ? '-' : '') . $ix['match'] . '%';
                        }
                        if ($label !== '') {
                            $ixMark = ' <span class="transcript-intersect-tag">[' . e($label) . ']</span>';
                        }
                        break;
                    }
                }
            }

            $attrs = '';
            if ($timeEnd > 0) {
                $attrs = ' data-time-start="' . $timeStart . '" data-time-end="' . $timeEnd . '"';
            }
            $timeLabel = $timeRaw;
            $absMode = !empty($opts['absolute_time']) && $dtRecorded > 0;
            if ($absMode) {
                $absEnd = $dtRecorded + max($timeStart, $timeEnd > 0 ? $timeEnd : $timeStart);
                $timeLabel = date('H:i:s', $dtRecorded + $timeStart)
                    . '→'
                    . date('H:i:s', $absEnd);
            }
            $numHtml = $compact ? '' : '<span class="transcript-num">' . $num . '</span> ';
            $html .= '<div class="' . $lineCls . '"' . $attrs . '>'
                . $numHtml
                . '<span class="transcript-time">[' . e($timeLabel) . ']</span> '
                . '<span class="transcript-text">' . $highlighted . '</span>'
                . $ixMark
                . '</div>';

            $out['lines']++;
            $prevTexts[2] = $prevTexts[1];
            $prevTexts[1] = $prevTexts[0];
            $prevTexts[0] = $text;
        }

        if ($out['trash'] > 0 && !$compact) {
            $html .= '<p class="transcript-trash">' . te('audio.transcript.trash_count', ['n' => $out['trash']]) . '</p>';
        }
        if ($html === '') {
            $html = '<p class="muted">' . te('audio.transcript.empty') . '</p>';
        }
        $out['html'] = $html;
        $out['trash_candidates'] = array_values($out['trash_candidates']);
        return $out;
    }

    /**
     * Decode legacy serialized INTERSECTIONS blob into absolute ranges.
     *
     * @return list<array{start:int,end:int,id:string,match:string}>
     */
    public static function parseIntersections(?string $raw): array
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id => $data) {
            if (!is_array($data)) {
                continue;
            }
            $start = (int)($data['intersectStart'] ?? $data['start'] ?? 0);
            $end = (int)($data['intersectEnd'] ?? $data['end'] ?? 0);
            if ($start < 1 || $end < 1) {
                continue;
            }
            $out[] = [
                'start' => $start,
                'end' => $end,
                'id' => (string)$id,
                'match' => (string)($data['match'] ?? ''),
            ];
        }
        return $out;
    }

    /** @see TranscriptGarbage::candidatePhrase */
    public static function trashCandidatePhrase(string $text): string
    {
        return TranscriptGarbage::candidatePhrase($text);
    }

    /**
     * Scan raw transcriptions for trash candidates not covered by existing phrases.
     *
     * @param list<string> $transcriptions
     * @param list<string> $trashPhrases
     * @return list<string>
     */
    public static function collectTrashCandidates(array $transcriptions, array $trashPhrases = []): array
    {
        return TranscriptGarbage::collectCandidates($transcriptions, $trashPhrases);
    }

    /**
     * @param list<array{pos:int,len:int,name:string,color:string,bg:string}> $hits
     */
    private static function highlightLine(string $text, array $hits, int &$keyCount): string
    {
        if ($text === '') {
            return '';
        }
        if (!$hits) {
            return e($text);
        }

        usort($hits, static function ($a, $b) {
            return $a['pos'] <=> $b['pos'];
        });

        $cursor = 0;
        $chunks = [];
        $lenText = mb_strlen($text, 'UTF-8');

        foreach ($hits as $hit) {
            $pos = max(0, (int)$hit['pos']);
            $len = (int)$hit['len'];
            if ($len < 1) {
                $len = mb_strlen((string)$hit['name'], 'UTF-8');
            }
            if ($len < 1 || $pos >= $lenText) {
                continue;
            }
            if (mb_substr($text, $pos, 1, 'UTF-8') === ' ') {
                $pos++;
            }
            if ($pos < $cursor) {
                continue; // overlap
            }
            if ($pos > $cursor) {
                $chunks[] = e(mb_substr($text, $cursor, $pos - $cursor, 'UTF-8'));
            }
            $end = min($lenText, $pos + $len);
            $frag = mb_substr($text, $pos, $end - $pos, 'UTF-8');
            if ($frag === '') {
                continue;
            }
            $title = e($hit['name'] !== '' ? $hit['name'] : $frag);
            $style = 'background-color:' . e($hit['bg']) . ';color:' . e($hit['color']);
            $chunks[] = '<b class="key-hit" style="' . $style . '" title="' . $title . '">' . e($frag) . '</b>';
            $cursor = $end;
            $keyCount++;
        }
        if ($cursor < $lenText) {
            $chunks[] = e(mb_substr($text, $cursor, null, 'UTF-8'));
        }
        return implode('', $chunks);
    }

    /** @param list<string> $trashPhrases */
    public static function matchesTrashPhrase(string $text, array $trashPhrases): bool
    {
        return TranscriptGarbage::matchesTrashPhrase($text, $trashPhrases);
    }

    public static function isLongTrashLine(string $text): bool
    {
        return TranscriptGarbage::isLongTrashLine($text);
    }

    /** @param list<string> $trashPhrases */
    public static function isTrash(string $text, array $trashPhrases): bool
    {
        return TranscriptGarbage::isTrashLine($text, $trashPhrases);
    }

    /**
     * KEY_TYPES: ##typeId-diversity-instances##...
     *
     * @return list<array{type_id:int,diversity:int,instances:int}>
     */
    public static function parseKeyTypes(?string $raw): array
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode('##', $raw) as $chunk) {
            $chunk = trim(str_replace('#', '', $chunk));
            if ($chunk === '') {
                continue;
            }
            $parts = explode('-', $chunk);
            if (count($parts) < 3) {
                continue;
            }
            $out[] = [
                'type_id' => (int)$parts[0],
                'diversity' => (int)$parts[1],
                'instances' => (int)$parts[2],
            ];
        }
        return $out;
    }

    private static function mmssToSeconds(string $mmss): int
    {
        if (strpos($mmss, ':') === false) {
            return max(0, (int)$mmss);
        }
        $p = explode(':', $mmss);
        return max(0, ((int)($p[0] ?? 0)) * 60 + (int)($p[1] ?? 0));
    }
}
