<?php
declare(strict_types=1);

/**
 * Dry-run BookingValidate / noop detection for assistant procedures.update drafts.
 * Fills EventBus assistant.write.preflight (blockers + optional workweeks.update suggest).
 */
final class ProceduresAssignPreflight
{
    /** Fields that count as a material patch for noop detection. */
    private const MATERIAL = [
        'id_operator', 'id_user', 'id_good', 'id_admin', 'summ',
        'dtstart', 'dtend', 'type', 'notice', 'enabled', 'action',
    ];

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreflight(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId !== 'procedures.update' && $opId !== 'procedures.create') {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $blockers = is_array($payload['blockers'] ?? null) ? $payload['blockers'] : [];
        $suggest = is_array($payload['suggest'] ?? null) ? $payload['suggest'] : [];
        $choices = is_array($payload['choices'] ?? null) ? $payload['choices'] : [];

        if ($opId === 'procedures.update') {
            $id = (int)($args['id'] ?? 0);
            $current = $id > 0 ? ProceduresRepository::find($id) : null;
            if ($current === null) {
                return $payload;
            }
            if (self::isNoop($args, $current)) {
                $payload['noop'] = true;
                return $payload;
            }
            $merged = self::mergeRow($current, $args);
            $check = self::explainWindow($merged, $id);
            if ($check['ok']) {
                return $payload;
            }
            $blockers[] = [
                'code' => (string)$check['code'],
                'message' => (string)$check['message'],
            ];
            // Only shift-window failures warrant a lane change; busy/overlap must not.
            if (in_array((string)$check['code'], ['slot_past_shift_end', 'not_on_shift'], true)) {
                foreach (self::suggestShiftFix($merged, (string)$check['code']) as $s) {
                    $suggest[] = $s;
                }
            } elseif ((string)$check['code'] === 'busy') {
                foreach (self::choicesFreeSlots($opId, $merged, $id) as $c) {
                    $choices[] = $c;
                }
            }
        } elseif ($opId === 'procedures.create') {
            $merged = self::mergeRow([], $args);
            if ((int)($merged['id_operator'] ?? 0) < 1 || (int)($merged['id_good'] ?? 0) < 1) {
                return $payload;
            }
            $check = self::explainWindow($merged, null);
            if (!$check['ok']) {
                $blockers[] = [
                    'code' => (string)$check['code'],
                    'message' => (string)$check['message'],
                ];
                if (in_array((string)$check['code'], ['slot_past_shift_end', 'not_on_shift'], true)) {
                    foreach (self::suggestShiftFix($merged, (string)$check['code']) as $s) {
                        $suggest[] = $s;
                    }
                } elseif ((string)$check['code'] === 'busy') {
                    foreach (self::choicesFreeSlots($opId, $merged, null) as $c) {
                        $choices[] = $c;
                    }
                }
            }
        }

        $payload['blockers'] = $blockers;
        $payload['suggest'] = $suggest;
        $payload['choices'] = $choices;
        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $current
     */
    private static function isNoop(array $args, array $current): bool
    {
        foreach (self::MATERIAL as $k) {
            if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
                continue;
            }
            if ($k === 'action') {
                $a = strtolower(trim((string)$args[$k]));
                if ($a !== '' && $a !== 'save' && $a !== 'update') {
                    return false;
                }
                continue;
            }
            if ($k === 'dtstart' || $k === 'dtend') {
                $newTs = self::toTs($args[$k]);
                $oldTs = (int)($current[$k] ?? 0);
                if ($newTs > 0 && $newTs !== $oldTs) {
                    return false;
                }
                continue;
            }
            $new = is_scalar($args[$k]) ? (string)$args[$k] : '';
            $old = is_scalar($current[$k] ?? null) ? (string)$current[$k] : '';
            if ($new !== $old) {
                return false;
            }
        }
        // Empty patch or all values equal current row.
        return true;
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function mergeRow(array $current, array $args): array
    {
        $out = $current;
        foreach (self::MATERIAL as $k) {
            if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
                continue;
            }
            if ($k === 'dtstart' || $k === 'dtend') {
                $ts = self::toTs($args[$k]);
                if ($ts > 0) {
                    $out[$k] = $ts;
                }
                continue;
            }
            $out[$k] = $args[$k];
        }
        $start = (int)($out['dtstart'] ?? 0);
        $end = (int)($out['dtend'] ?? 0);
        if ($start > 0 && $end <= $start) {
            $oldStart = (int)($current['dtstart'] ?? 0);
            $oldEnd = (int)($current['dtend'] ?? 0);
            if ($oldEnd > $oldStart) {
                $out['dtend'] = $start + ($oldEnd - $oldStart);
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{ok:bool,code:string,message:string}
     */
    private static function explainWindow(array $row, ?int $excludeId): array
    {
        if (!Config::moduleEnabled('booking') || !class_exists('BookingValidate', false)) {
            return ['ok' => true, 'code' => '', 'message' => ''];
        }
        $opId = (int)($row['id_operator'] ?? 0);
        $goodId = (int)($row['id_good'] ?? 0);
        $dtStart = (int)($row['dtstart'] ?? 0);
        $dtEnd = (int)($row['dtend'] ?? 0);
        if ($opId < 1 || $goodId < 1 || $dtStart < 1) {
            return ['ok' => true, 'code' => '', 'message' => ''];
        }
        if ($dtEnd <= $dtStart) {
            $dtEnd = $dtStart + 60;
        }
        $opts = [];
        if (class_exists('ProceduresChain', false) && ProceduresChain::isBreak($row)) {
            $opts['allow_past_work_end'] = true;
        }
        try {
            BookingValidate::assertProcedureWindow(
                $opId,
                $dtStart,
                $dtEnd,
                $goodId,
                $excludeId,
                $opts
            );
            return ['ok' => true, 'code' => '', 'message' => ''];
        } catch (Throwable $e) {
            $msg = trim($e->getMessage());
            $code = 'blocked';
            $notOn = t('booking.err.not_on_shift');
            if ($msg === $notOn || stripos($msg, 'not_on_shift') !== false) {
                $code = 'not_on_shift';
            } elseif (stripos($msg, 'shift_end') !== false
                || stripos($msg, 'slot_past_shift') !== false
                || (stripos($msg, 'смен') !== false && stripos($msg, 'конц') !== false)
            ) {
                $code = 'slot_past_shift_end';
            } elseif (stripos($msg, 'занят') !== false
                || stripos($msg, 'busy') !== false
                || stripos($msg, 'перенес') !== false
            ) {
                $code = 'busy';
            }
            return [
                'ok' => false,
                'code' => $code,
                'message' => $msg !== '' ? $msg : t('procedures.assistant.preflight.blocked'),
            ];
        }
    }

    /**
     * Dry-run free starts for the same operator/duration (busy conflict).
     * Cancel / «leave as is» is not a choice — UI already has Cancel.
     *
     * @param array<string,mixed> $row
     * @return list<array{op_id:string,args:array<string,mixed>,label:string}>
     */
    private static function choicesFreeSlots(string $writeOpId, array $row, ?int $excludeId): array
    {
        $opId = (int)($row['id_operator'] ?? 0);
        $goodId = (int)($row['id_good'] ?? 0);
        $dtStart = (int)($row['dtstart'] ?? 0);
        $dtEnd = (int)($row['dtend'] ?? 0);
        if ($opId < 1 || $goodId < 1 || $dtStart < 1) {
            return [];
        }
        if ($dtEnd <= $dtStart) {
            $dtEnd = $dtStart + 3600;
        }
        $dur = $dtEnd - $dtStart;
        if ($dur < 60) {
            $dur = 60;
        }
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00', $dtStart));
        $dayEnd = $dayStart + 86400;
        $busy = [];
        try {
            foreach (ProceduresRepository::listForDayRange($dayStart, $dayEnd, $opId) as $pr) {
                if ((int)($pr['id_operator'] ?? 0) !== $opId) {
                    continue;
                }
                $pid = (int)($pr['id'] ?? 0);
                if ($excludeId !== null && $pid === $excludeId) {
                    continue;
                }
                $a = (int)($pr['dtstart'] ?? 0);
                $b = (int)($pr['dtend'] ?? 0);
                if ($a > 0 && $b > $a) {
                    $busy[] = [$a, $b];
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        usort($busy, static function (array $x, array $y): int {
            return $x[0] <=> $y[0];
        });

        $candidates = [];
        $candidates[$dtStart] = true;
        foreach ($busy as $iv) {
            $candidates[(int)$iv[1]] = true;
        }
        // Also step +5 min from first conflict end while scanning the afternoon.
        $cursor = $dtStart;
        foreach ($busy as $iv) {
            if ($iv[1] > $cursor) {
                $cursor = (int)$iv[1];
            }
        }
        for ($i = 0; $i < 24; $i++) {
            $candidates[$cursor + $i * 300] = true;
        }

        $opts = [];
        if (class_exists('ProceduresChain', false) && ProceduresChain::isBreak($row)) {
            $opts['allow_past_work_end'] = true;
        }

        $out = [];
        $seenHm = [];
        ksort($candidates);
        foreach (array_keys($candidates) as $start) {
            $start = (int)$start;
            if ($start < $dayStart || $start + $dur > $dayEnd) {
                continue;
            }
            // Snap to whole minutes.
            $start -= $start % 60;
            $end = $start + $dur;
            $hm = date('H:i', $start);
            if (isset($seenHm[$hm])) {
                continue;
            }
            if ($start === $dtStart) {
                // Original slot already failed.
                continue;
            }
            try {
                if (!class_exists('BookingValidate', false)) {
                    break;
                }
                BookingValidate::assertProcedureWindow($opId, $start, $end, $goodId, $excludeId, $opts);
            } catch (Throwable $e) {
                continue;
            }
            $seenHm[$hm] = true;
            $args = ['dtstart' => date('Y-m-d H:i', $start), 'dtend' => date('Y-m-d H:i', $end)];
            if ($excludeId !== null && $excludeId > 0) {
                $args['id'] = $excludeId;
            }
            if ($opId > 0) {
                $args['id_operator'] = $opId;
            }
            if ($goodId > 0) {
                $args['id_good'] = $goodId;
            }
            foreach (['id_user', 'id_admin', 'summ', 'type', 'notice'] as $k) {
                if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
                    $args[$k] = $row[$k];
                }
            }
            $out[] = [
                'op_id' => $writeOpId,
                'args' => $args,
                'label' => t('procedures.assistant.preflight.choice_slot', ['hm' => $hm]),
            ];
            if (count($out) >= 2) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array{op_id:string,args:array<string,mixed>,label:string}>
     */
    private static function suggestShiftFix(array $row, string $code): array
    {
        if (!class_exists('WorkweeksRepository', false)) {
            return [];
        }
        $opId = (int)($row['id_operator'] ?? 0);
        $dtStart = (int)($row['dtstart'] ?? 0);
        $dtEnd = (int)($row['dtend'] ?? 0);
        if ($opId < 1 || $dtStart < 1) {
            return [];
        }
        if ($dtEnd <= $dtStart) {
            $dtEnd = $dtStart + 60;
        }
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00', $dtStart));
        // Prefer listForDay row (skips assist_team the same way BookingValidate does).
        $shift = null;
        foreach (WorkweeksRepository::listForDay($dayStart) as $s) {
            if ((int)($s['id_operator'] ?? 0) !== $opId) {
                continue;
            }
            if (!empty($s['assist_team'])) {
                continue;
            }
            $shift = $s;
            break;
        }
        if ($shift === null) {
            return [];
        }
        $shiftId = (int)($shift['id'] ?? 0);
        $currentPc = (int)($shift['price_col'] ?? 0);
        if ($shiftId < 1) {
            return [];
        }

        $coverPc = self::coveringPriceCol($dayStart, $dtStart, $dtEnd, $currentPc);
        if ($coverPc === null || $coverPc === $currentPc) {
            return [];
        }
        $fromLabel = WorkweeksRepository::shiftLabel($currentPc);
        $toLabel = WorkweeksRepository::shiftLabel($coverPc);
        $who = '';
        if (class_exists('UsersRepository', false)) {
            try {
                $who = trim(UsersRepository::acronymsList([$opId]));
            } catch (Throwable $e) {
                $who = '';
            }
        }
        if ($who === '') {
            $who = '#' . $opId;
        }
        return [[
            'op_id' => 'workweeks.update',
            'args' => [
                'id' => $shiftId,
                'price_col' => $coverPc,
            ],
            'label' => t('procedures.assistant.preflight.suggest_shift', [
                'who' => $who,
                'from' => $fromLabel,
                'to' => $toLabel,
                'date' => date('Y-m-d', $dayStart),
            ]),
        ]];
    }

    /**
     * Alternative lane that covers [dtStart, dtEnd].
     * If the current lane already covers the slot — return null (no change needed).
     * Masseur full-day lanes (e.g. М 10:00–22:00) must not be “upgraded” to II/I+II.
     */
    private static function coveringPriceCol(int $dayStart, int $dtStart, int $dtEnd, int $currentPc): ?int
    {
        if ($currentPc >= 1) {
            $cur = WorkweeksRepository::shiftRangeForDay($dayStart, $currentPc);
            if ($cur !== null
                && $dtStart >= (int)$cur['start']
                && $dtEnd <= (int)$cur['end'] + 1
            ) {
                return null;
            }
        }
        $best = null;
        $bestDur = PHP_INT_MAX;
        foreach (WorkweeksRepository::shiftWindows() as $pc => $w) {
            $pc = (int)$pc;
            if ($pc === $currentPc) {
                continue;
            }
            $range = WorkweeksRepository::shiftRangeForDay($dayStart, $pc);
            if ($range === null) {
                continue;
            }
            if ($dtStart < (int)$range['start'] || $dtEnd > (int)$range['end'] + 1) {
                continue;
            }
            $dur = (int)$range['end'] - (int)$range['start'];
            if ($dur < $bestDur) {
                $bestDur = $dur;
                $best = $pc;
            }
        }
        return $best;
    }

    /** @param mixed $raw */
    private static function toTs($raw): int
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $n = (int)$raw;
            return $n > 100000 ? $n : 0;
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return 0;
        }
        if (class_exists('ProceduresRepository', false)) {
            return ProceduresRepository::localToTs($s);
        }
        $ts = strtotime(str_replace('T', ' ', $s));
        return $ts !== false ? $ts : 0;
    }
}
