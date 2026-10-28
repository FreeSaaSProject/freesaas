<?php
declare(strict_types=1);

/**
 * Tool-result encoding and arg preview helpers for the LLM round-trip.
 */
final class AssistantToolPayload
{
    /**
     * Fallback when no module handled assistant.write.preview — raw scalar args only.
     *
     * @param array<string,mixed> $args
     * @return list<string>
     */
    public static function previewGenericArgs(array $args): array
    {
        $lines = [];
        foreach (self::pickScalarArgs($args, 8) as $pair) {
            $lines[] = self::formatArgBit($pair['key'], $pair['value'], true);
        }
        return $lines;
    }

    /**
     * Universal keys first, then remaining scalar args in input order (no product-field allowlists).
     *
     * @param array<string,mixed> $args
     * @return list<array{key:string,value:string}>
     */
    private static function pickScalarArgs(array $args, int $limit): array
    {
        if ($args === [] || $limit < 1) {
            return [];
        }
        $priority = [
            'id', 'q', 'name', 'date', 'dt', 'day', 'date_from', 'date_to',
            'year', 'month', 'descr', 'comment', 'status', 'action',
        ];
        $skip = [
            'format' => true, '_csrf' => true, 'csrf' => true,
            'token' => true, 'password' => true, 'passwd' => true,
        ];
        $dateKeys = [
            'date' => true, 'dt' => true, 'day' => true,
            'date_from' => true, 'date_to' => true,
        ];
        $out = [];
        $seen = [];
        $push = static function (string $k, $raw) use (&$out, &$seen, $limit, $dateKeys): void {
            if (isset($seen[$k]) || count($out) >= $limit) {
                return;
            }
            if ($raw === '' || $raw === null || is_array($raw) || is_object($raw)) {
                return;
            }
            if (!is_scalar($raw)) {
                return;
            }
            $v = (string)$raw;
            if (isset($dateKeys[$k])) {
                $v = self::formatDayValue($v);
            }
            if ($v === '') {
                return;
            }
            $seen[$k] = true;
            $out[] = ['key' => $k, 'value' => $v];
        };

        foreach ($priority as $k) {
            if (array_key_exists($k, $args)) {
                $push($k, $args[$k]);
            }
        }
        foreach ($args as $k => $raw) {
            $k = (string)$k;
            if ($k === '' || isset($skip[$k])) {
                continue;
            }
            $push($k, $raw);
        }
        return $out;
    }

    private static function formatArgBit(string $key, string $value, bool $withKeyFallback): string
    {
        $argKey = 'assistant.tool.arg.' . $key;
        $tpl = t($argKey, ['v' => $value]);
        if ($tpl !== $argKey) {
            return $tpl;
        }
        return $withKeyFallback ? ($key . '=' . $value) : $value;
    }

    /** @param mixed $raw */
    private static function formatDayValue($raw): string
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $ts = (int)$raw;
            if ($ts > 100000) {
                return date('Y-m-d', $ts);
            }
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
            return $m[0];
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : $s;
    }

    public static function publicArgs(array $args): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            $key = (string)$k;
            if ($key === '' || $key === 'format' || $key === '_csrf' || $key === 'csrf') {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$key] = $v;
            } elseif (is_array($v)) {
                $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($json) && strlen($json) <= 400) {
                    $out[$key] = $v;
                }
            }
            if (count($out) >= 24) {
                break;
            }
        }
        return $out;
    }

    /** @param list<mixed> $list */
    private static function looksLikeRowList(array $list): bool
    {
        $n = 0;
        foreach ($list as $row) {
            if (!is_array($row)) {
                return false;
            }
            $n++;
            if ($n >= 3) {
                return true;
            }
        }
        return $n > 0;
    }

    /**
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private static function slimRowsForTool(array $list, int $max): array
    {
        $out = [];
        foreach (array_slice($list, 0, max(1, $max)) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = self::slimRowForTool($row);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function slimRowForTool(array $row): array
    {
        $prefer = [
            'id', 'uuid', 'token', 'date', 'dt', 'dtstart', 'dtend', 'dtstart_iso', 'dtend_iso', 'dt_iso',
            'id_operator', 'operator_name', 'operator_acronym', 'acronym', 'id_user', 'client_name',
            'is_block', 'name', 'phone',
            'title', 'status', 'price_col', 'shift_label', 'shift_start_hm', 'shift_end_hm',
            'sum', 'summ', 'amount', 'content_brief', 'product_name', 'type_name', 'id_event_parent',
            'enabled', 'weekday', 'is_today',
        ];
        $slim = [];
        foreach ($prefer as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if (is_scalar($v) || $v === null) {
                $slim[$k] = $v;
            }
        }
        if ($slim !== []) {
            return $slim;
        }
        $n = 0;
        foreach ($row as $k => $v) {
            if ($n >= 8) {
                break;
            }
            if (is_scalar($v) || $v === null) {
                $slim[(string)$k] = $v;
                $n++;
            }
        }
        return $slim;
    }

    /**
     * @param list<array<string,mixed>> $days
     * @return list<array<string,mixed>>
     */
    private static function slimDaysForTool(array $days): array
    {
        $out = [];
        foreach (array_slice($days, 0, 7) as $day) {
            if (!is_array($day)) {
                continue;
            }
            $one = self::slimRowForTool($day);
            foreach (['events', 'procedures', 'orders', 'shifts'] as $k) {
                if (!isset($day[$k]) || !is_array($day[$k])) {
                    continue;
                }
                $rows = array_values($day[$k]);
                $max = $k === 'events' ? 40 : 20;
                $one[$k . '_count'] = count($rows);
                if (self::looksLikeRowList($rows)) {
                    $one[$k] = self::slimRowsForTool($rows, $max);
                }
            }
            $out[] = $one;
        }
        return $out;
    }

    /**
     * Aggregate common money/qty fields across list rows (for truncated tool payloads).
     *
     * @param list<mixed> $list
     * @return array<string,float|array<string,float>>
     */
    private static function sumNumericColumns(array $list): array
    {
        $moneyKeys = ['sum' => true, 'summ' => true, 'amount' => true, 'total' => true, 'price' => true, 'value' => true];
        $sums = [];
        $byDirection = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $k => $v) {
                if (!is_numeric($v)) {
                    continue;
                }
                $key = strtolower((string)$k);
                if (!isset($moneyKeys[$key])) {
                    continue;
                }
                if (!isset($sums[$key])) {
                    $sums[$key] = 0.0;
                }
                $sums[$key] += (float)$v;
            }
            if (isset($row['sum']) && is_numeric($row['sum']) && array_key_exists('direction', $row)) {
                $dir = (string)$row['direction'];
                if (!isset($byDirection[$dir])) {
                    $byDirection[$dir] = 0.0;
                }
                $byDirection[$dir] += (float)$row['sum'];
            }
        }
        foreach ($sums as $k => $v) {
            $sums[$k] = round((float)$v, 2);
        }
        if ($byDirection !== []) {
            foreach ($byDirection as $k => $v) {
                $byDirection[$k] = round((float)$v, 2);
            }
            // payments: direction 1 = accrued, else issued
            $sums['by_direction'] = $byDirection;
            if (isset($byDirection['1'])) {
                $sums['accrued'] = $byDirection['1'];
            }
            $issued = 0.0;
            foreach ($byDirection as $dir => $v) {
                if ((string)$dir === '1') {
                    continue;
                }
                $issued += (float)$v;
            }
            if ($issued > 0 || isset($byDirection['-1']) || isset($byDirection['0'])) {
                $sums['issued'] = round($issued, 2);
            }
        }
        return $sums;
    }

    /**
     * Encode tool result for the next LLM round. Never byte-chop JSON mid-string:
     * that breaks UTF-8 (Cyrillic) and the following LlmClient::chat fails with
     * «JSON encode failed».
     *
     * @param mixed $payload
     */
    public static function encodeToolContent($payload, int $maxBytes = 12000): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $encoded = json_encode($payload, $flags);
        if ($encoded === false) {
            return '{"ok":false,"error":"encode failed"}';
        }
        if (strlen($encoded) <= $maxBytes) {
            return $encoded;
        }

        $compact = [
            'ok' => true,
            '_truncated' => true,
            'bytes' => strlen($encoded),
            'hint' => 'Large result compacted. Answer from totals/months/sum_* — do not say you cannot sum; ask to narrow only for a per-row breakdown.',
        ];
        if (is_array($payload)) {
            if (array_key_exists('ok', $payload)) {
                $compact['ok'] = !empty($payload['ok']);
            }
            if (isset($payload['error'])) {
                $compact['ok'] = false;
                $compact['error'] = is_scalar($payload['error'])
                    ? (string)$payload['error']
                    : 'error';
            }
            if (isset($payload['total'])) {
                $compact['total'] = $payload['total'];
            }
            if (isset($payload['totals']) && is_array($payload['totals'])) {
                $compact['totals'] = $payload['totals'];
            }
            if (isset($payload['filters']) && is_array($payload['filters'])) {
                $compact['filters'] = $payload['filters'];
            }
            if (isset($payload['months']) && is_array($payload['months'])) {
                $monthSummaries = [];
                foreach ($payload['months'] as $month) {
                    if (!is_array($month)) {
                        continue;
                    }
                    $monthSummaries[] = [
                        'key' => $month['key'] ?? null,
                        'total_in' => $month['total_in'] ?? null,
                        'total_out' => $month['total_out'] ?? null,
                        'internal_out' => $month['internal_out'] ?? null,
                        'debt_start' => $month['debt_start'] ?? null,
                        'debt_end' => $month['debt_end'] ?? null,
                        'accrued_count' => isset($month['accrued']) && is_array($month['accrued'])
                            ? count($month['accrued']) : null,
                        'issued_count' => isset($month['issued']) && is_array($month['issued'])
                            ? count($month['issued']) : null,
                    ];
                }
                if ($monthSummaries !== []) {
                    $compact['months'] = $monthSummaries;
                }
            }
            $skipListKey = [
                'ok' => true, 'error' => true, 'total' => true, 'totals' => true,
                'months' => true, 'filters' => true, 'dps_by_payment' => true,
            ];
            $listKeys = ['items', 'rows', 'list', 'data', 'events', 'procedures', 'orders', 'shifts', 'days'];
            foreach ($payload as $k => $v) {
                if (!is_string($k) || !is_array($v) || $v === [] || isset($skipListKey[$k])) {
                    continue;
                }
                if (self::looksLikeRowList($v) && !in_array($k, $listKeys, true)) {
                    $listKeys[] = $k;
                }
            }
            foreach ($listKeys as $key) {
                if (!isset($payload[$key]) || !is_array($payload[$key])) {
                    continue;
                }
                $list = array_values($payload[$key]);
                if ($key === 'days' && $list !== []) {
                    $compact['days_count'] = count($list);
                    $compact['days'] = self::slimDaysForTool($list);
                    $skipListKey[$key] = true;
                    continue;
                }
                if ($list === [] || !self::looksLikeRowList($list)) {
                    continue;
                }
                $compact[$key . '_count'] = count($list);
                $sums = self::sumNumericColumns($list);
                if ($sums !== []) {
                    $compact['sum_' . $key] = $sums;
                }
                // Keep id-bearing slim rows (not a 3-row sample) so calendar delete can resolve.
                $maxRows = ($key === 'events' || $key === 'shifts') ? 80 : 40;
                $compact[$key] = self::slimRowsForTool($list, $maxRows);
                if (count($list) > $maxRows) {
                    $compact[$key . '_omitted'] = count($list) - $maxRows;
                }
                $skipListKey[$key] = true;
            }
            $meta = [];
            $n = 0;
            foreach ($payload as $k => $v) {
                if ($n >= 16) {
                    break;
                }
                if (isset($skipListKey[$k])) {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $meta[$k] = $v;
                    $n++;
                }
            }
            if ($meta !== []) {
                $compact['meta'] = $meta;
            }
            $compact['hint'] = 'Large result compacted to id/time/name fields. Use listed ids; narrow with staff=/date if omitted>0.';
        }

        $out = json_encode($compact, $flags);
        if ($out !== false && strlen($out) <= $maxBytes) {
            return $out;
        }
        // Still too big: peel lists down to id/time essentials, keep events if present.
        if (is_array($payload)) {
            $minimal = [
                'ok' => !isset($payload['ok']) || !empty($payload['ok']),
                '_truncated' => true,
                'bytes' => strlen($encoded),
                'hint' => 'Result too large; showing id/time essentials. Narrow with staff= or date.',
            ];
            foreach (['date', 'monday', 'anchor_date', 'staff_id', 'can_delete', 'can_edit', 'can_create'] as $k) {
                if (isset($payload[$k]) && (is_scalar($payload[$k]) || $payload[$k] === null)) {
                    $minimal[$k] = $payload[$k];
                }
            }
            foreach (['events', 'shifts', 'items', 'rows'] as $key) {
                if (!isset($payload[$key]) || !is_array($payload[$key])) {
                    continue;
                }
                $list = array_values($payload[$key]);
                $minimal[$key . '_count'] = count($list);
                $minimal[$key] = self::slimRowsForTool($list, $key === 'events' ? 60 : 30);
            }
            if (isset($payload['days']) && is_array($payload['days'])) {
                $minimal['days_count'] = count($payload['days']);
                $minimal['days'] = self::slimDaysForTool(array_values($payload['days']));
            }
            $out2 = json_encode($minimal, $flags);
            if ($out2 !== false && strlen($out2) <= $maxBytes) {
                return $out2;
            }
            // Last resort: shrink events until they fit (id/time/name only).
            if (isset($payload['events']) && is_array($payload['events'])) {
                $all = [];
                foreach ($payload['events'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $all[] = [
                        'id' => (int)($row['id'] ?? 0),
                        'is_block' => (int)($row['is_block'] ?? 0),
                        'operator_name' => (string)($row['operator_name'] ?? ''),
                        'dtstart_iso' => (string)($row['dtstart_iso'] ?? ''),
                        'dtend_iso' => (string)($row['dtend_iso'] ?? ''),
                    ];
                }
                $total = count($payload['events']);
                $keep = min(100, count($all));
                while ($keep >= 1) {
                    $bare = [
                        'ok' => true,
                        '_truncated' => true,
                        'bytes' => strlen($encoded),
                        'date' => $payload['date'] ?? null,
                        'events_count' => $total,
                        'events' => array_slice($all, 0, $keep),
                        'events_omitted' => max(0, $total - $keep),
                        'hint' => 'Only event id/time kept. Narrow staff= if omitted>0.',
                    ];
                    $out3 = json_encode($bare, $flags);
                    if ($out3 !== false && strlen($out3) <= $maxBytes) {
                        return $out3;
                    }
                    $keep = $keep > 1 ? max(1, (int)floor($keep / 2)) : 0;
                }
            }
        }
        return '{"ok":true,"_truncated":true,"bytes":' . strlen($encoded)
            . ',"hint":"Result too large; narrow filters (staff=/date)."}';
    }

}
