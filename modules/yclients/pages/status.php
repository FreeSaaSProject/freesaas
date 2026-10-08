<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    if ($action === 'ping') {
        if (!YclientsConfig::pullEnabled()) {
            if ($wantsJson) {
                AgentHttp::error('pull disabled', 503);
                return;
            }
            // fall through to HTML status with flash-like payload
        } elseif (!YclientsConfig::apiReady()) {
            if ($wantsJson) {
                AgentHttp::error('api config incomplete — POST /yclients/settings first', 400, [
                    'setup' => YclientsConfig::agentSetup(),
                ]);
                return;
            }
        } else {
            $res = YclientsApiClient::pingCompany();
            YclientsSyncStateRepository::mark(
                'ping',
                !empty($res['ok']),
                (string)($res['error'] ?? ''),
                [
                    'http' => (int)($res['http'] ?? 0),
                    'via' => 'session',
                    'title' => is_array($res['data']['data'] ?? null)
                        ? (string)($res['data']['data']['title'] ?? $res['data']['data']['company_name'] ?? '')
                        : '',
                ]
            );
            if ($wantsJson) {
                if (empty($res['ok'])) {
                    AgentHttp::error((string)($res['error'] ?? 'ping failed'), 502, ['detail' => $res]);
                    return;
                }
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'ping',
                    'company_id' => YclientsConfig::companyId(),
                    'data' => $res['data'] ?? null,
                    'setup' => YclientsConfig::agentSetup(),
                ]);
                return;
            }
        }
    } elseif ($wantsJson) {
        AgentHttp::error('unknown action; use action=ping', 400);
        return;
    }
}

$payload = [
    'ok' => true,
    'pull_enabled' => YclientsConfig::pullEnabled(),
    'api_ready' => YclientsConfig::apiReady(),
    'worker_token_configured' => YclientsConfig::workerTokenConfigured(),
    'company_id' => YclientsConfig::companyId(),
    'map_counts' => YclientsIdMapRepository::countsByEntity(YclientsConfig::companyId()),
    'jobs' => YclientsSyncStateRepository::all(),
    'setup' => YclientsConfig::agentSetup(),
];

if ($wantsJson) {
    AgentHttp::emit($payload);
    return;
}

View::render('status', [
    'title' => t('yclients.status.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('yclients.menu.status'), 'current' => true],
    ]),
    'payload' => $payload,
], 'layout', 'yclients');
