<?php
declare(strict_types=1);

/** cfg/modules/banks.ini helpers. */
final class BanksConfig
{
    public const PROVIDERS = [
        'tochka',
        'alfa_ul',
        'alfa_fl',
        'tbank_ul',
        'tbank_fl',
        'sber_ul',
        'sber_fl',
    ];

    public static function get(string $key, $default = '')
    {
        return Config::get('banks', $key, $default);
    }

    public static function defaultMoveTypeId(): int
    {
        return max(0, (int)self::get('default_move_type_id', 0));
    }

    public static function workerTokenConfigured(): bool
    {
        $t = trim((string)self::get('worker_token', ''));
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    public static function workerToken(): string
    {
        return trim((string)self::get('worker_token', ''));
    }

    /**
     * @return array{
     *   client_id:string,client_secret:string,redirect_uri:string,scope:string,
     *   auth_base:string,token_url:string,api_base:string,sandbox:bool
     * }
     */
    public static function providerCreds(string $provider): array
    {
        $p = self::normalizeProvider($provider);
        if ($p === '') {
            throw new InvalidArgumentException(t('banks.err.provider'));
        }

        if ($p === 'tochka') {
            return [
                'client_id' => trim((string)self::get('tochka_client_id', '')),
                'client_secret' => trim((string)self::get('tochka_client_secret', '')),
                'redirect_uri' => trim((string)self::get('tochka_redirect_uri', '')),
                'scope' => trim((string)self::get('tochka_scope', 'accounts balances customers statements')),
                'auth_base' => rtrim(trim((string)self::get('tochka_auth_base', 'https://enter.tochka.com')), '/'),
                'token_url' => rtrim(trim((string)self::get('tochka_auth_base', 'https://enter.tochka.com')), '/') . '/connect/token',
                'api_base' => rtrim(trim((string)self::get('tochka_api_base', 'https://enter.tochka.com/uapi')), '/'),
                'sandbox' => (int)self::get('tochka_sandbox', 0) === 1,
            ];
        }

        if ($p === 'alfa_ul' || $p === 'alfa_fl') {
            $prefix = $p;
            return [
                'client_id' => trim((string)self::get($prefix . '_client_id', '')),
                'client_secret' => trim((string)self::get($prefix . '_client_secret', '')),
                'redirect_uri' => trim((string)self::get($prefix . '_redirect_uri', '')),
                'scope' => trim((string)self::get(
                    $prefix . '_scope',
                    $p === 'alfa_fl' ? 'openid obru_accounts_pe' : 'openid profile email'
                )),
                'auth_base' => rtrim(trim((string)self::get($prefix . '_auth_base', 'https://id.alfabank.ru')), '/'),
                'token_url' => trim((string)self::get($prefix . '_token_url', 'https://baas.alfabank.ru/oidc/token')),
                'api_base' => rtrim(trim((string)self::get($prefix . '_api_base', 'https://baas.alfabank.ru')), '/'),
                'sandbox' => false,
            ];
        }

        if ($p === 'tbank_ul' || $p === 'tbank_fl') {
            $prefix = $p;
            $defaultScope = $p === 'tbank_fl'
                ? 'opensme/individual/accounts/debit/get'
                : 'opensme/inn/0/kpp/0/bank-accounts/get opensme/inn/0/kpp/0/bank-statements/get';
            return [
                'client_id' => trim((string)self::get($prefix . '_client_id', '')),
                'client_secret' => trim((string)self::get($prefix . '_client_secret', '')),
                'redirect_uri' => trim((string)self::get($prefix . '_redirect_uri', '')),
                'scope' => trim((string)self::get($prefix . '_scope', $defaultScope)),
                'auth_base' => rtrim(trim((string)self::get($prefix . '_auth_base', 'https://id.tbank.ru')), '/'),
                'token_url' => trim((string)self::get($prefix . '_token_url', 'https://id.tbank.ru/auth/token')),
                'api_base' => rtrim(trim((string)self::get($prefix . '_api_base', 'https://business.tbank.ru/openapi')), '/'),
                'sandbox' => false,
            ];
        }

        // sber_ul | sber_fl
        $prefix = $p;
        if ($p === 'sber_ul') {
            return [
                'client_id' => trim((string)self::get('sber_ul_client_id', '')),
                'client_secret' => trim((string)self::get('sber_ul_client_secret', '')),
                'redirect_uri' => trim((string)self::get('sber_ul_redirect_uri', '')),
                'scope' => trim((string)self::get(
                    'sber_ul_scope',
                    'openid GET_STATEMENT_ACCOUNT GET_CLIENT_ACCOUNTS'
                )),
                'auth_base' => rtrim(trim((string)self::get('sber_ul_auth_base', 'https://sbi.sberbank.ru:9443')), '/'),
                'token_url' => trim((string)self::get(
                    'sber_ul_token_url',
                    'https://fintech.sberbank.ru:9443/ic/sso/api/v2/oauth/token'
                )),
                'api_base' => rtrim(trim((string)self::get('sber_ul_api_base', 'https://fintech.sberbank.ru:9443')), '/'),
                'sandbox' => false,
            ];
        }

        return [
            'client_id' => trim((string)self::get('sber_fl_client_id', '')),
            'client_secret' => trim((string)self::get('sber_fl_client_secret', '')),
            'redirect_uri' => trim((string)self::get('sber_fl_redirect_uri', '')),
            'scope' => trim((string)self::get('sber_fl_scope', 'openid obru_accounts_pe')),
            'auth_base' => rtrim(trim((string)self::get('sber_fl_auth_base', '')), '/'),
            'token_url' => trim((string)self::get('sber_fl_token_url', '')),
            'api_base' => rtrim(trim((string)self::get('sber_fl_api_base', '')), '/'),
            'sandbox' => false,
        ];
    }

    public static function providerConfigured(string $provider): bool
    {
        $p = self::normalizeProvider($provider);
        if ($p === '') {
            return false;
        }
        if ($p === 'tochka' && (int)self::get('tochka_sandbox', 0) === 1) {
            return true;
        }
        if ($p === 'tbank_ul' && trim((string)self::get('tbank_ul_access_token', '')) !== '') {
            return true;
        }
        $c = self::providerCreds($p);
        if ($p === 'sber_fl') {
            return $c['client_id'] !== ''
                && $c['client_secret'] !== ''
                && $c['redirect_uri'] !== ''
                && $c['auth_base'] !== ''
                && $c['api_base'] !== ''
                && $c['token_url'] !== '';
        }
        return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['redirect_uri'] !== '';
    }

    public static function normalizeProvider(string $provider): string
    {
        $p = strtolower(trim($provider));
        return in_array($p, self::PROVIDERS, true) ? $p : '';
    }

    public static function providerLabel(string $provider): string
    {
        $p = self::normalizeProvider($provider);
        return $p !== '' ? t('banks.provider.' . $p) : $provider;
    }
}
