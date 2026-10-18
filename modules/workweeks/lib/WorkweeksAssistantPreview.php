<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for workweeks.* writes (EventBus assistant.write.preview).
 */
final class WorkweeksAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId === '' || strpos($opId, 'workweeks.') !== 0) {
            return $payload;
        }
        // Settings writes are opaque keybags — leave generic fallback.
        if (strpos($opId, 'workweeks.settings.') === 0) {
            return $payload;
        }

        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $lines = self::linesFor($opId, $args);
        if ($lines === []) {
            return $payload;
        }

        $existing = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $existing[] = $line;
        }
        $payload['lines'] = $existing;
        $payload['handled'] = true;
        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesFor(string $opId, array $args): array
    {
        $lines = [];
        $id = (int)($args['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            try {
                $current = WorkweeksRepository::find($id);
            } catch (Throwable $e) {
                $current = null;
            }
        }

        if (is_array($current) && $current !== []) {
            $nowLine = self::formatShiftRow($current);
            if ($nowLine !== '') {
                $lines[] = t('workweeks.assistant.preview.now', ['v' => $nowLine]);
            }
        }

        if ($opId === 'workweeks.delete' || $opId === 'workweeks.remove') {
            if ($lines === []) {
                $lines[] = t('workweeks.assistant.preview.delete');
            }
            return $lines;
        }

        if ($opId !== 'workweeks.update' && $opId !== 'workweeks.create') {
            return $lines;
        }

        $next = is_array($current) ? $current : [];
        foreach (['id_operator', 'date', 'dt', 'price_col', 'mas_max', 'id_table'] as $k) {
            if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
                continue;
            }
            if ($k === 'date') {
                $next['dt'] = $args[$k];
            } else {
                $next[$k] = $args[$k];
            }
        }

        $nextLine = self::formatShiftRow($next);
        $nowLine = (is_array($current) && $current !== []) ? self::formatShiftRow($current) : '';
        if ($nextLine !== '' && $nextLine !== $nowLine) {
            $label = $opId === 'workweeks.create'
                ? t('workweeks.assistant.preview.create', ['v' => $nextLine])
                : t('workweeks.assistant.preview.will', ['v' => $nextLine]);
            if (!in_array($label, $lines, true)) {
                $lines[] = $label;
            }
        } elseif ($opId === 'workweeks.create' && $nextLine !== '') {
            $lines[] = t('workweeks.assistant.preview.create', ['v' => $nextLine]);
        }

        if (!is_array($current) || $current === [] || $opId !== 'workweeks.update') {
            return $lines;
        }

        $map = [
            'id_operator' => static function ($v): string {
                return self::userLabel((int)$v);
            },
            'date' => static function ($v): string {
                return self::formatDayValue($v);
            },
            'price_col' => static function ($v): string {
                return self::shiftLaneLabel((int)$v);
            },
            'mas_max' => static function ($v): string {
                return (string)(int)$v;
            },
            'id_table' => static function ($v): string {
                return (string)(int)$v;
            },
        ];
        foreach ($map as $field => $fmt) {
            if (!array_key_exists($field, $args) || $args[$field] === '' || $args[$field] === null) {
                continue;
            }
            $curKey = $field === 'date' ? 'dt' : $field;
            $before = $fmt($current[$curKey] ?? '');
            $after = $fmt($args[$field]);
            if ($before === $after || $after === '') {
                continue;
            }
            $fieldLabel = t('workweeks.assistant.field.' . $field);
            if ($fieldLabel === 'workweeks.assistant.field.' . $field) {
                $fieldLabel = $field;
            }
            $lines[] = $fieldLabel . ': '
                . ($before !== '' ? $before : '—')
                . ' → '
                . $after;
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatShiftRow(array $row): string
    {
        $opId = (int)($row['id_operator'] ?? 0);
        $name = self::userLabel($opId);
        $dt = self::formatDayValue($row['dt'] ?? $row['date'] ?? '');
        $lane = self::shiftLaneLabel((int)($row['price_col'] ?? 0));
        $mas = (int)($row['mas_max'] ?? 0);
        $table = (int)($row['id_table'] ?? 0);
        $bits = array_filter([
            $name !== '' ? $name : ($opId > 0 ? '#' . $opId : ''),
            $dt !== '' ? $dt : '',
            $lane !== '' ? $lane : '',
            $mas > 0 ? t('workweeks.assistant.preview.mas', ['v' => (string)$mas]) : '',
            $table > 0 ? t('workweeks.assistant.preview.table', ['v' => (string)$table]) : '',
        ], static function (string $s): bool {
            return $s !== '';
        });
        return implode(' · ', $bits);
    }

    private static function userLabel(int $userId): string
    {
        if ($userId < 1) {
            return '';
        }
        if (!class_exists('UsersRepository')) {
            return '#' . $userId;
        }
        try {
            $name = trim(UsersRepository::nameById($userId));
        } catch (Throwable $e) {
            return '#' . $userId;
        }
        if ($name === '' || $name === '—') {
            return '#' . $userId;
        }
        if ($name === '#' . $userId || preg_match('/^#\d+$/', $name) === 1) {
            return $name;
        }
        return $name . ' (#' . $userId . ')';
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

    private static function shiftLaneLabel(int $priceCol): string
    {
        if ($priceCol < 1) {
            return '';
        }
        try {
            $label = trim(WorkweeksRepository::shiftLabel($priceCol));
        } catch (Throwable $e) {
            $label = '';
        }
        if ($label !== '') {
            return t('workweeks.assistant.preview.lane', [
                'v' => $label,
                'id' => (string)$priceCol,
            ]);
        }
        return t('workweeks.assistant.preview.lane_id', ['v' => (string)$priceCol]);
    }
}
