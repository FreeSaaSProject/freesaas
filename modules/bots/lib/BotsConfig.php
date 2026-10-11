<?php
declare(strict_types=1);

/** Channel tokens/secrets in cfg/modules/bots.ini ([bots] section). */
final class BotsConfig
{
    public const AUDIENCES = ['staff', 'client'];
    public const PLATFORMS = ['telegram', 'max'];

    /** @return list<string> */
    public static function channelKeys(): array
    {
        $out = [];
        foreach (self::AUDIENCES as $a) {
            foreach (self::PLATFORMS as $p) {
                $out[] = $a . '_' . $p;
            }
        }
        return $out;
    }

    /**
     * @return array{audience:string,platform:string,token:string,webhook_secret:string}|null
     */
    public static function channel(string $audience, string $platform): ?array
    {
        $audience = strtolower(trim($audience));
        $platform = strtolower(trim($platform));
        if (!in_array($audience, self::AUDIENCES, true) || !in_array($platform, self::PLATFORMS, true)) {
            return null;
        }
        $prefix = $audience . '_' . $platform;
        return [
            'audience' => $audience,
            'platform' => $platform,
            'token' => trim((string)Config::get('bots', $prefix . '_token', '')),
            'webhook_secret' => trim((string)Config::get('bots', $prefix . '_webhook_secret', '')),
        ];
    }

    public static function maxSslInsecure(): bool
    {
        return (string)Config::get('bots', 'max_ssl_insecure', '0') === '1';
    }

    /**
     * Dev-only: accept webhooks when channel secret is empty.
     * Default false (fail-closed). Never enable on production.
     */
    public static function allowUnsignedWebhooks(): bool
    {
        return (string)Config::get('bots', 'allow_unsigned_webhooks', '0') === '1';
    }

    public static function generateWebhookSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Channels that have a token but no webhook secret. */
    public static function channelsMissingSecret(): array
    {
        $out = [];
        foreach (self::listForUi() as $row) {
            if (!empty($row['has_token']) && empty($row['has_secret'])) {
                $out[] = (string)$row['id'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{id:string,audience:string,platform:string,has_token:bool,token_masked:string,has_secret:bool,webhook_path:string}>
     */
    public static function listForUi(): array
    {
        $out = [];
        foreach (self::channelKeys() as $id) {
            [$audience, $platform] = explode('_', $id, 2);
            $ch = self::channel($audience, $platform);
            if ($ch === null) {
                continue;
            }
            $token = $ch['token'];
            $out[] = [
                'id' => $id,
                'audience' => $audience,
                'platform' => $platform,
                'has_token' => $token !== '',
                'token_masked' => self::maskSecret($token),
                'has_secret' => $ch['webhook_secret'] !== '',
                'webhook_path' => '/bots/webhook/' . $platform . '/' . $audience,
            ];
        }
        return $out;
    }

    public static function anyTokenConfigured(): bool
    {
        foreach (self::listForUi() as $row) {
            if (!empty($row['has_token'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        foreach (self::channelKeys() as $id) {
            $tokenKey = $id . '_token';
            $secretKey = $id . '_webhook_secret';
            if (array_key_exists($tokenKey, $post)) {
                $raw = trim((string)$post[$tokenKey]);
                if ($raw === '') {
                    Config::set('bots', $tokenKey, '');
                } elseif (!self::looksMasked($raw)) {
                    Config::set('bots', $tokenKey, $raw);
                }
            }
            if (array_key_exists($secretKey, $post)) {
                $raw = trim((string)$post[$secretKey]);
                if ($raw === '') {
                    // Empty submit: keep existing, or leave empty for auto-gen below.
                } elseif (!self::looksMasked($raw)) {
                    Config::set('bots', $secretKey, $raw);
                }
            }
            // Auto-generate secret when token is set and secret still empty.
            $token = trim((string)Config::get('bots', $tokenKey, ''));
            $secret = trim((string)Config::get('bots', $secretKey, ''));
            if ($token !== '' && $secret === '') {
                Config::set('bots', $secretKey, self::generateWebhookSecret());
            }
        }
        Config::set('bots', 'max_ssl_insecure', !empty($post['max_ssl_insecure']) ? '1' : '0');
    }

    public static function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($value, 0, 4) . '…' . substr($value, -4);
    }

    private static function looksMasked(string $raw): bool
    {
        return strpos($raw, '…') !== false
            || strpos($raw, '...') !== false
            || strpos($raw, '•') !== false
            || preg_match('/^\*+$/', $raw) === 1;
    }
}
