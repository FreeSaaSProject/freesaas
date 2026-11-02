<?php
declare(strict_types=1);

/**
 * PSB (Промсвязьбанк) CardAccountStatement CSV → normalized bank txs.
 *
 * Header block + semicolon columns:
 * Дата и время транзакции; Дата обработки; Детали; Сумма; Сумма в валюте карты; Комиссия
 */
final class PsbCardStatementParser
{
    /**
     * @return array{
     *   account_number:string,
     *   closing_balance:?float,
     *   opening_balance:?float,
     *   period_from:string,
     *   period_to:string,
     *   txs:list<array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}>
     * }
     */
    public static function parse(string $csv): array
    {
        $csv = self::stripBom($csv);
        if (trim($csv) === '') {
            throw new InvalidArgumentException(t('banks.import.err.empty'));
        }
        if (mb_stripos($csv, 'Дата и время транзакции') === false
            && mb_stripos($csv, 'Номер счета') === false) {
            throw new InvalidArgumentException(t('banks.import.err.format'));
        }

        $lines = preg_split('/\R/u', $csv) ?: [];
        $accountNumber = '';
        $closingBalance = null;
        $openingBalance = null;
        $periodFrom = '';
        $periodTo = '';
        $headerIdx = -1;
        $colMap = [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cells = self::csvRow($line);
            if ($cells === [] || $cells === [null]) {
                continue;
            }
            $key = self::normHeader((string)($cells[0] ?? ''));
            if ($key === 'номерсчета' || $key === 'номерсчёта') {
                $raw = (string)($cells[1] ?? '');
                $accountNumber = preg_replace('/\D+/', '', $raw) ?? '';
                continue;
            }
            if ($key === 'исходящийостаток') {
                $closingBalance = self::parseMoney((string)($cells[1] ?? ''));
                continue;
            }
            if ($key === 'входящийостаток') {
                $openingBalance = self::parseMoney((string)($cells[1] ?? ''));
                continue;
            }
            if ($key === 'датаначалавыписки') {
                $periodFrom = trim((string)($cells[1] ?? ''));
                continue;
            }
            if ($key === 'датаокончаниявыписки') {
                $periodTo = trim((string)($cells[1] ?? ''));
                continue;
            }
            $joined = self::normHeader(implode(';', $cells));
            if (mb_strpos($joined, 'датаивремятранзакции') !== false
                && mb_strpos($joined, 'детали') !== false) {
                $headerIdx = $i;
                $colMap = self::mapColumns($cells);
                break;
            }
        }

        if ($headerIdx < 0 || $colMap === []) {
            throw new InvalidArgumentException(t('banks.import.err.format'));
        }
        if (!isset($colMap['signed']) || !isset($colMap['details'])) {
            throw new InvalidArgumentException(t('banks.import.err.format'));
        }

        $txs = [];
        for ($i = $headerIdx + 1, $n = count($lines); $i < $n; $i++) {
            $line = trim((string)$lines[$i]);
            if ($line === '') {
                continue;
            }
            $cells = self::csvRow($line);
            if ($cells === [] || count($cells) < 3) {
                continue;
            }
            $tx = self::normalizeRow($cells, $colMap);
            if ($tx !== null) {
                $txs[] = $tx;
            }
        }

        if ($txs === []) {
            throw new InvalidArgumentException(t('banks.import.err.no_rows'));
        }

        return [
            'account_number' => $accountNumber,
            'closing_balance' => $closingBalance,
            'opening_balance' => $openingBalance,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'txs' => $txs,
        ];
    }

    /**
     * @param list<string> $cells
     * @return array<string,int>
     */
    private static function mapColumns(array $cells): array
    {
        $map = [];
        foreach ($cells as $idx => $label) {
            $h = self::normHeader((string)$label);
            if ($h === 'датаивремятранзакции') {
                $map['tx_at'] = $idx;
            } elseif ($h === 'датаобработкитранзакции' || $h === 'датаобработки') {
                $map['process_at'] = $idx;
            } elseif ($h === 'детали') {
                $map['details'] = $idx;
            } elseif ($h === 'сумма') {
                $map['amount'] = $idx;
            } elseif ($h === 'суммаввалютекарт' || $h === 'суммаввалютекарты') {
                $map['signed'] = $idx;
            } elseif ($h === 'комиссия') {
                $map['fee'] = $idx;
            }
        }
        return $map;
    }

    /**
     * @param list<string|null> $cells
     * @param array<string,int> $colMap
     * @return array{external_id:string,direction:int,amount:float,dt:int,purpose:string,counterparty_name:string,counterparty_inn:string,raw:array}|null
     */
    private static function normalizeRow(array $cells, array $colMap): ?array
    {
        $details = trim((string)($cells[$colMap['details']] ?? ''));
        $signedRaw = (string)($cells[$colMap['signed']] ?? '');
        $signed = self::parseMoney($signedRaw);
        if ($details === '' && abs($signed) < 0.00001) {
            return null;
        }
        if (abs($signed) < 0.00001) {
            return null;
        }

        $direction = $signed >= 0 ? 1 : -1;
        $amount = round(abs($signed), 2);

        $processRaw = isset($colMap['process_at']) ? (string)($cells[$colMap['process_at']] ?? '') : '';
        $txAtRaw = isset($colMap['tx_at']) ? (string)($cells[$colMap['tx_at']] ?? '') : '';
        $dt = self::parseProcessDate($processRaw);
        if ($dt <= 0) {
            $dt = self::parseTxDateTime($txAtRaw);
        }
        if ($dt <= 0) {
            $dt = time();
        }

        $seq = '';
        if (preg_match('/\s(\d{5,6})\.\s*MCC\b/u', $details, $m)) {
            $seq = $m[1];
        }

        $purpose = $details;
        $parts = [
            'direction' => $direction,
            'amount' => $amount,
            'dt' => $dt,
            'purpose' => $purpose,
            'counterparty_inn' => '',
            'counterparty_name' => '',
        ];
        $externalId = $seq !== ''
            ? ('seq:' . $seq)
            : BankTxMapRepository::fingerprint($parts);

        return [
            'external_id' => $externalId,
            'direction' => $direction,
            'amount' => $amount,
            'dt' => $dt,
            'purpose' => $purpose,
            'counterparty_name' => '',
            'counterparty_inn' => '',
            'raw' => [
                'cells' => $cells,
                'signed' => $signedRaw,
                'process_at' => $processRaw,
                'tx_at' => $txAtRaw,
            ],
        ];
    }

    private static function parseMoney(string $raw): float
    {
        $s = trim($raw);
        if ($s === '') {
            return 0.0;
        }
        $neg = false;
        if ($s[0] === '-' || (isset($s[0]) && $s[0] === '−')) {
            $neg = true;
            $s = mb_substr($s, 1);
        }
        $s = str_replace(["\xC2\xA0", ' ', "'"], '', $s);
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) {
            return 0.0;
        }
        $v = (float)$s;
        return $neg ? -$v : $v;
    }

    /** Booking/process date dd.mm.YYYY → noon local. */
    private static function parseProcessDate(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            $ts = strtotime(sprintf('%04d-%02d-%02d 12:00:00', (int)$m[3], (int)$m[2], (int)$m[1]));
            return $ts !== false ? $ts : 0;
        }
        return 0;
    }

    /** Transaction datetime "dd.mm.YYYY H:i:s" or "dd.mm.YYYY H:i". */
    private static function parseTxDateTime(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (preg_match(
            '/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/',
            $raw,
            $m
        )) {
            $ts = strtotime(sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                (int)$m[3],
                (int)$m[2],
                (int)$m[1],
                (int)$m[4],
                (int)$m[5],
                isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0
            ));
            return $ts !== false ? $ts : 0;
        }
        return self::parseProcessDate($raw);
    }

    private static function normHeader(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(["\xC2\xA0", ' ', '"', "'"], '', $s);
        return $s;
    }

    private static function stripBom(string $s): string
    {
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            return substr($s, 3);
        }
        return $s;
    }

    /** @return list<string|null> */
    private static function csvRow(string $line): array
    {
        // Empty $escape = PHP 8.4+ recommended (no backslash escapes; "" doubling works).
        return str_getcsv($line, ';', '"', '');
    }
}
