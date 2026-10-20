<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for payments.* writes.
 */
final class PaymentsAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId === '' || strpos($opId, 'payments.') !== 0) {
            return $payload;
        }
        if (strpos($opId, 'payments.settings.') === 0) {
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
                $current = PaymentsRepository::find($id);
            } catch (Throwable $e) {
                $current = null;
            }
        }

        $lines = [];
        if (is_array($current) && $current !== []) {
            $now = self::formatPayment($current);
            if ($now !== '') {
                $lines[] = t('payments.assistant.preview.now', ['v' => $now]);
            }
        }

        $action = strtolower(trim((string)($args['action'] ?? '')));
        if ($action === 'delete') {
            if ($lines === []) {
                $lines[] = t('payments.assistant.preview.delete');
            }
            return $lines;
        }

        $next = is_array($current) ? $current : [];
        foreach (['user_id', 'id_user', 'sum', 'summ', 'direction', 'dt', 'date', 'descr'] as $k) {
            if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
                continue;
            }
            if ($k === 'id_user') {
                $next['user_id'] = $args[$k];
            } elseif ($k === 'summ') {
                $next['sum'] = $args[$k];
            } elseif ($k === 'date') {
                $next['dt'] = $args[$k];
            } else {
                $next[$k] = $args[$k];
            }
        }
        $will = self::formatPayment($next);
        if ($will === '') {
            return $lines;
        }
        if ($opId === 'payments.create' || $current === null) {
            $lines[] = t('payments.assistant.preview.create', ['v' => $will]);
        } else {
            $nowLine = is_array($current) ? self::formatPayment($current) : '';
            if ($will !== $nowLine) {
                $lines[] = t('payments.assistant.preview.will', ['v' => $will]);
            }
        }
        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatPayment(array $row): string
    {
        $userId = (int)($row['user_id'] ?? $row['id_user'] ?? 0);
        $who = self::userLabel($userId);
        $sum = $row['sum'] ?? $row['summ'] ?? '';
        $sumLabel = ($sum !== '' && $sum !== null) ? (string)$sum : '';
        $dir = self::directionLabel($row['direction'] ?? '');
        $when = self::formatDay($row['dt'] ?? $row['date'] ?? '');
        $descr = trim((string)($row['descr'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($descr) > 60) {
            $descr = mb_substr($descr, 0, 60) . '…';
        } elseif (strlen($descr) > 60) {
            $descr = substr($descr, 0, 60) . '…';
        }
        $bits = array_filter([$who, $sumLabel, $dir, $when, $descr], static function (string $s): bool {
            return $s !== '';
        });
        return implode(' · ', $bits);
    }

    /** @param mixed $dir */
    private static function directionLabel($dir): string
    {
        $n = (int)$dir;
        if ($n === 1) {
            return t('payments.assistant.preview.dir_in');
        }
        if ($n === -1) {
            return t('payments.assistant.preview.dir_out');
        }
        return '';
    }

    /** @param mixed $raw */
    private static function formatDay($raw): string
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
        if ($name === '' || $name === '—' || preg_match('/^#\d+$/', $name) === 1) {
            return $name !== '' && $name !== '—' ? $name : ('#' . $userId);
        }
        return $name . ' (#' . $userId . ')';
    }
}
