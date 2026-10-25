<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Named slot patterns on an agreement extract period/gross/fee from move DESCR.
 */

final class RemittanceParser
{
    public const PRESET_VOLGA_TERMY_V1 = 'volga_termy_agency_v1';

    /**
     * @return array<string,array{label_key:string,period_from:string,period_to:string,gross:string,fee:string}>
     */
    public static function presets(): array
    {
        return [
            self::PRESET_VOLGA_TERMY_V1 => [
                'label_key' => 'remittances.preset.volga_termy_v1',
                // Capture groups: first group = value. Dates DD.MM.YY; money with spaces + comma decimals.
                'period_from' => '/период\\s+с\\s+(\\d{2}\\.\\d{2}\\.\\d{2})/ui',
                'period_to' => '/по\\s+(\\d{2}\\.\\d{2}\\.\\d{2})/ui',
                'gross' => '/Сумма\\s+([0-9\\s\\x{00A0}]+,\\d{2})\\s*руб/ui',
                'fee' => '/возмещаемых\\s+расходов\\s+([0-9\\s\\x{00A0}]+,\\d{2})\\s*руб/ui',
            ],
        ];
    }

    /** @return array{period_from:string,period_to:string,gross:string,fee:string} */
    public static function slotsForPreset(string $presetId): array
    {
        $p = self::presets()[$presetId] ?? null;
        if ($p === null) {
            return [
                'period_from' => '',
                'period_to' => '',
                'gross' => '',
                'fee' => '',
            ];
        }
        return [
            'period_from' => (string)$p['period_from'],
            'period_to' => (string)$p['period_to'],
            'gross' => (string)$p['gross'],
            'fee' => (string)$p['fee'],
        ];
    }

    /**
     * @param array{pattern_period_from?:string,pattern_period_to?:string,pattern_gross?:string,pattern_fee?:string} $agreement
     * @return array{
     *   ok:bool,
     *   period_from:?string,
     *   period_to:?string,
     *   gross:?float,
     *   fee:?float,
     *   error:string
     * }
     */
    public static function parse(string $descr, array $agreement): array
    {
        $empty = [
            'ok' => false,
            'period_from' => null,
            'period_to' => null,
            'gross' => null,
            'fee' => null,
            'error' => '',
        ];
        $descr = trim($descr);
        if ($descr === '') {
            $empty['error'] = 'empty_descr';
            return $empty;
        }

        $fromPat = trim((string)($agreement['pattern_period_from'] ?? ''));
        $toPat = trim((string)($agreement['pattern_period_to'] ?? ''));
        $grossPat = trim((string)($agreement['pattern_gross'] ?? ''));
        $feePat = trim((string)($agreement['pattern_fee'] ?? ''));
        if ($fromPat === '' || $toPat === '' || $grossPat === '' || $feePat === '') {
            $empty['error'] = 'patterns_incomplete';
            return $empty;
        }

        $fromRaw = self::firstCapture($descr, $fromPat);
        $toRaw = self::firstCapture($descr, $toPat);
        $grossRaw = self::firstCapture($descr, $grossPat);
        $feeRaw = self::firstCapture($descr, $feePat);

        if ($fromRaw === null || $toRaw === null) {
            $empty['error'] = 'period_not_found';
            return $empty;
        }
        if ($grossRaw === null) {
            $empty['error'] = 'gross_not_found';
            return $empty;
        }
        if ($feeRaw === null) {
            $empty['error'] = 'fee_not_found';
            return $empty;
        }

        $periodFrom = self::normalizeDate($fromRaw);
        $periodTo = self::normalizeDate($toRaw);
        $gross = self::normalizeMoney($grossRaw);
        $fee = self::normalizeMoney($feeRaw);

        if ($periodFrom === null || $periodTo === null) {
            $empty['error'] = 'period_invalid';
            return $empty;
        }
        if ($gross === null || $fee === null) {
            $empty['error'] = 'money_invalid';
            return $empty;
        }
        if ($periodTo < $periodFrom) {
            $empty['error'] = 'period_order';
            return $empty;
        }

        return [
            'ok' => true,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'gross' => $gross,
            'fee' => $fee,
            'error' => '',
        ];
    }

    private static function firstCapture(string $haystack, string $pattern): ?string
    {
        $prev = error_reporting(error_reporting() & ~E_WARNING);
        $ok = @preg_match($pattern, $haystack, $m);
        error_reporting($prev);
        if ($ok !== 1 || !isset($m[1])) {
            return null;
        }
        $v = trim((string)$m[1]);
        return $v !== '' ? $v : null;
    }

    /** @return string|null Y-m-d */
    public static function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^(\\d{2})\\.(\\d{2})\\.(\\d{2})$/', $raw, $m)) {
            $y = 2000 + (int)$m[3];
            $mo = (int)$m[2];
            $d = (int)$m[1];
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        if (preg_match('/^(\\d{2})\\.(\\d{2})\\.(\\d{4})$/', $raw, $m)) {
            $y = (int)$m[3];
            $mo = (int)$m[2];
            $d = (int)$m[1];
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        if (preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $raw, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return $raw;
        }
        return null;
    }

    public static function normalizeMoney(string $raw): ?float
    {
        $s = str_replace(["\xc2\xa0", ' '], '', trim($raw));
        $s = str_replace(',', '.', $s);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return round((float)$s, 2);
    }
}
