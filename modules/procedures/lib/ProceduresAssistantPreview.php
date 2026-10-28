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
        if ($opId === 'procedures.set_admin') {
            return self::previewSetAdmin($payload, $args);
        }
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
        if (isset($next['id_operator']) && (int)$next['id_operator'] > 0) {
            // Prefer acronym over full FIO left over from the DB row.
            $next['operator_name'] = self::userLabel((int)$next['id_operator']);
        }

        if (is_array($current) && array_key_exists('id_operator', $args) && $args['id_operator'] !== '' && $args['id_operator'] !== null) {
            $fromOp = (int)($current['id_operator'] ?? 0);
            $toOp = (int)$args['id_operator'];
            if ($toOp > 0 && $toOp !== $fromOp) {
                $lines[] = t('procedures.assistant.preview.operator', [
                    'from' => self::userLabel($fromOp),
                    'to' => self::userLabel($toOp),
                ]);
            }
        }

        if (is_array($current)) {
            $fromStart = self::formatLocal($current['dtstart'] ?? '');
            $toStart = self::formatLocal($next['dtstart'] ?? '');
            $fromEnd = self::formatLocal($current['dtend'] ?? '');
            $toEnd = self::formatLocal($next['dtend'] ?? '');
            if ($toStart !== '' && $fromStart !== '' && $toStart !== $fromStart) {
                $lines[] = t('procedures.assistant.preview.time', [
                    'from' => $fromStart,
                    'to' => $toStart,
                ]);
            } elseif ($toEnd !== '' && $fromEnd !== '' && $toEnd !== $fromEnd) {
                $lines[] = t('procedures.assistant.preview.time_end', [
                    'from' => $fromEnd,
                    'to' => $toEnd,
                ]);
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
     * @param array<string,mixed> $args
     * @return list<string>
     */
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function previewSetAdmin(array $payload, array $args): array
    {
        $ids = self::normalizeIds($args['ids'] ?? $args['id'] ?? null);
        $adminId = (int)($args['id_admin'] ?? $args['admin_id'] ?? 0);
        $fromAdmin = (int)($args['from_id_admin'] ?? $args['from_admin'] ?? 0);
        $date = trim((string)($args['date'] ?? $args['date_from'] ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        if ($ids === [] && $fromAdmin > 0) {
            try {
                $ids = ProceduresRepository::idsByAdminOnDate($fromAdmin, $date);
            } catch (Throwable $e) {
                $ids = [];
            }
        }
        $adminLabel = $adminId > 0 ? self::userLabel($adminId) : '';
        if ($adminLabel === '' && $adminId > 0) {
            $adminLabel = '#' . $adminId;
        }
        $fromLabel = $fromAdmin > 0 ? self::userLabel($fromAdmin) : '';
        if ($fromLabel === '' && $fromAdmin > 0) {
            $fromLabel = '#' . $fromAdmin;
        }
        if ($ids !== []) {
            $payload['headline'] = t('procedures.assistant.preview.set_admin_ids', [
                'ids' => implode(', ', array_map('strval', array_slice($ids, 0, 8)))
                    . (count($ids) > 8 ? '…' : ''),
                'n' => (string)count($ids),
            ]);
        } elseif ($fromAdmin > 0) {
            $payload['headline'] = t('procedures.assistant.preview.set_admin_day', [
                'from' => $fromLabel,
                'date' => $date,
                'n' => '0',
            ]);
        }
        $lines = [];
        if ($fromAdmin > 0) {
            $lines[] = t('procedures.assistant.preview.set_admin_day', [
                'from' => $fromLabel,
                'date' => $date,
                'n' => (string)count($ids),
            ]);
        }
        foreach (array_slice($ids, 0, 5) as $pid) {
            try {
                $row = ProceduresRepository::find($pid);
            } catch (Throwable $e) {
                $row = null;
            }
            if (!is_array($row)) {
                continue;
            }
            $nowAdmin = self::userLabel((int)($row['id_admin'] ?? 0));
            $brief = self::formatProcedure($row);
            if ($brief !== '') {
                $lines[] = '#' . $pid . ($nowAdmin !== '' ? ' · ' . t('procedures.assistant.preview.set_admin_was', ['v' => $nowAdmin]) : '')
                    . ' · ' . $brief;
            }
        }
        if ($adminLabel !== '') {
            $lines[] = t('procedures.assistant.preview.set_admin_to', ['v' => $adminLabel]);
        }
        return self::merge($payload, $lines);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function normalizeIds($raw): array
    {
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $ids[] = (int)$v;
            }
        } elseif (is_string($raw) || is_int($raw) || is_float($raw)) {
            foreach (preg_split('/[\s,;]+/', trim((string)$raw)) ?: [] as $part) {
                if ($part !== '' && ctype_digit($part)) {
                    $ids[] = (int)$part;
                }
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
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
        $op = '';
        if ((int)($row['id_operator'] ?? 0) > 0) {
            $op = self::userLabel((int)$row['id_operator']);
        } elseif (trim((string)($row['operator_name'] ?? '')) !== '') {
            $op = trim((string)$row['operator_name']);
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

    /** Staff acronym (not full name) — keeps Confirm drafts short in Max. */
    private static function userLabel(int $userId): string
    {
        if ($userId < 1) {
            return '';
        }
        if (!class_exists('UsersRepository')) {
            return '#' . $userId;
        }
        try {
            $list = UsersRepository::acronymsList([$userId]);
        } catch (Throwable $e) {
            return '#' . $userId;
        }
        $list = trim($list);
        return $list !== '' ? $list : ('#' . $userId);
    }
}
