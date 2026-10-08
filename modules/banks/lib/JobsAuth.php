<?php
declare(strict_types=1);

/** Shared token check for /banks/jobs/* endpoints. */
final class BanksJobsAuth
{
    public static function assertToken(): void
    {
        if (!BanksConfig::workerTokenConfigured()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'worker_token not configured'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $expected = BanksConfig::workerToken();
        $got = (string)($_SERVER['HTTP_X_BANKS_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
        if (!hash_equals($expected, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
