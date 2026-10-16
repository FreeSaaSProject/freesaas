<?php
declare(strict_types=1);

/**
 * Shared token check for ASR / voice workers and audio_pipeline jobs.
 * Prefer [asr] worker_token; fall back to legacy [audio] worker_token.
 * UI: generate / show once on /audio/settings (superadmin).
 */
final class AsrWorkerAuth
{
    public static function configuredToken(): string
    {
        $token = trim((string)Config::get('asr', 'worker_token', ''));
        if ($token !== '' && $token !== 'CHANGE_ME') {
            return $token;
        }
        $legacy = trim((string)Config::get('audio', 'worker_token', ''));
        if ($legacy !== '' && $legacy !== 'CHANGE_ME') {
            return $legacy;
        }
        return '';
    }

    public static function isConfigured(): bool
    {
        return self::configuredToken() !== '';
    }

    /** Mask for settings UI (never dump full token into HTML after first generate flash). */
    public static function mask(?string $token = null): string
    {
        $t = $token !== null ? trim($token) : self::configuredToken();
        if ($t === '') {
            return '';
        }
        $len = strlen($t);
        if ($len <= 12) {
            return str_repeat('•', $len);
        }

        return substr($t, 0, 4) . '…' . substr($t, -4);
    }

    /**
     * Create a new token and persist to cfg/modules/asr.ini [asr] worker_token.
     * Returns plaintext once for the UI flash / copy button.
     */
    public static function generateAndPersist(): string
    {
        $token = bin2hex(random_bytes(32));
        Config::set('asr', 'worker_token', $token);

        return $token;
    }

    /**
     * If no usable token yet, generate one. Returns plaintext when newly created, else ''.
     */
    public static function ensureGenerated(): string
    {
        if (self::isConfigured()) {
            return '';
        }

        return self::generateAndPersist();
    }

    public static function assertToken(): void
    {
        $expected = self::configuredToken();
        if ($expected === '') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'worker_token not configured'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $got = (string)($_SERVER['HTTP_X_AUDIO_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
        if (!hash_equals($expected, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
