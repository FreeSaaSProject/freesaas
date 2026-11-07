<?php
declare(strict_types=1);

BitrixJobsAuth::assertWorkerToken();
$res = BitrixApiClient::ping();
BitrixSyncStateRepository::mark('ping', !empty($res['ok']), '', (string)($res['error'] ?? ''));
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => !empty($res['ok']), 'ping' => $res], JSON_UNESCAPED_UNICODE);
