<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for procedures.* writes.
 */
final class ProceduresAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId === '' || strpos($opId, 'procedures.') !== 0) {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        if (strpos($opId, 'procedures.types.') === 0) {
            return self::merge($payload, self::linesForType($args));
        }
        return self::merge($payload, self::linesForProcedure($opId, $args));
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
    private static function linesForType(array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $name = trim((string)($args['type'] ?? $args['name'] ?? ''));
        if ($name === '' && $id > 0) {
            try {
                $row = ProcedureTypesRepository::find($id);
                $name = is_array($row) ? trim((string)($row['type'] ?? '')) : '';
            } catch (Throwable $e) {
                $name = '';
            }
        }
        if ($name === '') {
            return $id > 0 ? ['#' . $id] : [];
        }
        return [t('procedures.assistant.preview.type', ['v' => $name])];
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesForProcedure(string $opId, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            try {
                $current = ProceduresRepository::find($id);
            } catch (Throwable $e) {
                $current = null;
            }
        }

        $lines = [];
        if (is_array($current) && $current !== []) {
            $now = self::formatProcedure($current);
            if ($now !== '') {
                $lines[] = t('procedures.assistant.preview.now', ['v' => $now]);
            }
        }

        if ($opId === 'procedures.delete' || strtolower((string)($args['action'] ?? '')) === 'delete') {
            if ($lines === []) {
                $lines[] = t('procedures.assistant.preview.delete');
            }
            return $lines;
        }

        $next = is_array($current) ? $current : [];
        foreach (['id_operator', 'id_user', 'id_good', 'id_admin', 'summ', 'dtstart', 'dtend', 'type', 'notice'] as $k) {
            if (array_key_exists($k, $args) && $args[$k] !== '' && $args[$k] !== null) {
                $next[$k] = $args[$k];
            }
        }
        // Resolve names from ids when patching.
        if (isset($next['id_good']) && empty($next['product_name']) && class_exists('ProductsRepository')) {
            try {
                $p = ProductsRepository::find((int)$next['id_good']);
                $next['product_name'] = is_array($p) ? (string)($p['name'] ?? '') : '';
            } catch (Throwable $e) {
                // ignore
            }
        }
        if (isset($next['id_user']) && empty($next['client_name']) && class_exists('ClientsRepository')) {
            try {
                $c = ClientsRepository::find((int)$next['id_user']);
                $next['client_name'] = is_array($c) ? (string)($c['name'] ?? '') : '';
            } catch (Throwable $e) {
                // ignore
            }
        }
        if (isset($next['id_operator']) && empty($next['operator_name']) && class_exists('UsersRepository')) {
            try {
                $next['operator_name'] = UsersRepository::nameById((int)$next['id_operator']);
            } catch (Throwable $e) {
                // ignore
            }
        }

        $will = self::formatProcedure($next);
        if ($will === '') {
            return $lines;
        }
        if ($opId === 'procedures.create' || $current === null) {
            $lines[] = t('procedures.assistant.preview.create', ['v' => $will]);
        } else {
            $nowLine = self::formatProcedure($current);
            if ($will !== $nowLine) {
                $lines[] = t('procedures.assistant.preview.will', ['v' => $will]);
            }
        }
        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatProcedure(array $row): string
    {
        $client = trim((string)($row['client_name'] ?? ''));
        if ($client === '' && (int)($row['id_user'] ?? 0) > 0) {
            $client = '#' . (int)$row['id_user'];
        }
        $product = trim((string)($row['product_name'] ?? ''));
        if ($product === '' && (int)($row['id_good'] ?? 0) > 0) {
            $product = '#' . (int)$row['id_good'];
        }
        $op = trim((string)($row['operator_name'] ?? ''));
        if ($op === '' && (int)($row['id_operator'] ?? 0) > 0) {
            $op = self::userLabel((int)$row['id_operator']);
        }
        $when = self::formatLocal($row['dtstart'] ?? '');
        $summ = $row['summ'] ?? '';
        $sumLabel = ($summ !== '' && $summ !== null) ? (string)$summ : '';
        $type = trim((string)($row['type_name'] ?? ''));

        $bits = array_filter([$client, $product, $op, $type, $when, $sumLabel], static function (string $s): bool {
            return $s !== '';
        });
        return implode(' · ', $bits);
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
        if ($name === '' || $name === '—' || preg_match('/^#\d+$/', $name) === 1) {
            return $name !== '' && $name !== '—' ? $name : ('#' . $userId);
        }
        return $name . ' (#' . $userId . ')';
    }
}
