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
            $creds = [
                'client_id' => trim((string)self::get('tochka_client_id', '')),
                'client_secret' => trim((string)self::get('tochka_client_secret', '')),
                'redirect_uri' => trim((string)self::get('tochka_redirect_uri', '')),
                'scope' => trim((string)self::get('tochka_scope', 'accounts balances customers statements')),
                'auth_base' => rtrim(trim((string)self::get('tochka_auth_base', 'https://enter.tochka.com')), '/'),
                'token_url' => rtrim(trim((string)self::get('tochka_auth_base', 'https://enter.tochka.com')), '/') . '/connect/token',
                'api_base' => rtrim(trim((string)self::get('tochka_api_base', 'https://enter.tochka.com/uapi')), '/'),
                'sandbox' => (int)self::get('tochka_sandbox', 0) === 1,
            ];
            return self::withAutoRedirect($p, $creds);
        }

        if ($p === 'alfa_ul' || $p === 'alfa_fl') {
            $prefix = $p;
            return self::withAutoRedirect($p, [
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
            ]);
        }

        if ($p === 'tbank_ul' || $p === 'tbank_fl') {
            $prefix = $p;
            $defaultScope = $p === 'tbank_fl'
                ? 'opensme/individual/accounts/debit/get'
                : 'opensme/inn/0/kpp/0/bank-accounts/get opensme/inn/0/kpp/0/bank-statements/get';
            return self::withAutoRedirect($p, [
                'client_id' => trim((string)self::get($prefix . '_client_id', '')),
                'client_secret' => trim((string)self::get($prefix . '_client_secret', '')),
                'redirect_uri' => trim((string)self::get($prefix . '_redirect_uri', '')),
                'scope' => trim((string)self::get($prefix . '_scope', $defaultScope)),
                'auth_base' => rtrim(trim((string)self::get($prefix . '_auth_base', 'https://id.tbank.ru')), '/'),
                'token_url' => trim((string)self::get($prefix . '_token_url', 'https://id.tbank.ru/auth/token')),
                'api_base' => rtrim(trim((string)self::get($prefix . '_api_base', 'https://business.tbank.ru/openapi')), '/'),
                'sandbox' => false,
            ]);
        }

        if ($p === 'sber_ul') {
            return self::withAutoRedirect($p, [
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
            ]);
        }

        return self::withAutoRedirect($p, [
            'client_id' => trim((string)self::get('sber_fl_client_id', '')),
            'client_secret' => trim((string)self::get('sber_fl_client_secret', '')),
            'redirect_uri' => trim((string)self::get('sber_fl_redirect_uri', '')),
            'scope' => trim((string)self::get('sber_fl_scope', 'openid obru_accounts_pe')),
            'auth_base' => rtrim(trim((string)self::get('sber_fl_auth_base', '')), '/'),
            'token_url' => trim((string)self::get('sber_fl_token_url', '')),
            'api_base' => rtrim(trim((string)self::get('sber_fl_api_base', '')), '/'),
            'sandbox' => false,
        ]);
    }

    /**
     * @param array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool} $creds
     * @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool}
     */
    private static function withAutoRedirect(string $provider, array $creds): array
    {
        if (trim($creds['redirect_uri']) === '') {
            $creds['redirect_uri'] = self::oauthRedirectUri($provider);
        }
        return $creds;
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

    /**
     * Cabinets / docs where staff obtain tokens and OAuth apps.
     * @return list<array{url:string,label_key:string}>
     */
    public static function portalLinks(string $provider): array
    {
        $p = self::normalizeProvider($provider);
        $map = [
            'tochka' => [
                ['url' => 'https://id.tochka.com/cabinet/oauth-apps/new', 'label_key' => 'banks.portal.tochka_oauth_new'],
                ['url' => 'https://id.tochka.com/cabinet/oauth-apps', 'label_key' => 'banks.portal.tochka_oauth_list'],
                ['url' => 'https://developers.tochka.com/docs/tochka-api/', 'label_key' => 'banks.portal.tochka_docs'],
            ],
            'alfa_ul' => [
                ['url' => 'https://developers.alfabank.ru/', 'label_key' => 'banks.portal.alfa_dev'],
                ['url' => 'https://alfabank.ru/sme/alfaapi/', 'label_key' => 'banks.portal.alfa_api'],
            ],
            'alfa_fl' => [
                ['url' => 'https://developers.alfabank.ru/', 'label_key' => 'banks.portal.alfa_dev'],
            ],
            'tbank_ul' => [
                ['url' => 'https://business.tbank.ru/', 'label_key' => 'banks.portal.tbank_cabinet'],
                ['url' => 'https://developer.tinkoff.ru/docs/intro/manuals/self-service-auth', 'label_key' => 'banks.portal.tbank_token'],
                ['url' => 'https://developer.tinkoff.ru/tapi/', 'label_key' => 'banks.portal.tbank_docs'],
            ],
            'tbank_fl' => [
                ['url' => 'https://developer.tinkoff.ru/docs/intro/partner/tid', 'label_key' => 'banks.portal.tbank_tid'],
                ['url' => 'https://developer.tinkoff.ru/tapi/', 'label_key' => 'banks.portal.tbank_docs'],
            ],
            'sber_ul' => [
                ['url' => 'https://developers.sber.ru/portal/products/sber-api', 'label_key' => 'banks.portal.sber_api'],
                ['url' => 'https://developers.sber.ru/docs/ru/sber-api/start/oauth', 'label_key' => 'banks.portal.sber_oauth'],
            ],
            'sber_fl' => [
                ['url' => 'https://developers.sber.ru/docs/ru/sberid/service/overview', 'label_key' => 'banks.portal.sber_id'],
                ['url' => 'https://developers.sber.ru/portal/products/sber-api', 'label_key' => 'banks.portal.sber_api'],
            ],
        ];
        return $map[$p] ?? [];
    }

    /** HTML chip-row of external portal links for a provider. */
    public static function portalLinksHtml(string $provider): string
    {
        $links = self::portalLinks($provider);
        if ($links === []) {
            return '';
        }
        $parts = [];
        foreach ($links as $link) {
            $parts[] = '<a class="banks-portal-link" href="' . e($link['url']) . '" target="_blank" rel="noopener noreferrer">'
                . e(t($link['label_key'])) . '</a>';
        }
        return '<p class="banks-portal-links"><span class="muted">' . e(t('banks.portal.where')) . '</span> '
            . implode(' · ', $parts) . '</p>';
    }

    /** Absolute OAuth callback for this instance + provider. */
    public static function oauthRedirectUri(string $provider): string
    {
        $p = self::normalizeProvider($provider);
        if ($p === '') {
            return '';
        }
        $origin = class_exists('DbTools', false)
            ? DbTools::publicOrigin()
            : '';
        if ($origin === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
            if (strpos($host, ',') !== false) {
                $host = trim(explode(',', $host, 2)[0]);
            }
            $origin = $host !== '' ? (($https ? 'https' : 'http') . '://' . $host) : '';
        }
        if ($origin === '') {
            return '';
        }
        return rtrim($origin, '/') . '/banks/oauth/' . $p . '/callback';
    }

    /** Effective redirect URI: saved value or auto-generated for this host. */
    public static function effectiveRedirectUri(string $provider): string
    {
        $p = self::normalizeProvider($provider);
        if ($p === '') {
            return '';
        }
        $saved = trim((string)self::get($p . '_redirect_uri', ''));
        if ($saved !== '') {
            return $saved;
        }
        return self::oauthRedirectUri($p);
    }

    /** Keys editable on /banks/settings (secrets kept if post empty). */
    public static function settingsKeys(): array
    {
        return [
            'default_move_type_id',
            'worker_token',
            'tochka_sandbox',
            'tochka_client_id',
            'tochka_client_secret',
            'tochka_redirect_uri',
            'alfa_ul_client_id',
            'alfa_ul_client_secret',
            'alfa_ul_redirect_uri',
            'alfa_fl_client_id',
            'alfa_fl_client_secret',
            'alfa_fl_redirect_uri',
            'tbank_ul_access_token',
            'tbank_ul_client_id',
            'tbank_ul_client_secret',
            'tbank_ul_redirect_uri',
            'tbank_fl_client_id',
            'tbank_fl_client_secret',
            'tbank_fl_redirect_uri',
            'sber_ul_client_id',
            'sber_ul_client_secret',
            'sber_ul_redirect_uri',
            'sber_fl_client_id',
            'sber_fl_client_secret',
            'sber_fl_redirect_uri',
            'sber_fl_auth_base',
            'sber_fl_token_url',
            'sber_fl_api_base',
        ];
    }

    /** @return list<string> secret keys — empty post keeps previous */
    public static function secretKeys(): array
    {
        return [
            'worker_token',
            'tochka_client_secret',
            'alfa_ul_client_secret',
            'alfa_fl_client_secret',
            'tbank_ul_access_token',
            'tbank_ul_client_secret',
            'tbank_fl_client_secret',
            'sber_ul_client_secret',
            'sber_fl_client_secret',
        ];
    }

    /** @return array<string,string> */
    public static function formValues(): array
    {
        $out = [];
        foreach (self::settingsKeys() as $key) {
            $val = (string)self::get($key, $key === 'tochka_sandbox' ? '0' : '');
            if (in_array($key, self::secretKeys(), true)) {
                $out[$key] = '';
                $out[$key . '_masked'] = self::maskSecret($val);
                $out[$key . '_set'] = $val !== '' && $val !== 'CHANGE_ME' ? '1' : '0';
            } else {
                $out[$key] = $val;
            }
        }
        // Prefill empty redirect URIs from current instance host (HTTPS on prod).
        foreach (self::PROVIDERS as $p) {
            $rk = $p . '_redirect_uri';
            if (!array_key_exists($rk, $out)) {
                continue;
            }
            $suggested = self::oauthRedirectUri($p);
            $out[$rk . '_suggested'] = $suggested;
            if (trim((string)$out[$rk]) === '' && $suggested !== '') {
                $out[$rk] = $suggested;
            }
        }
        return $out;
    }

    public static function saveFromPost(array $post): void
    {
        foreach (self::settingsKeys() as $key) {
            if (in_array($key, self::secretKeys(), true)) {
                $incoming = trim((string)($post[$key] ?? ''));
                if ($incoming !== '') {
                    Config::set('banks', $key, $incoming);
                }
                continue;
            }
            if ($key === 'tochka_sandbox') {
                Config::set('banks', $key, !empty($post[$key]) ? '1' : '0');
                continue;
            }
            if ($key === 'default_move_type_id') {
                Config::set('banks', $key, (string)max(0, (int)($post[$key] ?? 0)));
                continue;
            }
            $val = trim((string)($post[$key] ?? ''));
            // If redirect left blank, persist auto URL for this host.
            if (substr($key, -13) === '_redirect_uri' && $val === '') {
                $provider = substr($key, 0, -13);
                $val = self::oauthRedirectUri($provider);
            }
            Config::set('banks', $key, $val);
        }
    }

    private static function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === 'CHANGE_ME') {
            return '—';
        }
        if (mb_strlen($value) <= 8) {
            return '••••';
        }
        return mb_substr($value, 0, 4) . '…' . mb_substr($value, -4);
    }
}
