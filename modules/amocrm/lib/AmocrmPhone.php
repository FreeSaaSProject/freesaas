<?php
declare(strict_types=1);

final class AmocrmPhone
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
        return strlen($digits) >= 10 ? $digits : null;
    }

    /** @param mixed $customFieldsValues */
    public static function fromCustomFields($customFieldsValues): ?string
    {
        if (!is_array($customFieldsValues)) {
            return null;
        }
        foreach ($customFieldsValues as $cf) {
            if (!is_array($cf)) {
                continue;
            }
            $code = strtoupper((string)($cf['field_code'] ?? $cf['code'] ?? ''));
            $name = mb_strtolower((string)($cf['field_name'] ?? ''));
            if ($code !== 'PHONE' && !str_contains($name, 'телефон') && !str_contains($name, 'phone')) {
                continue;
            }
            foreach ($cf['values'] ?? [] as $v) {
                if (is_array($v)) {
                    $n = self::normalize((string)($v['value'] ?? ''));
                    if ($n !== null) {
                        return $n;
                    }
                }
            }
        }
        return null;
    }
}
