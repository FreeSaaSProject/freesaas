<?php
declare(strict_types=1);

/** Shared token check for /yclients/jobs/* endpoints. */
final class YclientsJobsAuth
{
    public static function assertToken(): void
    {
        $expected = trim((string)Config::get('yclients', 'worker_token', ''));
        if ($expected === '' || $expected === 'CHANGE_ME') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'worker_token not configured'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $got = (string)($_SERVER['HTTP_X_YCLIENTS_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
        if (!hash_equals($expected, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
