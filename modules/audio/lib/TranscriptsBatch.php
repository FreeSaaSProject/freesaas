<?php
declare(strict_types=1);

/**
 * Batch transcripts for agents (MCP audio__transcripts__batch): one call instead of N audio.get.
 *
 * Selection: explicit ids, or day / time window / admin / badge / review filters.
 * context_before: N preceding records of the same admin (or same badge when unassigned) on the same day,
 * flagged context=true. Output is capped by max_bytes; pagination via next_offset.
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
    private const ENVELOPE_RESERVE = 512;

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
        $clean = [];
        foreach ($corrected as $id => $text) {
            $f = TranscriptGarbage::filterTranscription((string)$text, $trash);
            $clean[(int)$id] = ['text' => $f['text'], 'lines' => (int)$f['lines']];
        }

        $reviews = RecordsReviewsRepository::mapByRecordIds($ids);
        $adminIds = [];
        foreach ($selected as $row) {
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
        $selectedEmitted = 0;
        $budgetCut = false;
        $anyTranscriptionTruncated = false;

        foreach ($page as $row) {
            $rid = (int)$row['id'];
            $unit = [];
            foreach ($ctxByRec[$rid] ?? [] as $cid) {
                if (!isset($emitted[$cid['id']])) {
                    $unit[] = ['row' => $cid['row'], 'context' => true];
                }
            }
            $unit[] = ['row' => $row, 'context' => false];

            $built = [];
            $unitSize = 0;
            foreach ($unit as $u) {
                $item = self::item($u['row'], $u['context'], $clean, $reviews, $names);
                $size = strlen((string)json_encode($item, self::JSON_FLAGS)) + 1;
                $built[] = ['item' => $item, 'size' => $size, 'context' => $u['context']];
                $unitSize += $size;
            }

            if ($used + $unitSize <= $budget) {
                foreach ($built as $b) {
                    $items[] = $b['item'];
                    $emitted[(int)$b['item']['id']] = true;
                    $used += $b['size'];
                }
                $selectedEmitted++;
                continue;
            }
            if ($used > 0) {
                $budgetCut = true;
                break;
            }

            // First unit alone exceeds the budget: keep the context records that fit, then cut the selected transcript.
            foreach ($built as $b) {
                if (!$b['context']) {
                    continue;
                }
                if ($used + $b['size'] <= $budget) {
                    $items[] = $b['item'];
                    $emitted[(int)$b['item']['id']] = true;
                    $used += $b['size'];
                }
            }
            $selItem = $built[count($built) - 1]['item'];
            $fitted = self::fitItem($selItem, $budget - $used);
            $items[] = $fitted;
            $emitted[(int)$fitted['id']] = true;
            $used += strlen((string)json_encode($fitted, self::JSON_FLAGS)) + 1;
            $selectedEmitted++;
            $budgetCut = true;
            break;
        }

        foreach ($items as $it) {
            if (!empty($it['transcription_truncated'])) {
                $anyTranscriptionTruncated = true;
            }
        }
        $nextOffset = $p['offset'] + $selectedEmitted < $total ? $p['offset'] + $selectedEmitted : null;

        $out = [
            'ok' => true,
            'total' => $total,
            'returned' => count($items),
            'offset' => $p['offset'],
            'limit' => $p['limit'],
            'next_offset' => $nextOffset,
            'truncated' => $budgetCut || $anyTranscriptionTruncated,
            'bytes' => 0,
            'max_bytes' => $maxBytes,
            'missing_ids' => $missing,
            'items' => $items,
        ];
        $out['bytes'] = strlen((string)json_encode($out, self::JSON_FLAGS));
        return $out;
    }

    /**
     * @return array{
     *   ids:list<int>, date:string, year:int, month:int, day:int,
     *   range_from:int, range_to:int, admin:int, unassigned:int, badge:int,
     *   review:string, disabled:int, search:string, context_before:int,
     *   limit:int, offset:int, max_bytes:int, error:string
     * }
     */
    private static function parse(array $in): array
    {
        $out = [
            'ids' => [], 'date' => '', 'year' => 0, 'month' => 0, 'day' => 0,
            'range_from' => 0, 'range_to' => 0, 'admin' => 0, 'unassigned' => 0, 'badge' => 0,
            'review' => '', 'disabled' => 0, 'search' => '', 'context_before' => 0,
            'limit' => self::LIMIT_DEFAULT, 'offset' => 0, 'max_bytes' => self::BYTES_DEFAULT,
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
        $out['max_bytes'] = max(self::BYTES_MIN, min(self::BYTES_MAX, (int)($in['max_bytes'] ?? self::BYTES_DEFAULT)));

        if ($out['ids'] === [] && $out['date'] === '' && $out['review'] === '') {
            $out['error'] = t('audio.transcripts.err.selector');
        }
        return $out;
    }

    /** "HH:MM" → seconds from midnight; -2 when invalid. */
    private static function hhmmToSeconds(string $hhmm): int
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
     * @param array<string,mixed> $row
     * @param array<int,array{text:string,lines:int}> $clean
     * @param array<int,array<string,mixed>> $reviews
     * @param array<int,string> $names
     * @return array<string,mixed>
     */
    private static function item(array $row, bool $context, array $clean, array $reviews, array $names): array
    {
        $id = (int)$row['id'];
        $ts = (int)$row['dt_recorded'];
        $adminId = (int)$row['id_admin'];
        $review = 'none';
        if (isset($reviews[$id])) {
            $review = (int)($reviews[$id]['dt_review'] ?? 0) > 0 ? 'reviewed' : 'pending';
        }
        $text = $clean[$id] ?? ['text' => '', 'lines' => 0];
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
            'lines' => $text['lines'],
            'transcription' => $text['text'],
        ];
    }

    /**
     * Shrink an item's transcription (whole lines) until it fits into $room bytes.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function fitItem(array $item, int $room): array
    {
        $lines = $item['transcription'] === '' ? [] : explode("\n", (string)$item['transcription']);
        $lo = 0;
        $hi = count($lines);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            $cand = $item;
            $cand['transcription'] = implode("\n", array_slice($lines, 0, $mid));
            if (strlen((string)json_encode($cand, self::JSON_FLAGS)) + 1 <= $room) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $item['transcription'] = implode("\n", array_slice($lines, 0, $lo));
        $item['transcription_truncated'] = $lo < count($lines);
        if (!$item['transcription_truncated']) {
            unset($item['transcription_truncated']);
        }
        return $item;
    }
}
