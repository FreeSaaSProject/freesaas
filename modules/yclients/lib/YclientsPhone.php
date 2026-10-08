<?php
declare(strict_types=1);

/** RU-oriented phone normalize for YClients → base_clients.tel match. */
final class YclientsPhone
{
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) === 10) {
            return '7' . $digits;
        }
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            return '7' . substr($digits, 1);
        }
        if (strlen($digits) >= 10) {
            return '7' . substr($digits, -10);
        }
        return null;
    }
}
