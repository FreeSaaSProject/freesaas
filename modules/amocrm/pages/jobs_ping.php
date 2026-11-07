<?php
declare(strict_types=1);

AmocrmJobsAuth::assertWorkerToken();
$res = AmocrmApiClient::ping();
AmocrmSyncStateRepository::mark('ping', !empty($res['ok']), '', (string)($res['error'] ?? ''));
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => !empty($res['ok']), 'ping' => $res], JSON_UNESCAPED_UNICODE);
