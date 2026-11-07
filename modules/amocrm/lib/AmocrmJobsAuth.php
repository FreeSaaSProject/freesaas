<?php
declare(strict_types=1);

final class AmocrmJobsAuth
{
    public static function assertWorkerToken(): void
    {
        $expected = trim(AmocrmConfig::string('worker_token'));
        if ($expected === '' || $expected === 'CHANGE_ME') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'worker_token not configured']);
            exit;
        }
        $got = (string)($_SERVER['HTTP_X_AMOCRM_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
        if (!hash_equals($expected, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            exit;
        }
    }

    public static function assertWebhookToken(): void
    {
        $expected = trim(AmocrmConfig::string('webhook_token'));
        if ($expected === '' || $expected === 'CHANGE_ME') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'webhook_token not configured']);
            exit;
        }
        $got = (string)($_SERVER['HTTP_X_AMOCRM_WEBHOOK_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
        if (!hash_equals($expected, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            exit;
        }
    }
}
