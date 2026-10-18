<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for calendar.events.* writes.
 */
final class CalendarAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId === '' || strpos($opId, 'calendar.events.') !== 0) {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $lines = self::linesFor($opId, $args);
        return self::merge($payload, $lines);
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private static function merge(array $payload, array $lines): array
    {
        if ($lines === []) {
            return $payload;
        }
        $existing = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $existing[] = $line;
            }
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
        $id = (int)($args['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            try {
                $current = EventsRepository::find($id);
            } catch (Throwable $e) {
                $current = null;
            }
        }

        $lines = [];
        if (is_array($current) && $current !== []) {
            $now = self::formatEvent($current);
            if ($now !== '') {
                $lines[] = t('calendar.assistant.preview.now', ['v' => $now]);
            }
        }

        $action = strtolower(trim((string)($args['action'] ?? '')));
        $isDelete = $opId === 'calendar.events.delete' || $action === 'delete';
        if ($isDelete) {
            // Without a resolved row, do not invent a generic “delete this” line —
            // harness should reject missing path id before pending; keep preview honest.
            if ($lines === [] && $id > 0) {
                $lines[] = t('calendar.assistant.preview.delete');
            }
            return $lines;
        }

        $next = is_array($current) ? $current : [];
        foreach (['id_operator', 'name', 'phone', 'descr', 'dtstart', 'dtend'] as $k) {
            if (array_key_exists($k, $args) && $args[$k] !== '' && $args[$k] !== null) {
                $next[$k] = $args[$k];
            }
        }
        $will = self::formatEvent($next);
        if ($will === '') {
            return $lines;
        }
        if ($opId === 'calendar.events.create' || $current === null) {
            $lines[] = t('calendar.assistant.preview.create', ['v' => $will]);
        } elseif ($will !== ($lines !== [] ? self::formatEvent($current ?: []) : '')) {
            $lines[] = t('calendar.assistant.preview.will', ['v' => $will]);
        }
        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatEvent(array $row): string
    {
        $op = self::userLabel((int)($row['id_operator'] ?? 0));
        $when = self::formatRange($row['dtstart'] ?? '', $row['dtend'] ?? '');
        $who = trim((string)($row['name'] ?? ''));
        $phone = trim((string)($row['phone'] ?? ''));
        $descr = trim((string)($row['descr'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($descr) > 80) {
            $descr = mb_substr($descr, 0, 80) . '…';
        } elseif (strlen($descr) > 80) {
            $descr = substr($descr, 0, 80) . '…';
        }
        $bits = array_filter([
            $op,
            $when,
            $who !== '' ? $who : '',
            $phone !== '' ? $phone : '',
            $descr !== '' ? $descr : '',
        ], static function (string $s): bool {
            return $s !== '';
        });
        return implode(' · ', $bits);
    }

    /** @param mixed $start @param mixed $end */
    private static function formatRange($start, $end): string
    {
        $a = self::formatLocal($start);
        $b = self::formatLocal($end);
        if ($a !== '' && $b !== '') {
            return $a . ' → ' . $b;
        }
        return $a !== '' ? $a : $b;
    }

    /** @param mixed $raw */
    private static function formatLocal($raw): string
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $ts = (int)$raw;
            if ($ts > 100000) {
                return date('Y-m-d H:i', $ts);
            }
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return str_replace('T', ' ', substr($s, 0, 16));
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d H:i', $ts) : $s;
    }

    private static function userLabel(int $userId): string
    {
        if ($userId < 1 || !class_exists('UsersRepository')) {
            return $userId > 0 ? '#' . $userId : '';
        }
        try {
            $name = trim(UsersRepository::nameById($userId));
        } catch (Throwable $e) {
            return '#' . $userId;
        }
        if ($name === '' || $name === '—') {
            return '#' . $userId;
        }
        if (preg_match('/^#\d+$/', $name) === 1) {
            return $name;
        }
        return $name . ' (#' . $userId . ')';
    }
}
