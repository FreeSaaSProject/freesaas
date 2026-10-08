<?php
declare(strict_types=1);

ClienticsJobsAuth::assertToken();

if (!ClienticsConfig::pullEnabled()) {
    AgentHttp::error('pull disabled', 503);
    return;
}

$res = ClienticsApiClient::ping();
ClienticsSyncStateRepository::mark(
    'ping',
    !empty($res['ok']),
    (string)($res['error'] ?? ''),
    ['count' => (int)($res['count'] ?? 0), 'http' => (int)($res['http'] ?? 0)]
);

if (empty($res['ok'])) {
    AgentHttp::error((string)($res['error'] ?? 'ping failed'), 502, ['detail' => $res]);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'job' => 'ping',
    'account_id' => ClienticsConfig::accountId(),
    'count' => (int)($res['count'] ?? 0),
]);
