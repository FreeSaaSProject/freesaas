<?php
declare(strict_types=1);

/**
 * Batch transcripts for agents (MCP audio__transcripts__batch): one call instead of N audio.get.
 *
 * Selection: explicit ids, or day / time window / admin / badge / review filters.
 * context_before: N preceding records of the same admin (or same badge when unassigned) on the same day,
 * flagged context=true.
 *
 * Paging has two independent signals:
 *  - selection: next_offset (index into the selected records); null = every selected record delivered.
 *  - transcript: per item `complete`; a long record is delivered in slices of whole lines, the cursor is
 *    (next_offset, next_line_from) and next_offset stays on that record until its last line is out.
 * Context records are delivered whole or listed in context_omitted (fetch them with ids=<id>, line_from=1).
 * Any record can be read fully with ids=<id> and the line cursor.
 */
final class TranscriptsBatch
{
    public const MAX_IDS = 200;
    /**
     * Small pages on purpose: long tool results get cut by MCP clients and by the in-system
     * assistant (AssistantToolPayload caps tool results at 12000 bytes and compacts above that).
     */
    public const LIMIT_DEFAULT = 5;
    public const LIMIT_MAX = 10;
    public const CONTEXT_MAX = 5;
    public const BYTES_DEFAULT = 12000;
    public const BYTES_MIN = 4000;
    public const BYTES_MAX = 30000;

    /** Reserved for the envelope (ok, total, next_offset, …) on top of the items. */
    private const ENVELOPE_RESERVE = 768;

    /** When a record does not fit alone, context may take at most this share of the budget. */
    private const CONTEXT_SHARE = 0.4;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param array<string,mixed> $in query / JSON arguments
     * @return array<string,mixed> envelope with ok=true, or ['ok'=>false,'error'=>string]
     */
    public static function run(array $in): array
    {
        $p = self::parse($in);
        if ($p['error'] !== '') {
            return ['ok' => false, 'error' => $p['error']];
        }

        // 1) Selection (metadata only; ordered chronologically).
        $missing = [];
        if ($p['ids'] !== []) {
            $selected = RecordsRepository::lightByIds($p['ids']);
            $found = [];
            foreach ($selected as $row) {
                $found[(int)$row['id']] = true;
            }
            foreach ($p['ids'] as $id) {
                if (!isset($found[$id])) {
                    $missing[] = $id;
                }
            }
        } else {
            $selected = RecordsRepository::list([
                'admin' => $p['admin'],
                'unassigned' => $p['unassigned'],
                'badge' => $p['badge'],
                'year' => $p['year'],
                'month' => $p['month'],
                'day' => $p['day'],
                'range_from' => $p['range_from'],
                'range_to' => $p['range_to'],
                'search' => $p['search'],
                'disabled' => $p['disabled'],
                'review' => $p['review'],
                'limit' => 0,
                'order' => 'DT_RECORDED ASC, ID ASC',
            ])['items'];
        }

        $total = count($selected);
        $selectedSet = [];
        foreach ($selected as $row) {
            $selectedSet[(int)$row['id']] = true;
        }
        $page = array_slice($selected, $p['offset'], $p['limit']);

        // 2) Context: predecessors of each page record in its admin/badge group on the same day.
        $ctxByRec = self::contextByRecord($page, $p['context_before'], $selectedSet);

        // 3) Load transcripts once for page + context records.
        $allIds = [];
        foreach ($page as $row) {
            $allIds[(int)$row['id']] = true;
            foreach ($ctxByRec[(int)$row['id']] ?? [] as $cid) {
                $allIds[$cid['id']] = true;
            }
        }
        $ids = array_keys($allIds);
        $full = RecordsRepository::findByIds($ids);
        $raw = [];
        foreach ($ids as $id) {
            $raw[$id] = (string)($full[$id]['transcription'] ?? '');
        }
        $corrected = TranscriptCorrector::applyToRecords($raw);
        $trash = RecordsRepository::trashPhrases();
        /** @var array<int,list<string>> $clean record id => cleaned transcript lines */
        $clean = [];
        foreach ($corrected as $id => $text) {
            $f = TranscriptGarbage::filterTranscription((string)$text, $trash);
            $clean[(int)$id] = $f['text'] === '' ? [] : explode("\n", $f['text']);
        }

        $reviews = RecordsReviewsRepository::mapByRecordIds($ids);
        $adminIds = [];
        foreach ($page as $row) {
            $adminIds[(int)$row['id_admin']] = true;
        }
        foreach ($ctxByRec as $list) {
            foreach ($list as $cid) {
                $adminIds[(int)$cid['row']['id_admin']] = true;
            }
        }
        unset($adminIds[0]);
        $names = $adminIds === [] ? [] : UsersRepository::staffNamesIncluding(array_keys($adminIds));

        // 4) Emit units (context records, then the selected one) until the byte budget is spent.
        $maxBytes = $p['max_bytes'];
        $budget = $maxBytes - self::ENVELOPE_RESERVE;
        $used = 0;
        $items = [];
        $emitted = [];
        $completed = 0;
        $partial = null;
        $contextOmitted = [];

        foreach ($page as $idx => $row) {
            $rid = (int)$row['id'];
            // A resumed record (line_from > 1) already had its context on the previous page.
            $startLine = $idx === 0 ? $p['line_from'] : 1;
            $selLines = $clean[$rid] ?? [];
            $selMeta = self::meta($row, false, $reviews, $names, count($selLines));
            $selFull = self::slice($selMeta, $selLines, $startLine, PHP_INT_MAX);
            $selSize = self::sizeOf($selFull);

            $ctx = [];
            if ($startLine === 1) {
                foreach ($ctxByRec[$rid] ?? [] as $c) {
                    if (isset($emitted[$c['id']])) {
                        continue;
                    }
                    $cLines = $clean[$c['id']] ?? [];
                    $cItem = self::slice(
                        self::meta($c['row'], true, $reviews, $names, count($cLines)),
                        $cLines,
                        1,
                        PHP_INT_MAX
                    );
                    $ctx[] = ['id' => (int)$c['id'], 'item' => $cItem, 'size' => self::sizeOf($cItem)];
                }
            }
            $ctxSize = 0;
            foreach ($ctx as $c) {
                $ctxSize += $c['size'];
            }

            if ($used + $ctxSize + $selSize <= $budget) {
                foreach ($ctx as $c) {
                    $items[] = $c['item'];
                    $emitted[$c['id']] = true;
                }
                $items[] = $selFull;
                $emitted[$rid] = true;
                $used += $ctxSize + $selSize;
                $completed++;
                continue;
            }
            if ($used > 0) {
                // The unit moves to the next page intact (context stays attached).
                break;
            }

            // First unit alone does not fit. Context is whole-or-omitted and gets a bounded share,
            // nearest predecessors first; the selected record gets the rest and is sliced by lines.
            $allow = (int)floor($budget * self::CONTEXT_SHARE);
            $keep = [];
            $ctxUsed = 0;
            for ($k = count($ctx) - 1; $k >= 0; $k--) {
                if ($ctxUsed + $ctx[$k]['size'] <= $allow) {
                    $keep[] = $ctx[$k];
                    $ctxUsed += $ctx[$k]['size'];
                } else {
                    $contextOmitted[] = $ctx[$k]['id'];
                }
            }
            foreach (array_reverse($keep) as $c) {
                $items[] = $c['item'];
                $emitted[$c['id']] = true;
                $used += $c['size'];
            }
            $sel = self::fitSelected($selMeta, $selLines, $startLine, $budget - $used);
            $items[] = $sel;
            $emitted[$rid] = true;
            $used += self::sizeOf($sel);
            if (!empty($sel['complete'])) {
                $completed++;
                continue;
            }
            $partial = $sel;
            break;
        }

        // Cursor: next_offset stays on a partially delivered record; null only when nothing is left.
        $nextOffset = $p['offset'] + $completed;
        $nextLine = null;
        if ($partial !== null) {
            $nextLine = (int)$partial['next_line_from'];
        } elseif ($nextOffset >= $total) {
            $nextOffset = null;
        }
        sort($contextOmitted);

        $out = [
            'ok' => true,
            'total' => $total,
            'returned' => count($items),
            'offset' => $p['offset'],
            'line_from' => $p['line_from'],
            'limit' => $p['limit'],
            'next_offset' => $nextOffset,
            'next_line_from' => $nextLine,
            'selection_complete' => $nextOffset === null,
            'transcripts_complete' => $partial === null,
            'context_omitted' => $contextOmitted,
            'bytes' => 0,
            'max_bytes' => $maxBytes,
            'missing_ids' => $missing,
            'items' => $items,
        ];
        if ($partial !== null) {
            $out['hint'] = 'Record ' . (int)$partial['id'] . ' is partial (lines ' . (int)$partial['line_from'] . '-'
                . (int)$partial['line_to'] . ' of ' . (int)$partial['lines'] . '). Call again with the same arguments, '
                . 'offset=' . (int)$nextOffset . ' and line_from=' . (int)$nextLine . '.';
        } elseif ($contextOmitted !== []) {
            $out['hint'] = 'Context records ' . implode(',', $contextOmitted) . ' were left out to fit the budget; '
                . 'read them with ids=<id> (add line_from to continue a long one).';
        }
        $out['bytes'] = strlen((string)json_encode($out, self::JSON_FLAGS));
        return $out;
    }

    /**
     * @return array{
     *   ids:list<int>, date:string, year:int, month:int, day:int,
     *   range_from:int, range_to:int, admin:int, unassigned:int, badge:int,
     *   review:string, disabled:int, search:string, context_before:int,
     *   limit:int, offset:int, line_from:int, max_bytes:int, error:string
     * }
     */
    private static function parse(array $in): array
    {
        $out = [
            'ids' => [], 'date' => '', 'year' => 0, 'month' => 0, 'day' => 0,
            'range_from' => 0, 'range_to' => 0, 'admin' => 0, 'unassigned' => 0, 'badge' => 0,
            'review' => '', 'disabled' => 0, 'search' => '', 'context_before' => 0,
            'limit' => self::LIMIT_DEFAULT, 'offset' => 0, 'line_from' => 1, 'max_bytes' => self::BYTES_DEFAULT,
            'error' => '',
        ];

        $rawIds = $in['ids'] ?? [];
        if (is_string($rawIds)) {
            $rawIds = preg_split('/[\s,;]+/', trim($rawIds)) ?: [];
        }
        if (is_array($rawIds)) {
            $seen = [];
            foreach ($rawIds as $v) {
                $id = (int)$v;
                if ($id > 0) {
                    $seen[$id] = $id;
                }
            }
            $out['ids'] = array_values($seen);
        }
        if (count($out['ids']) > self::MAX_IDS) {
            $out['error'] = t('audio.transcripts.err.too_many', ['n' => (string)count($out['ids']), 'max' => (string)self::MAX_IDS]);
            return $out;
        }

        $date = trim((string)($in['date'] ?? ''));
        if ($date !== '') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                $out['error'] = t('audio.transcripts.err.date');
                return $out;
            }
            $out['date'] = $date;
            $out['year'] = (int)$m[1];
            $out['month'] = (int)$m[2];
            $out['day'] = (int)$m[3];
        }

        $timeFrom = trim((string)($in['time_from'] ?? ''));
        $timeTo = trim((string)($in['time_to'] ?? ''));
        if ($timeFrom !== '' || $timeTo !== '') {
            if ($date === '') {
                $out['error'] = t('audio.transcripts.err.time_needs_date');
                return $out;
            }
            $base = mktime(0, 0, 0, $out['month'], $out['day'], $out['year']);
            $fromSec = $timeFrom !== '' ? self::hhmmToSeconds($timeFrom) : -1;
            $toSec = $timeTo !== '' ? self::hhmmToSeconds($timeTo) : -1;
            if ($fromSec === -2 || $toSec === -2) {
                $out['error'] = t('audio.transcripts.err.time');
                return $out;
            }
            if ($fromSec >= 0) {
                $out['range_from'] = $base + $fromSec;
            }
            if ($toSec >= 0) {
                $out['range_to'] = $base + $toSec + 59;
            }
        }

        $out['admin'] = max(0, (int)($in['admin'] ?? 0));
        $out['badge'] = max(0, (int)($in['badge'] ?? 0));
        $out['unassigned'] = !empty($in['unassigned']) ? 1 : 0;
        if ($out['unassigned']) {
            $out['admin'] = 0;
        }

        $review = trim((string)($in['review'] ?? ''));
        if ($review !== '' && !in_array($review, ['pending', 'marked'], true)) {
            $out['error'] = t('audio.transcripts.err.review');
            return $out;
        }
        $out['review'] = $review;

        $out['disabled'] = max(0, min(2, (int)($in['disabled'] ?? 0)));
        $out['search'] = trim((string)($in['search'] ?? ''));
        $out['context_before'] = max(0, min(self::CONTEXT_MAX, (int)($in['context_before'] ?? 0)));
        $out['limit'] = max(1, min(self::LIMIT_MAX, (int)($in['limit'] ?? self::LIMIT_DEFAULT)));
        $out['offset'] = max(0, (int)($in['offset'] ?? 0));
        $out['line_from'] = max(1, (int)($in['line_from'] ?? 1));
        $out['max_bytes'] = max(self::BYTES_MIN, min(self::BYTES_MAX, (int)($in['max_bytes'] ?? self::BYTES_DEFAULT)));

        if ($out['ids'] === [] && $out['date'] === '' && $out['review'] === '') {
            $out['error'] = t('audio.transcripts.err.selector');
        }
        return $out;
    }

    /** "HH:MM" → seconds from midnight; -2 when invalid. */
    public static function hhmmToSeconds(string $hhmm): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return -2;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) {
            return -2;
        }
        return $h * 3600 + $i * 60;
    }

    /**
     * Context per page record: up to N predecessors in the same day group that are not selected themselves.
     *
     * @param list<array<string,mixed>> $page
     * @param array<int,bool> $selectedSet
     * @return array<int,list<array{id:int,row:array<string,mixed>}>>
     */
    private static function contextByRecord(array $page, int $n, array $selectedSet): array
    {
        if ($n < 1 || $page === []) {
            return [];
        }
        $dates = [];
        foreach ($page as $row) {
            $ts = (int)$row['dt_recorded'];
            if ($ts > 0) {
                $dates[date('Y-m-d', $ts)] = true;
            }
        }

        $groupOf = static function (array $row): string {
            $admin = (int)$row['id_admin'];
            return $admin > 0 ? 'a' . $admin : 'b' . (int)$row['id_dictophone'];
        };

        /** @var array<string,list<array<string,mixed>>> $groups date|group => rows (chronological) */
        $groups = [];
        /** @var array<int,array{0:string,1:int}> $pos id => [groupKey, index] */
        $pos = [];
        foreach (array_keys($dates) as $date) {
            [$y, $mo, $d] = array_map('intval', explode('-', $date));
            $dayRows = RecordsRepository::list([
                'year' => $y,
                'month' => $mo,
                'day' => $d,
                'disabled' => 0,
                'limit' => 0,
                'order' => 'DT_RECORDED ASC, ID ASC',
            ])['items'];
            foreach ($dayRows as $row) {
                $key = $date . '|' . $groupOf($row);
                $groups[$key] ??= [];
                $pos[(int)$row['id']] = [$key, count($groups[$key])];
                $groups[$key][] = $row;
            }
        }

        $out = [];
        foreach ($page as $row) {
            $rid = (int)$row['id'];
            if (!isset($pos[$rid])) {
                continue;
            }
            [$key, $idx] = $pos[$rid];
            $list = [];
            for ($j = max(0, $idx - $n); $j < $idx; $j++) {
                $prev = $groups[$key][$j];
                $pid = (int)$prev['id'];
                if (isset($selectedSet[$pid])) {
                    continue;
                }
                $list[] = ['id' => $pid, 'row' => $prev];
            }
            if ($list !== []) {
                $out[$rid] = $list;
            }
        }
        return $out;
    }

    /**
     * Record metadata without the transcript body.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $reviews
     * @param array<int,string> $names
     * @return array<string,mixed>
     */
    private static function meta(array $row, bool $context, array $reviews, array $names, int $linesTotal): array
    {
        $id = (int)$row['id'];
        $ts = (int)$row['dt_recorded'];
        $adminId = (int)$row['id_admin'];
        $review = 'none';
        if (isset($reviews[$id])) {
            $review = (int)($reviews[$id]['dt_review'] ?? 0) > 0 ? 'reviewed' : 'pending';
        }
        return [
            'id' => $id,
            'dt_recorded' => $ts,
            'time' => $ts > 0 ? date('H:i:s', $ts) : '',
            'id_admin' => $adminId,
            'admin_name' => $adminId > 0 ? (string)($names[$adminId] ?? '') : '',
            'id_badge' => (int)$row['id_dictophone'],
            'playtime' => (int)($row['playtime'] ?? 0),
            'disabled' => !empty($row['disabled']) ? 1 : 0,
            'review' => $review,
            'score' => isset($row['score']) && $row['score'] !== null ? (int)$row['score'] : null,
            'context' => $context,
            'lines' => $linesTotal,
        ];
    }

    /**
     * Item with $count lines of the cleaned transcript starting at 1-based $from.
     * Adds the per-transcript completeness fields: line_from, line_to, complete, next_line_from.
     *
     * @param array<string,mixed> $meta
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private static function slice(array $meta, array $lines, int $from, int $count): array
    {
        $total = count($lines);
        $from = max(1, $from);
        $seg = $count > 0 && $from <= $total ? array_slice($lines, $from - 1, $count) : [];
        $to = min($total, $from - 1 + count($seg));
        $item = $meta;
        $item['line_from'] = $from;
        $item['line_to'] = $to;
        $item['complete'] = $to >= $total;
        if (!$item['complete']) {
            $item['next_line_from'] = $to + 1;
        }
        $item['transcription'] = implode("\n", $seg);
        return $item;
    }

    /**
     * Largest slice of whole lines that fits $room bytes. Always at least one line (when any are left),
     * so a cursor always moves forward even for a tiny budget.
     *
     * @param array<string,mixed> $meta
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private static function fitSelected(array $meta, array $lines, int $from, int $room): array
    {
        $remaining = max(0, count($lines) - (max(1, $from) - 1));
        if ($remaining === 0) {
            return self::slice($meta, $lines, $from, 0);
        }
        $lo = 1;
        $hi = $remaining;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if (self::sizeOf(self::slice($meta, $lines, $from, $mid)) <= $room) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return self::slice($meta, $lines, $from, $lo);
    }

    /** @param array<string,mixed> $item */
    private static function sizeOf(array $item): int
    {
        return strlen((string)json_encode($item, self::JSON_FLAGS)) + 1;
    }
}
