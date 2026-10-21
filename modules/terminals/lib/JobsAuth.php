<?php
declare(strict_types=1);

/** Shared token check for /terminals/jobs/* endpoints. */
final class JobsAuth
{
    public static function assertToken(): void
    {
        $expected = trim((string)Config::get('terminals', 'worker_token', ''));
        if ($expected === '' || $expected === 'CHANGE_ME') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'worker_token not configured'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Prefer header; allow POST body. Never ?token= (Referer/log leak).
        $got = (string)($_SERVER['HTTP_X_TERMINALS_TOKEN'] ?? $_POST['token'] ?? '');
        if (!hash_equals($expected, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
