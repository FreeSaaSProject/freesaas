<?php
declare(strict_types=1);

final class GcalOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/calendar';

    public static function authUrl(int $userId): string
    {
        $state = self::makeState($userId);
        $q = http_build_query([
            'client_id' => GcalConfig::clientId(),
            'redirect_uri' => GcalConfig::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
        return self::AUTH_URL . '?' . $q;
    }

    public static function makeState(int $userId): string
    {
        $payload = json_encode(['u' => $userId, 't' => time()], JSON_UNESCAPED_UNICODE);
        $sig = hash_hmac('sha256', (string)$payload, self::hmacKey());
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    public static function parseState(string $state): ?int
    {
        $raw = base64_decode(strtr($state, '-_', '+/'), true);
        if ($raw === false || strpos($raw, '|') === false) {
            return null;
        }
        [$payload, $sig] = explode('|', $raw, 2);
        $expect = hash_hmac('sha256', $payload, self::hmacKey());
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $data = json_decode($payload, true);
        if (!is_array($data) || empty($data['u'])) {
            return null;
        }
        if ((int)($data['t'] ?? 0) < time() - 3600) {
            return null;
        }
        return (int)$data['u'];
    }

    /**
     * @return array{access_token:string,refresh_token?:string,expires_at:int}|null
     */
    public static function exchangeCode(string $code): ?array
    {
        return self::tokenRequest([
            'code' => $code,
            'client_id' => GcalConfig::clientId(),
            'client_secret' => GcalConfig::clientSecret(),
            'redirect_uri' => GcalConfig::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * @return array{access_token:string,refresh_token?:string,expires_at:int}|null
     */
    public static function refresh(string $refreshToken): ?array
    {
        $tok = self::tokenRequest([
            'refresh_token' => $refreshToken,
            'client_id' => GcalConfig::clientId(),
            'client_secret' => GcalConfig::clientSecret(),
            'grant_type' => 'refresh_token',
        ]);
        if ($tok !== null && empty($tok['refresh_token'])) {
            $tok['refresh_token'] = $refreshToken;
        }
        return $tok;
    }

    private static function hmacKey(): string
    {
        $secret = GcalConfig::clientSecret();
        if ($secret === '') {
            $secret = (string)Config::get('salts', 'site', 'gcal');
        }
        return $secret;
    }

    /**
     * @param array<string,string> $params
     * @return array{access_token:string,refresh_token?:string,expires_at:int}|null
     */
    private static function tokenRequest(array $params): ?array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            return null;
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }
        return [
            'access_token' => (string)$data['access_token'],
            'refresh_token' => (string)($data['refresh_token'] ?? ''),
            'expires_at' => time() + (int)($data['expires_in'] ?? 3600) - 60,
        ];
    }
}
