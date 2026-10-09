<?php
declare(strict_types=1);

/**
 * Shared token check for ASR / voice workers and audio_pipeline jobs.
 * Prefer [asr] worker_token; fall back to legacy [audio] worker_token.
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
