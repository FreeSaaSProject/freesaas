<?php
declare(strict_types=1);

/**
 * Shared token check for ASR claim/result endpoints.
 */
final class AsrWorkerAuth
{
    public static function assertToken(): void
    {
        $expected = (string)Config::get('audio', 'worker_token', '');
        if ($expected === '' || $expected === 'CHANGE_ME') {
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
