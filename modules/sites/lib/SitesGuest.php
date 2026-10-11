<?php
declare(strict_types=1);

/**
 * Storefront guest identity: phone in session before checkout / booking wizard.
 */
final class SitesGuest
{
    private const KEY = 'sites_guest';

    /** Normalize to 11-digit 7XXXXXXXXXX or empty. */
    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return '';
        }
        return $digits;
    }

    public static function phone(): string
    {
        $raw = $_SESSION[self::KEY]['phone'] ?? '';
        return is_string($raw) ? self::normalizePhone($raw) : '';
    }

    public static function clientId(): int
    {
        return max(0, (int)($_SESSION[self::KEY]['client_id'] ?? 0));
    }

    public static function hasPhone(): bool
    {
        return self::phone() !== '';
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /**
     * Set phone, resolve client, return gate result.
     *
     * @return array{ok:bool,phone:string,client_id:int,blocked:bool,error:string}
     */
    public static function setPhone(string $raw): array
    {
        $phone = self::normalizePhone($raw);
        if ($phone === '') {
            return [
                'ok' => false,
                'phone' => '',
                'client_id' => 0,
                'blocked' => false,
                'error' => t('sites.cart.err_phone_format'),
            ];
        }

        $clientId = 0;
        $blocked = false;
        if (class_exists(ClientsRepository::class, false) && Database::tableExists('base_clients')) {
            $client = ClientsRepository::findByPhoneDigits($phone);
            if ($client !== null) {
                $clientId = (int)($client['id'] ?? 0);
                if (!ClientsRepository::canBookOnline($client)) {
                    $blocked = true;
                }
            }
        }

        $_SESSION[self::KEY] = [
            'phone' => $phone,
            'client_id' => $clientId,
            'blocked' => $blocked ? 1 : 0,
        ];

        if ($blocked) {
            return [
                'ok' => false,
                'phone' => $phone,
                'client_id' => $clientId,
                'blocked' => true,
                'error' => t('sites.cart.err_blocked'),
            ];
        }

        return [
            'ok' => true,
            'phone' => $phone,
            'client_id' => $clientId,
            'blocked' => false,
            'error' => '',
        ];
    }

    public static function isBlocked(): bool
    {
        if (!empty($_SESSION[self::KEY]['blocked'])) {
            return true;
        }
        $phone = self::phone();
        if ($phone === '' || !class_exists(ClientsRepository::class, false)) {
            return false;
        }
        $client = ClientsRepository::findByPhoneDigits($phone);
        if ($client === null) {
            return false;
        }
        if (!ClientsRepository::canBookOnline($client)) {
            $_SESSION[self::KEY]['blocked'] = 1;
            $_SESSION[self::KEY]['client_id'] = (int)($client['id'] ?? 0);
            return true;
        }
        return false;
    }

    /** Ready for wizard / checkout form (phone present and not blocked). */
    public static function canProceed(): bool
    {
        return self::hasPhone() && !self::isBlocked();
    }
}
