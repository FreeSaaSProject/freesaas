<?php
declare(strict_types=1);

/**
 * Instance-wide settings for the public client MCP ([client_mcp] in cfg/modules/client_mcp.ini).
 * Per-host settings (on/off, name, consent, tools) live in base_client_mcp_hosts.
 */
final class ClientMcpConfig
{
    public const SECTION = 'client_mcp';

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return [
            'require_name_for_new' => '1',
            'currency' => 'RUB',
            'sessions_per_ip_hour' => '20',
            'bookings_per_phone_day' => '3',
            'token_ttl_hours' => '24',
        ];
    }

    /** @return array<string,string> */
    public static function formValues(): array
    {
        return [
            'require_name_for_new' => self::requireNameForNew() ? '1' : '0',
            'currency' => self::currency(),
            'sessions_per_ip_hour' => (string)self::sessionsPerIpHour(),
            'bookings_per_phone_day' => (string)self::bookingsPerPhoneDay(),
            'token_ttl_hours' => (string)self::tokenTtlHours(),
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        $currency = strtoupper(trim((string)($post['currency'] ?? 'RUB')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RUB';
        }
        Config::set(self::SECTION, 'require_name_for_new', !empty($post['require_name_for_new']) ? '1' : '0');
        Config::set(self::SECTION, 'currency', $currency);
        Config::set(self::SECTION, 'sessions_per_ip_hour', (string)self::clampInt($post['sessions_per_ip_hour'] ?? 20, 1, 1000));
        Config::set(self::SECTION, 'bookings_per_phone_day', (string)self::clampInt($post['bookings_per_phone_day'] ?? 3, 1, 50));
        Config::set(self::SECTION, 'token_ttl_hours', (string)self::clampInt($post['token_ttl_hours'] ?? 24, 1, 720));
    }

    public static function requireNameForNew(): bool
    {
        $raw = strtolower(trim((string)Config::get(self::SECTION, 'require_name_for_new', '1')));
        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    public static function currency(): string
    {
        // Prefer platform [app] currency; module ini remains an explicit override when set.
        $mod = strtoupper(trim((string)Config::get(self::SECTION, 'currency', '')));
        if ($mod !== '' && preg_match('/^[A-Z]{3}$/', $mod)) {
            return $mod;
        }
        if (class_exists('I18n', false)) {
            return I18n::currencyCode();
        }
        return 'RUB';
    }

    public static function sessionsPerIpHour(): int
    {
        return self::clampInt(Config::get(self::SECTION, 'sessions_per_ip_hour', 20), 1, 1000);
    }

    public static function bookingsPerPhoneDay(): int
    {
        return self::clampInt(Config::get(self::SECTION, 'bookings_per_phone_day', 3), 1, 50);
    }

    public static function tokenTtlHours(): int
    {
        return self::clampInt(Config::get(self::SECTION, 'token_ttl_hours', 24), 1, 720);
    }

    public static function moduleVersion(): string
    {
        $meta = ModuleManager::get('client_mcp') ?? [];
        return (string)($meta['version'] ?? '0.1.0');
    }

    /** @param mixed $raw */
    private static function clampInt($raw, int $min, int $max): int
    {
        $n = is_numeric($raw) ? (int)$raw : $min;
        return max($min, min($max, $n));
    }
}
