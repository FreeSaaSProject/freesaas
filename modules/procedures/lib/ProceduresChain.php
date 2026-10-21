<?php
declare(strict_types=1);

/**
 * Contiguous procedure blocks (same staff + client, abutting times) and chill rests.
 * Used when reassigning / shifting time so massage + dops + break move together.
 */
final class ProceduresChain
{
    /**
     * Procedure type IDs treated as break/pause (cfg procedures.break_type_ids, default 38).
     *
     * @return list<int>
     */
    public static function breakTypeIds(): array
    {
        $raw = trim((string)Config::get('procedures', 'break_type_ids', '38'));
        if ($raw === '') {
            return [38];
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $id = (int)$part;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return $out !== [] ? array_values($out) : [38];
    }

    /** @param array<string,mixed> $row */
    public static function isBreak(array $row): bool
    {
        $type = (int)($row['type'] ?? $row['TYPE'] ?? 0);
        if ($type > 0 && in_array($type, self::breakTypeIds(), true)) {
            return true;
        }
        $notice = (string)($row['notice'] ?? $row['NOTICE'] ?? '');
        if (strncmp($notice, 'chill#', 6) === 0) {
            return true;
        }
        $good = (int)($row['id_good'] ?? $row['ID_GOOD'] ?? 0);
        if ($good > 0
            && class_exists('TerminalsConfig', false)
            && TerminalsConfig::chillProductId() === $good
        ) {
            return true;
        }
        return false;
    }

    public static function isBreakTypeId(int $typeId): bool
    {
        return $typeId > 0 && in_array($typeId, self::breakTypeIds(), true);
    }

    public static function isBreakProductTypeId(int $productTypeId): bool
    {
        return self::isBreakTypeId($productTypeId);
    }

    /**
     * Ordered contiguous block containing $procId (same operator + client, DTEND=DTSTART).
     *
     * @return list<array<string,mixed>>
     */
    public static function contiguousAround(int $procId): array
    {
        if ($procId < 1 || !class_exists('ProceduresRepository', false)) {
            return [];
        }
        $anchor = ProceduresRepository::find($procId);
        if ($anchor === null) {
            return [];
        }
        $op = (int)($anchor['id_operator'] ?? 0);
        $user = (int)($anchor['id_user'] ?? 0);
        $dayStart = (int)strtotime(date('Y-m-d', (int)($anchor['dtstart'] ?? time())) . ' 00:00:00');
        if ($op < 1 || $user < 1 || $dayStart < 1) {
            return [$anchor];
        }
        $dayEnd = $dayStart + 86400;
        $rows = ProceduresRepository::listForDayRange($dayStart, $dayEnd, $op);
        $same = [];
        foreach ($rows as $r) {
            if (isset($r['enabled']) && (int)$r['enabled'] === 0) {
                continue;
            }
            if ((int)($r['id_user'] ?? 0) !== $user) {
                continue;
            }
            if ((int)($r['id_operator'] ?? 0) !== $op) {
                continue;
            }
            $same[] = $r;
        }
        usort($same, static function (array $a, array $b): int {
            return ((int)($a['dtstart'] ?? 0) <=> (int)($b['dtstart'] ?? 0))
                ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        });
        $idx = null;
        foreach ($same as $i => $r) {
            if ((int)($r['id'] ?? 0) === $procId) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return [$anchor];
        }
        $lo = $idx;
        while ($lo > 0) {
            $prevEnd = (int)($same[$lo - 1]['dtend'] ?? 0);
            $curStart = (int)($same[$lo]['dtstart'] ?? 0);
            if ($prevEnd < 1 || $curStart < 1 || $prevEnd !== $curStart) {
                break;
            }
            $lo--;
        }
        $hi = $idx;
        $n = count($same);
        while ($hi < $n - 1) {
            $curEnd = (int)($same[$hi]['dtend'] ?? 0);
            $nextStart = (int)($same[$hi + 1]['dtstart'] ?? 0);
            if ($curEnd < 1 || $nextStart < 1 || $curEnd !== $nextStart) {
                break;
            }
            $hi++;
        }
        return array_slice($same, $lo, $hi - $lo + 1);
    }

    /**
     * @return list<int>
     */
    public static function contiguousIds(int $procId): array
    {
        $ids = [];
        foreach (self::contiguousAround($procId) as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Edited procedure + its own chill companions only (notice chill#{id}).
     * Used when UI opts out of full contiguous-block relocate.
     *
     * @return list<array<string,mixed>>
     */
    public static function unitAround(int $procId): array
    {
        if ($procId < 1 || !class_exists('ProceduresRepository', false)) {
            return [];
        }
        $anchor = ProceduresRepository::find($procId);
        if ($anchor === null) {
            return [];
        }
        $op = (int)($anchor['id_operator'] ?? 0);
        $dayStart = (int)strtotime(date('Y-m-d', (int)($anchor['dtstart'] ?? time())) . ' 00:00:00');
        if ($op < 1 || $dayStart < 1) {
            return [$anchor];
        }
        $prefix = 'chill#' . $procId;
        $out = [$anchor];
        foreach (ProceduresRepository::listForDayRange($dayStart, $dayStart + 86400, $op) as $r) {
            $rid = (int)($r['id'] ?? 0);
            if ($rid < 1 || $rid === $procId) {
                continue;
            }
            if (isset($r['enabled']) && (int)$r['enabled'] === 0) {
                continue;
            }
            $notice = (string)($r['notice'] ?? '');
            if ($notice === $prefix
                || strncmp($notice, $prefix, strlen($prefix)) === 0
            ) {
                $out[] = $r;
            }
        }
        usort($out, static function (array $a, array $b): int {
            return ((int)($a['dtstart'] ?? 0) <=> (int)($b['dtstart'] ?? 0))
                ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        });
        return $out;
    }

    /**
     * Whether operator or start time in $data differs from $before (chain relocate trigger).
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $before
     */
    public static function needsRelocate(array $data, array $before): bool
    {
        $oldOp = (int)($before['id_operator'] ?? 0);
        $newOp = (int)($data['id_operator'] ?? $oldOp);
        $oldStart = (int)($before['dtstart'] ?? 0);
        $newStart = (int)($data['dtstart'] ?? $oldStart);
        return ($newOp > 0 && $newOp !== $oldOp)
            || ($newStart > 0 && $oldStart > 0 && $newStart !== $oldStart);
    }

    /**
     * Human lines for flash after a chain move (name + new time window).
     *
     * @param list<array<string,mixed>> $chain
     * @param array<string,mixed> $anchorData
     * @return list<string>
     */
    public static function moveSummaryLines(array $chain, int $anchorId, array $anchorData, int $delta): array
    {
        $lines = [];
        foreach ($chain as $r) {
            $rid = (int)($r['id'] ?? 0);
            if ($rid < 1) {
                continue;
            }
            if ($rid === $anchorId) {
                $start = (int)($anchorData['dtstart'] ?? $r['dtstart'] ?? 0);
                $end = (int)($anchorData['dtend'] ?? $r['dtend'] ?? 0);
                $name = trim((string)($r['product_name'] ?? ''));
                $goodId = (int)($anchorData['id_good'] ?? $r['id_good'] ?? 0);
            } else {
                $start = (int)($r['dtstart'] ?? 0) + $delta;
                $end = (int)($r['dtend'] ?? 0) + $delta;
                $name = trim((string)($r['product_name'] ?? ''));
                $goodId = (int)($r['id_good'] ?? 0);
            }
            if ($name === '' && $goodId > 0 && class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($goodId);
                $name = is_array($p) ? trim((string)($p['name'] ?? '')) : '';
            }
            if ($name === '') {
                $name = '#' . $goodId;
            }
            $time = $start > 0
                ? (date('H:i', $start) . ($end > $start ? ('–' . date('H:i', $end)) : ''))
                : '—';
            $lines[] = t('procedures.edit.chain_item', [
                'id' => $rid,
                'name' => $name,
                'time' => $time,
            ]);
        }
        return $lines;
    }

    /**
     * Reassign / time-shift a contiguous block when operator or start changes.
     * Caller must validate slots (with contiguous exclude ids) first.
     *
     * @param array<string,mixed> $anchorData full update payload for the edited row
     * @param array<string,mixed> $before row before edit
     * @param bool $moveFullChain false = only this procedure + chill#{id}
     * @return array{count:int,lines:list<string>}
     */
    public static function relocateOnUpdate(
        int $anchorId,
        array $anchorData,
        array $before,
        bool $moveFullChain = true
    ): array {
        $empty = static function () use ($anchorId, $anchorData): array {
            ProceduresRepository::update($anchorId, $anchorData);
            return ['count' => 1, 'lines' => []];
        };
        if ($anchorId < 1 || !class_exists('ProceduresRepository', false)) {
            return $empty();
        }
        if (!self::needsRelocate($anchorData, $before)) {
            return $empty();
        }

        $chain = $moveFullChain
            ? self::contiguousAround($anchorId)
            : self::unitAround($anchorId);
        if (count($chain) < 2) {
            $oldStart = (int)($before['dtstart'] ?? 0);
            $newStart = (int)($anchorData['dtstart'] ?? $oldStart);
            $delta = ($newStart > 0 && $oldStart > 0) ? ($newStart - $oldStart) : 0;
            $lines = self::moveSummaryLines([$before], $anchorId, $anchorData, $delta);
            ProceduresRepository::update($anchorId, $anchorData);
            return ['count' => 1, 'lines' => $lines];
        }

        $oldOp = (int)($before['id_operator'] ?? 0);
        $newOp = (int)($anchorData['id_operator'] ?? $oldOp);
        $oldStart = (int)($before['dtstart'] ?? 0);
        $newStart = (int)($anchorData['dtstart'] ?? $oldStart);
        $delta = ($newStart > 0 && $oldStart > 0) ? ($newStart - $oldStart) : 0;
        if ($newOp < 1) {
            $newOp = $oldOp;
        }

        $lines = self::moveSummaryLines($chain, $anchorId, $anchorData, $delta);
        $updated = 0;
        foreach ($chain as $r) {
            $rid = (int)($r['id'] ?? 0);
            if ($rid < 1) {
                continue;
            }
            if ($rid === $anchorId) {
                ProceduresRepository::update($rid, $anchorData);
                $updated++;
                continue;
            }
            $data = [
                'dtstart' => (int)($r['dtstart'] ?? 0) + $delta,
                'dtend' => (int)($r['dtend'] ?? 0) + $delta,
                'dtend_plan' => (int)($r['dtend_plan'] ?? 0) > 0
                    ? ((int)$r['dtend_plan'] + $delta)
                    : (int)($r['dtend_plan'] ?? 0),
                'id_operator' => $newOp > 0 ? $newOp : (int)($r['id_operator'] ?? 0),
                'id_admin' => (int)($r['id_admin'] ?? 0),
                'id_good' => (int)($r['id_good'] ?? 0),
                'id_table' => (int)($r['id_table'] ?? 0),
                'id_user' => (int)($r['id_user'] ?? 0),
                'id_terminal' => (int)($r['id_terminal'] ?? 0),
                'type' => (int)($r['type'] ?? 0),
                'summ' => (int)($r['summ'] ?? 0),
                'summ_default' => (int)($r['summ_default'] ?? 0),
                'notice' => (string)($r['notice'] ?? ''),
                'enabled' => (int)($r['enabled'] ?? 1),
                'token' => (string)($r['token'] ?? ''),
                'id_event_parent' => (int)($r['id_event_parent'] ?? 0),
                'onec_id' => (string)($r['onec_id'] ?? ''),
                'onec_bracelet' => (string)($r['onec_bracelet'] ?? ''),
                'restored' => (int)($r['restored'] ?? 0),
            ];
            ProceduresRepository::update($rid, $data);
            $updated++;
        }
        return ['count' => $updated, 'lines' => $lines];
    }
}
