<?php
declare(strict_types=1);

/**
 * Dry-run workweeks.create / workweeks.update drafts (EventBus assistant.write.preflight).
 * Only data conflicts here: the speaker may lack assign rights, the confirmer decides those.
 */
final class WorkweeksAssistantPreflight
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreflight(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId !== 'workweeks.create' && $opId !== 'workweeks.update' && $opId !== 'workweeks.delete') {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $isDelete = $opId === 'workweeks.delete'
            || strtolower(trim((string)($args['action'] ?? ''))) === 'delete';
        if ($isDelete) {
            $id = (int)($args['id'] ?? 0);
            if ($id < 1 || WorkweeksRepository::find($id) === null) {
                return self::block(
                    $payload,
                    'workweeks_delete_id',
                    t('workweeks.preflight.delete_need_id')
                );
            }
            return $payload;
        }
        if ($opId === 'workweeks.delete') {
            return $payload;
        }

        $id = 0;
        $row = null;
        $defaults = null;
        if ($opId === 'workweeks.update') {
            $id = (int)($args['id'] ?? 0);
            $row = $id > 0 ? WorkweeksRepository::find($id) : null;
            if ($row === null) {
                return self::block(
                    $payload,
                    'workweeks_missing',
                    t('workweeks.preflight.missing_id')
                );
            }
            $defaults = [
                'date' => date('Y-m-d', (int)$row['dt']),
                'id_operator' => (int)$row['id_operator'],
                'price_col' => (int)$row['price_col'],
                'mas_max' => (int)$row['mas_max'],
                'id_table' => (int)$row['id_table'],
            ];
        }

        try {
            $data = WorkweeksRepository::dataFromPost($args, $defaults);
        } catch (InvalidArgumentException $e) {
            return self::block($payload, 'workweeks_invalid', $e->getMessage());
        }

        $dup = WorkweeksRepository::findByDayOperator((int)$data['dt'], (int)$data['id_operator']);
        if ($dup === null || (int)$dup['id'] === $id) {
            return $payload;
        }
        return self::block($payload, 'workweeks_duplicate', self::duplicateMessage($data, $dup, $row));
    }

    /**
     * @param array{dt:int,date:string,id_operator:int,price_col:int} $data
     * @param array<string,mixed> $dup
     * @param array<string,mixed>|null $row
     */
    private static function duplicateMessage(array $data, array $dup, ?array $row): string
    {
        $dayStart = (int)$data['dt'];
        $dupId = (int)$dup['id'];
        $dupPc = (int)$dup['price_col'];
        $who = self::acronym((int)$data['id_operator']);
        $msg = t('workweeks.preflight.duplicate', [
            'who' => $who,
            'date' => date('d.m.Y', $dayStart),
            'lane' => WorkweeksRepository::shiftLabel($dupPc),
            'id' => $dupId,
        ]);

        $cover = self::coveringPriceCol($dayStart, $dupPc, (int)$data['price_col']);
        $swap = $row !== null && (int)$row['id_operator'] !== (int)$data['id_operator'];
        if ($swap) {
            $old = self::acronym((int)$row['id_operator']);
            if ($cover === $dupPc) {
                return $msg . ' ' . t('workweeks.preflight.swap_delete_only', [
                    'who' => $who,
                    'old' => $old,
                    'id' => (int)$row['id'],
                ]);
            }
            if ($cover !== null) {
                return $msg . ' ' . t('workweeks.preflight.swap_merge', [
                    'who' => $who,
                    'old' => $old,
                    'dup_id' => $dupId,
                    'pc' => $cover,
                    'lane' => WorkweeksRepository::shiftLabel($cover),
                    'id' => (int)$row['id'],
                ]);
            }
            return $msg . ' ' . t('workweeks.preflight.swap_manual', [
                'dup_id' => $dupId,
                'id' => (int)$row['id'],
            ]);
        }
        if ($cover !== null && $cover !== $dupPc) {
            return $msg . ' ' . t('workweeks.preflight.extend', [
                'dup_id' => $dupId,
                'pc' => $cover,
                'lane' => WorkweeksRepository::shiftLabel($cover),
            ]);
        }
        return $msg . ' ' . t('workweeks.preflight.edit_existing', ['dup_id' => $dupId]);
    }

    /** Smallest configured lane whose window covers both lanes (null if none). */
    private static function coveringPriceCol(int $dayStart, int $pcA, int $pcB): ?int
    {
        $a = WorkweeksRepository::shiftRangeForDay($dayStart, $pcA);
        $b = WorkweeksRepository::shiftRangeForDay($dayStart, $pcB);
        if ($a === null || $b === null) {
            return null;
        }
        $start = min((int)$a['start'], (int)$b['start']);
        $end = max((int)$a['end'], (int)$b['end']);
        $best = null;
        $bestDur = PHP_INT_MAX;
        foreach (array_keys(WorkweeksRepository::shiftWindows()) as $pc) {
            $r = WorkweeksRepository::shiftRangeForDay($dayStart, (int)$pc);
            if ($r === null || (int)$r['start'] > $start || (int)$r['end'] < $end) {
                continue;
            }
            $dur = (int)$r['end'] - (int)$r['start'];
            if ($dur < $bestDur) {
                $bestDur = $dur;
                $best = (int)$pc;
            }
        }
        return $best;
    }

    private static function acronym(int $userId): string
    {
        if ($userId < 1) {
            return '—';
        }
        if (class_exists('UsersRepository', false)) {
            $a = trim(UsersRepository::acronymsList([$userId]));
            if ($a !== '') {
                return $a;
            }
        }
        return '#' . $userId;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function block(array $payload, string $code, string $message): array
    {
        $blockers = is_array($payload['blockers'] ?? null) ? $payload['blockers'] : [];
        $blockers[] = ['code' => $code, 'message' => $message];
        $payload['blockers'] = $blockers;
        $payload['replan'] = true;
        return $payload;
    }
}
