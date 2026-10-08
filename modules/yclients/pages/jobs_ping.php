<?php
declare(strict_types=1);

YclientsJobsAuth::assertToken();

if (!YclientsConfig::pullEnabled()) {
    AgentHttp::error('pull disabled', 503);
    return;
}

$res = YclientsApiClient::pingCompany();
YclientsSyncStateRepository::mark(
    'ping',
    !empty($res['ok']),
    (string)($res['error'] ?? ''),
    [
        'http' => (int)($res['http'] ?? 0),
        'title' => is_array($res['data']['data'] ?? null)
            ? (string)($res['data']['data']['title'] ?? $res['data']['data']['company_name'] ?? '')
            : '',
    ]
);

if (empty($res['ok'])) {
    AgentHttp::error((string)($res['error'] ?? 'ping failed'), 502, ['detail' => $res]);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'job' => 'ping',
    'company_id' => YclientsConfig::companyId(),
    'data' => $res['data'] ?? null,
]);
