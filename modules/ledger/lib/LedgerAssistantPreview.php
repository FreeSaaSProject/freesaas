<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for ledger.* writes.
 */
final class LedgerAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId === '' || strpos($opId, 'ledger.') !== 0) {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        if (strpos($opId, 'ledger.accounts.') === 0) {
            return self::merge($payload, self::linesForAccount($opId, $args));
        }
        if (strpos($opId, 'ledger.moves.') === 0) {
            return self::merge($payload, self::linesForMove($opId, $args));
        }
        if (strpos($opId, 'ledger.types.') === 0) {
            return self::merge($payload, self::linesForType($args));
        }
        return $payload;
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
    private static function linesForAccount(string $opId, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '' && $id > 0) {
            try {
                $row = LedgerAccountsRepository::find($id);
                $name = is_array($row) ? trim((string)($row['name'] ?? '')) : '';
            } catch (Throwable $e) {
                $name = '';
            }
        }
        if ($name === '') {
            return $id > 0 ? ['#' . $id] : [];
        }
        $action = strtolower((string)($args['action'] ?? ''));
        if ($action === 'delete') {
            return [t('ledger.assistant.preview.account_delete', ['v' => $name])];
        }
        return [t('ledger.assistant.preview.account', ['v' => $name])];
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesForType(array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '' && $id > 0) {
            try {
                $row = AccountMoveTypesRepository::find($id);
                $name = is_array($row) ? trim((string)($row['name'] ?? '')) : '';
            } catch (Throwable $e) {
                $name = '';
            }
        }
        if ($name === '') {
            return $id > 0 ? ['#' . $id] : [];
        }
        return [t('ledger.assistant.preview.type', ['v' => $name])];
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesForMove(string $opId, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            try {
                $current = LedgerMovesRepository::find($id);
            } catch (Throwable $e) {
                $current = null;
            }
        }

        $lines = [];
        if (is_array($current) && $current !== []) {
            $now = self::formatMove($current);
            if ($now !== '') {
                $lines[] = t('ledger.assistant.preview.now', ['v' => $now]);
            }
        }

        $action = strtolower(trim((string)($args['action'] ?? '')));
        if ($opId === 'ledger.moves.delete' || $action === 'delete') {
            if ($lines === []) {
                $lines[] = t('ledger.assistant.preview.delete');
            }
            return $lines;
        }

        $next = is_array($current) ? $current : [];
        foreach (['account_id', 'id_account', 'type_id', 'id_type', 'summ', 'sum', 'direction', 'descr', 'dt', 'date', 'account_to', 'id_account_to'] as $k) {
            if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
                continue;
            }
            if ($k === 'id_account') {
                $next['account_id'] = $args[$k];
            } elseif ($k === 'id_type') {
                $next['type_id'] = $args[$k];
            } elseif ($k === 'sum') {
                $next['summ'] = $args[$k];
            } elseif ($k === 'date') {
                $next['dt'] = $args[$k];
            } elseif ($k === 'id_account_to') {
                $next['account_to'] = $args[$k];
            } else {
                $next[$k] = $args[$k];
            }
        }
        $will = self::formatMove($next);
        if ($will === '') {
            return $lines;
        }
        if (strpos($opId, 'transfer') !== false) {
            $lines[] = t('ledger.assistant.preview.transfer', ['v' => $will]);
        } elseif ($current === null || strpos($opId, 'copy') !== false) {
            $lines[] = t('ledger.assistant.preview.create', ['v' => $will]);
        } else {
            $nowLine = self::formatMove($current);
            if ($will !== $nowLine) {
                $lines[] = t('ledger.assistant.preview.will', ['v' => $will]);
            }
        }
        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatMove(array $row): string
    {
        $accountId = (int)($row['account_id'] ?? 0);
        $accountTo = (int)($row['account_to'] ?? 0);
        $typeId = (int)($row['type_id'] ?? 0);
        $summ = $row['summ'] ?? '';
        $dir = (int)($row['direction'] ?? 0);
        $descr = trim((string)($row['descr'] ?? ''));
        $when = self::formatDay($row['dt'] ?? $row['date'] ?? '');

        $dirLabel = '';
        if ($dir === 1) {
            $dirLabel = t('ledger.dir.label.in');
        } elseif ($dir === -1) {
            $dirLabel = t('ledger.dir.label.out');
        }

        $acct = self::accountLabel($accountId);
        if ($accountTo > 0) {
            $acct2 = self::accountLabel($accountTo);
            if ($acct !== '' && $acct2 !== '') {
                $acct = $acct . ' → ' . $acct2;
            }
        }

        $type = self::typeLabel($typeId);
        $sumLabel = ($summ !== '' && $summ !== null) ? (string)$summ : '';
        if (function_exists('mb_strlen') && mb_strlen($descr) > 60) {
            $descr = mb_substr($descr, 0, 60) . '…';
        } elseif (strlen($descr) > 60) {
            $descr = substr($descr, 0, 60) . '…';
        }

        $bits = array_filter([$acct, $type, $dirLabel, $sumLabel, $when, $descr], static function (string $s): bool {
            return $s !== '';
        });
        return implode(' · ', $bits);
    }

    private static function accountLabel(int $id): string
    {
        if ($id < 1) {
            return '';
        }
        try {
            $row = LedgerAccountsRepository::find($id);
            $name = is_array($row) ? trim((string)($row['name'] ?? '')) : '';
            return $name !== '' ? $name : ('#' . $id);
        } catch (Throwable $e) {
            return '#' . $id;
        }
    }

    private static function typeLabel(int $id): string
    {
        if ($id < 1) {
            return '';
        }
        try {
            $row = AccountMoveTypesRepository::find($id);
            $name = is_array($row) ? trim((string)($row['name'] ?? '')) : '';
            return $name !== '' ? $name : ('#' . $id);
        } catch (Throwable $e) {
            return '#' . $id;
        }
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
}
