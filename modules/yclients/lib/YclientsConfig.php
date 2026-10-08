<?php
declare(strict_types=1);

/** Ini-backed settings for yclients module ([yclients] in cfg/modules/yclients.ini). */
final class YclientsConfig
{
    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'pull_enabled' => '1',
            'company_id' => '',
            'partner_token' => '',
            'user_token' => '',
            'api_base' => 'https://api.yclients.com/api/v1',
            'timeout_sec' => 30,
            'page_size' => 200,
            'worker_token' => 'CHANGE_ME',
        ];
    }

    /** @return array<string, string> */
    public static function formValues(): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            if ($key === 'pull_enabled') {
                $out[$key] = self::flag($key, true) ? '1' : '0';
                continue;
            }
            if (in_array($key, ['timeout_sec', 'page_size'], true)) {
                $out[$key] = (string)self::int($key, (int)$default);
                continue;
            }
            if (in_array($key, ['partner_token', 'user_token', 'worker_token'], true)) {
                $raw = (string)Config::get('yclients', $key, $default);
                $out[$key . '_masked'] = self::maskSecret($raw);
                $out[$key] = '';
                continue;
            }
            $out[$key] = (string)Config::get('yclients', $key, $default);
        }
        return $out;
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        // Agent-friendly: only flip flags / scalars when the key is present in the POST.
        // HTML form always sends checkboxes when checked; omit = keep current for partial JSON saves.
        if (array_key_exists('pull_enabled', $post)) {
            Config::set('yclients', 'pull_enabled', !empty($post['pull_enabled']) ? '1' : '0');
        }
        if (array_key_exists('company_id', $post)) {
            Config::set('yclients', 'company_id', trim((string)$post['company_id']));
        }
        if (array_key_exists('api_base', $post)) {
            $base = trim((string)$post['api_base']);
            if ($base === '') {
                $base = (string)self::defaults()['api_base'];
            }
            Config::set('yclients', 'api_base', rtrim($base, '/'));
        }
        if (array_key_exists('timeout_sec', $post)) {
            Config::set('yclients', 'timeout_sec', (string)max(5, min(120, (int)$post['timeout_sec'])));
        }
        if (array_key_exists('page_size', $post)) {
            Config::set('yclients', 'page_size', (string)max(1, min(200, (int)$post['page_size'])));
        }

        foreach (['partner_token', 'user_token', 'worker_token'] as $secretKey) {
            $incoming = trim((string)($post[$secretKey] ?? ''));
            if ($incoming !== '') {
                Config::set('yclients', $secretKey, $incoming);
            }
        }
    }

    public static function flag(string $key, bool $default = true): bool
    {
        $raw = Config::get('yclients', $key, $default ? '1' : '0');
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string)$raw));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no');
    }

    public static function int(string $key, int $default): int
    {
        $raw = Config::get('yclients', $key, $default);
        return is_numeric($raw) ? (int)$raw : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string)Config::get('yclients', $key, $default);
    }

    public static function pullEnabled(): bool
    {
        return self::flag('pull_enabled', true);
    }

    public static function companyId(): int
    {
        return max(0, self::int('company_id', 0));
    }

    public static function pageSize(): int
    {
        return max(1, min(200, self::int('page_size', 200)));
    }

    public static function timeoutSec(): int
    {
        return max(5, min(120, self::int('timeout_sec', 30)));
    }

    public static function apiBase(): string
    {
        $base = trim(self::string('api_base', (string)self::defaults()['api_base']));
        return rtrim($base !== '' ? $base : (string)self::defaults()['api_base'], '/');
    }

    public static function workerTokenConfigured(): bool
    {
        $t = trim(self::string('worker_token', 'CHANGE_ME'));
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    public static function apiReady(): bool
    {
        return self::companyId() > 0
            && trim(self::string('partner_token')) !== ''
            && trim(self::string('user_token')) !== '';
    }

    /** @return array{company_id:int,partner_token:string,user_token:string,api_base:string,timeout:int} */
    public static function api(): array
    {
        return [
            'company_id' => self::companyId(),
            'partner_token' => trim(self::string('partner_token')),
            'user_token' => trim(self::string('user_token')),
            'api_base' => self::apiBase(),
            'timeout' => self::timeoutSec(),
        ];
    }

    public static function maskSecret(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === 'CHANGE_ME') {
            return t('yclients.settings.secret_empty');
        }
        $len = mb_strlen($raw);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return mb_substr($raw, 0, 2) . str_repeat('*', min(12, $len - 4)) . mb_substr($raw, -2);
    }

    /**
     * Agent-oriented setup checklist (no secret values).
     *
     * @return array{
     *   pull_enabled:bool,
     *   api_ready:bool,
     *   worker_token_configured:bool,
     *   company_id:int,
     *   missing:list<string>,
     *   writable_fields:list<string>,
     *   next_steps:list<string>
     * }
     */
    public static function agentSetup(): array
    {
        $missing = [];
        if (self::companyId() < 1) {
            $missing[] = 'company_id';
        }
        if (trim(self::string('partner_token')) === '') {
            $missing[] = 'partner_token';
        }
        if (trim(self::string('user_token')) === '') {
            $missing[] = 'user_token';
        }
        if (!self::workerTokenConfigured()) {
            $missing[] = 'worker_token';
        }

        $next = [];
        if ($missing !== []) {
            $next[] = 'POST /yclients/settings?format=json with missing fields (empty secret = keep previous)';
        }
        if (!self::apiReady()) {
            $next[] = 'After tokens: POST /yclients?format=json action=ping (session) to verify company';
        } else {
            $next[] = 'POST /yclients?format=json action=ping';
            $next[] = 'POST /yclients/import?format=json action=clients&dry_run=1';
        }

        return [
            'pull_enabled' => self::pullEnabled(),
            'api_ready' => self::apiReady(),
            'worker_token_configured' => self::workerTokenConfigured(),
            'company_id' => self::companyId(),
            'missing' => $missing,
            'writable_fields' => [
                'pull_enabled', 'company_id', 'partner_token', 'user_token',
                'api_base', 'timeout_sec', 'page_size', 'worker_token',
            ],
            'next_steps' => $next,
        ];
    }
}
