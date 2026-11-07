<?php
declare(strict_types=1);

final class BitrixConfig
{
    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'pull_enabled' => '1',
            'push_enabled' => '1',
            'portal' => '',
            'rest_url' => '',
            'webhook_token' => 'CHANGE_ME',
            'worker_token' => 'CHANGE_ME',
            'timeout_sec' => 30,
            'page_size' => 50,
        ];
    }

    public static function flag(string $key, bool $default = true): bool
    {
        $raw = Config::get('bitrix', $key, $default ? '1' : '0');
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string)$raw));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no');
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string)Config::get('bitrix', $key, $default);
    }

    public static function int(string $key, int $default): int
    {
        $raw = Config::get('bitrix', $key, $default);
        return is_numeric($raw) ? (int)$raw : $default;
    }

    public static function pullEnabled(): bool
    {
        return self::flag('pull_enabled', true);
    }

    public static function pushEnabled(): bool
    {
        return self::flag('push_enabled', true);
    }

    public static function portal(): string
    {
        return trim(self::string('portal'));
    }

    public static function restUrl(): string
    {
        return rtrim(trim(self::string('rest_url')), '/') . '/';
    }

    public static function apiReady(): bool
    {
        $url = trim(self::string('rest_url'));
        return $url !== '' && str_starts_with($url, 'http');
    }

    public static function workerTokenConfigured(): bool
    {
        $t = trim(self::string('worker_token'));
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    public static function webhookTokenConfigured(): bool
    {
        $t = trim(self::string('webhook_token'));
        return $t !== '' && $t !== 'CHANGE_ME';
    }

    /** @return array<string,string> */
    public static function formValues(): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            if (in_array($key, ['pull_enabled', 'push_enabled'], true)) {
                $out[$key] = self::flag($key, true) ? '1' : '0';
                continue;
            }
            if (in_array($key, ['timeout_sec', 'page_size'], true)) {
                $out[$key] = (string)self::int($key, (int)$default);
                continue;
            }
            if (in_array($key, ['webhook_token', 'worker_token'], true)) {
                $raw = self::string($key, (string)$default);
                $out[$key . '_masked'] = self::maskSecret($raw);
                $out[$key] = '';
                continue;
            }
            $out[$key] = self::string($key, (string)$default);
        }
        return $out;
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        foreach (['pull_enabled', 'push_enabled'] as $flag) {
            if (array_key_exists($flag, $post)) {
                Config::set('bitrix', $flag, !empty($post[$flag]) ? '1' : '0');
            }
        }
        foreach (['portal', 'rest_url'] as $key) {
            if (array_key_exists($key, $post)) {
                Config::set('bitrix', $key, trim((string)$post[$key]));
            }
        }
        if (array_key_exists('timeout_sec', $post)) {
            Config::set('bitrix', 'timeout_sec', (string)max(5, min(120, (int)$post['timeout_sec'])));
        }
        if (array_key_exists('page_size', $post)) {
            Config::set('bitrix', 'page_size', (string)max(1, min(200, (int)$post['page_size'])));
        }
        foreach (['webhook_token', 'worker_token'] as $secretKey) {
            $incoming = trim((string)($post[$secretKey] ?? ''));
            if ($incoming !== '') {
                Config::set('bitrix', $secretKey, $incoming);
            }
        }
    }

    public static function maskSecret(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === 'CHANGE_ME') {
            return '';
        }
        $len = strlen($raw);
        if ($len <= 4) {
            return '****';
        }
        return str_repeat('*', max(0, $len - 4)) . substr($raw, -4);
    }
}
