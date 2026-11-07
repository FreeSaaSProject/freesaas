<?php
declare(strict_types=1);

final class BitrixPhone
{
    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) < 10) {
            return null;
        }
        return $digits;
    }

    /** @param mixed $phoneField Bitrix PHONE multi-field */
    public static function fromBitrixField($phoneField): ?string
    {
        if (is_string($phoneField)) {
            return self::normalize($phoneField);
        }
        if (!is_array($phoneField)) {
            return null;
        }
        foreach ($phoneField as $row) {
            if (is_string($row)) {
                $n = self::normalize($row);
                if ($n !== null) {
                    return $n;
                }
                continue;
            }
            if (is_array($row)) {
                $n = self::normalize((string)($row['VALUE'] ?? $row['value'] ?? ''));
                if ($n !== null) {
                    return $n;
                }
            }
        }
        return null;
    }
}
