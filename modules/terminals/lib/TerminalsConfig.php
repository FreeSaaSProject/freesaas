<?php
declare(strict_types=1);

/** Ini-backed settings for terminals module ([terminals] in cfg/modules/terminals.ini). */
final class TerminalsConfig
{
    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'registry_enabled' => '1',
            'analytics_enabled' => '1',
            'sync_enabled' => '1',
            // Legacy phpshop_system.1C_ENABLED — off → stub/debug data, no live 1C calls.
            '1c_enabled' => '1',
            '1c_url' => '',
            '1c_auth' => '',
            '1c_apikey' => '',
            '1c_terminal' => '',
            '1c_uid' => '',
            '1c_timeout_sec' => 30,
            'worker_token' => 'CHANGE_ME',
            'booking_terminal_id' => '',
            'booking_admin_id' => '',
        ];
    }

    /** @return array<string, string> */
    public static function formValues(): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            if (in_array($key, ['registry_enabled', 'analytics_enabled', 'sync_enabled', '1c_enabled'], true)) {
                $out[$key] = self::flag($key, (string)$default === '1') ? '1' : '0';
                continue;
            }
            if ($key === '1c_timeout_sec') {
                $out[$key] = (string)self::int($key, (int)$default);
                continue;
            }
            if (in_array($key, ['1c_auth', '1c_apikey', 'worker_token'], true)) {
                $raw = (string)Config::get('terminals', $key, $default);
                $out[$key . '_masked'] = self::maskSecret($raw);
                $out[$key] = '';
                continue;
            }
            $out[$key] = (string)Config::get('terminals', $key, $default);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        foreach (['registry_enabled', 'analytics_enabled', 'sync_enabled', '1c_enabled'] as $key) {
            Config::set('terminals', $key, !empty($post[$key]) ? '1' : '0');
        }

        Config::set('terminals', '1c_url', trim((string)($post['1c_url'] ?? '')));
        Config::set('terminals', '1c_terminal', trim((string)($post['1c_terminal'] ?? '')));
        Config::set('terminals', '1c_uid', trim((string)($post['1c_uid'] ?? '')));
        $timeout = max(5, min(120, (int)($post['1c_timeout_sec'] ?? 30)));
        Config::set('terminals', '1c_timeout_sec', (string)$timeout);

        Config::set('terminals', 'booking_terminal_id', trim((string)($post['booking_terminal_id'] ?? '')));
        Config::set('terminals', 'booking_admin_id', trim((string)($post['booking_admin_id'] ?? '')));

        foreach (['1c_auth', '1c_apikey', 'worker_token'] as $secretKey) {
            $incoming = (string)($post[$secretKey] ?? '');
            if (trim($incoming) !== '') {
                Config::set('terminals', $secretKey, trim($incoming));
            }
        }
    }

    public static function flag(string $key, bool $default = true): bool
    {
        $raw = Config::get('terminals', $key, $default ? '1' : '0');
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string)$raw));
        if ($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no') {
            return false;
        }
        return true;
    }

    public static function int(string $key, int $default): int
    {
        $raw = Config::get('terminals', $key, $default);
        return is_numeric($raw) ? (int)$raw : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string)Config::get('terminals', $key, $default);
    }

    public static function registryEnabled(): bool
    {
        return self::flag('registry_enabled', true);
    }

    public static function analyticsEnabled(): bool
    {
        return self::flag('analytics_enabled', true);
    }

    public static function syncEnabled(): bool
    {
        return self::flag('sync_enabled', true);
    }

    public static function workerTokenConfigured(): bool
    {
        $t = trim(self::string('worker_token', 'CHANGE_ME'));
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    /** Global toggle (legacy «Активирован модуль связи с 1С»). */
    public static function onecEnabled(): bool
    {
        return self::flag('1c_enabled', true);
    }

    /** Credentials present (independent of onecEnabled). */
    public static function onecReady(): bool
    {
        return trim(self::string('1c_url')) !== ''
            && trim(self::string('1c_auth')) !== ''
            && trim(self::string('1c_apikey')) !== ''
            && trim(self::string('1c_terminal')) !== '';
    }

    /** Live HTTP to 1C allowed: enabled + credentials. */
    public static function onecLive(): bool
    {
        return self::onecEnabled() && self::onecReady();
    }

    /**
     * Use stub/debug 1C responses (no network).
     * Only when globally off (1c_enabled=0) or explicit per-call disable.
     * Incomplete credentials while enabled must NOT stub — callers fail loudly.
     */
    public static function onecStubMode(bool $requestDisable = false): bool
    {
        return $requestDisable || !self::onecEnabled();
    }

    /** @return array{url:string,auth:string,apikey:string,terminal:string,uid:string,timeout:int} */
    public static function onec(): array
    {
        return [
            'url' => rtrim(trim(self::string('1c_url')), '/'),
            'auth' => trim(self::string('1c_auth')),
            'apikey' => trim(self::string('1c_apikey')),
            'terminal' => trim(self::string('1c_terminal')),
            'uid' => trim(self::string('1c_uid')),
            'timeout' => max(5, self::int('1c_timeout_sec', 30)),
        ];
    }

    public static function maskSecret(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === 'CHANGE_ME') {
            return t('terminals.settings.secret_empty');
        }
        $len = mb_strlen($raw);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return mb_substr($raw, 0, 2) . str_repeat('*', min(12, $len - 4)) . mb_substr($raw, -2);
    }

    public static function maskCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        $len = mb_strlen($code);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return mb_substr($code, 0, 2) . str_repeat('*', max(4, $len - 4)) . mb_substr($code, -2);
    }
}
