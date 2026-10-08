<?php
declare(strict_types=1);

/** Ini-backed settings for clientics module ([clientics] in cfg/modules/clientics.ini). */
final class ClienticsConfig
{
    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'pull_enabled' => '1',
            'domain' => 'klientiks.ru',
            'account_id' => '',
            'user_id' => '',
            'access_token' => '',
            'timeout_sec' => 30,
            'page_limit' => 100,
            'worker_token' => 'CHANGE_ME',
            'webhook_token' => 'CHANGE_ME',
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
            if (in_array($key, ['timeout_sec', 'page_limit'], true)) {
                $out[$key] = (string)self::int($key, (int)$default);
                continue;
            }
            if (in_array($key, ['access_token', 'worker_token', 'webhook_token'], true)) {
                $raw = (string)Config::get('clientics', $key, $default);
                $out[$key . '_masked'] = self::maskSecret($raw);
                $out[$key] = '';
                continue;
            }
            $out[$key] = (string)Config::get('clientics', $key, $default);
        }
        return $out;
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        // Agent-friendly partial saves: only write keys present in POST.
        if (array_key_exists('pull_enabled', $post)) {
            Config::set('clientics', 'pull_enabled', !empty($post['pull_enabled']) ? '1' : '0');
        }
        if (array_key_exists('domain', $post)) {
            $domain = trim((string)$post['domain']);
            if ($domain === '') {
                $domain = (string)self::defaults()['domain'];
            }
            Config::set('clientics', 'domain', $domain);
        }
        if (array_key_exists('account_id', $post)) {
            Config::set('clientics', 'account_id', trim((string)$post['account_id']));
        }
        if (array_key_exists('user_id', $post)) {
            Config::set('clientics', 'user_id', trim((string)$post['user_id']));
        }
        if (array_key_exists('timeout_sec', $post)) {
            Config::set('clientics', 'timeout_sec', (string)max(5, min(120, (int)$post['timeout_sec'])));
        }
        if (array_key_exists('page_limit', $post)) {
            Config::set('clientics', 'page_limit', (string)max(1, min(500, (int)$post['page_limit'])));
        }

        foreach (['access_token', 'worker_token', 'webhook_token'] as $secretKey) {
            $incoming = trim((string)($post[$secretKey] ?? ''));
            if ($incoming !== '') {
                Config::set('clientics', $secretKey, $incoming);
            }
        }
    }

    public static function flag(string $key, bool $default = true): bool
    {
        $raw = Config::get('clientics', $key, $default ? '1' : '0');
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string)$raw));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no');
    }

    public static function int(string $key, int $default): int
    {
        $raw = Config::get('clientics', $key, $default);
        return is_numeric($raw) ? (int)$raw : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string)Config::get('clientics', $key, $default);
    }

    public static function pullEnabled(): bool
    {
        return self::flag('pull_enabled', true);
    }

    public static function accountId(): string
    {
        return trim(self::string('account_id'));
    }

    public static function pageLimit(): int
    {
        return max(1, min(500, self::int('page_limit', 100)));
    }

    public static function timeoutSec(): int
    {
        return max(5, min(120, self::int('timeout_sec', 30)));
    }

    public static function domain(): string
    {
        $d = trim(self::string('domain', (string)self::defaults()['domain']));
        return $d !== '' ? $d : (string)self::defaults()['domain'];
    }

    public static function workerTokenConfigured(): bool
    {
        $t = trim(self::string('worker_token', 'CHANGE_ME'));
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    public static function webhookTokenConfigured(): bool
    {
        $t = trim(self::string('webhook_token', 'CHANGE_ME'));
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    public static function apiReady(): bool
    {
        return self::accountId() !== ''
            && trim(self::string('user_id')) !== ''
            && trim(self::string('access_token')) !== '';
    }

    /** @return array{domain:string,account_id:string,user_id:string,access_token:string,timeout:int} */
    public static function api(): array
    {
        return [
            'domain' => self::domain(),
            'account_id' => self::accountId(),
            'user_id' => trim(self::string('user_id')),
            'access_token' => trim(self::string('access_token')),
            'timeout' => self::timeoutSec(),
        ];
    }

    public static function maskSecret(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === 'CHANGE_ME') {
            return t('clientics.settings.secret_empty');
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
     *   webhook_token_configured:bool,
     *   account_id:string,
     *   missing:list<string>,
     *   writable_fields:list<string>,
     *   next_steps:list<string>
     * }
     */
    public static function agentSetup(): array
    {
        $missing = [];
        if (self::accountId() === '') {
            $missing[] = 'account_id';
        }
        if (trim(self::string('user_id')) === '') {
            $missing[] = 'user_id';
        }
        if (trim(self::string('access_token')) === '') {
            $missing[] = 'access_token';
        }
        if (!self::workerTokenConfigured()) {
            $missing[] = 'worker_token';
        }
        if (!self::webhookTokenConfigured()) {
            $missing[] = 'webhook_token';
        }

        $next = [];
        if ($missing !== []) {
            $next[] = 'POST /clientics/settings?format=json with missing fields (empty secret = keep previous)';
        }
        if (!self::apiReady()) {
            $next[] = 'After tokens: POST /clientics?format=json action=ping (session) to verify API';
        } else {
            $next[] = 'POST /clientics?format=json action=ping';
            $next[] = 'POST /clientics/import?format=json action=clients&dry_run=1&from=YYYY-MM-DD&to=YYYY-MM-DD';
        }

        return [
            'pull_enabled' => self::pullEnabled(),
            'api_ready' => self::apiReady(),
            'worker_token_configured' => self::workerTokenConfigured(),
            'webhook_token_configured' => self::webhookTokenConfigured(),
            'account_id' => self::accountId(),
            'missing' => $missing,
            'writable_fields' => [
                'pull_enabled', 'domain', 'account_id', 'user_id', 'access_token',
                'timeout_sec', 'page_limit', 'worker_token', 'webhook_token',
            ],
            'next_steps' => $next,
        ];
    }
}
