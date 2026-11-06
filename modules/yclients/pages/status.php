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
            $gaps = YclientsConfig::credentialGaps();
            $hint = 'api config incomplete (' . implode(', ', $gaps) . ') — POST /yclients/settings';
            if (in_array('user_token', $gaps, true) && !in_array('partner_token', $gaps, true)) {
                $hint .= ' or action=auth_login with salon login/password';
            }
            if ($wantsJson) {
                AgentHttp::error($hint, 400, [
                    'missing' => $gaps,
                    'setup' => YclientsConfig::agentSetup(),
                ]);
                return;
            }
        } else {
            $res = YclientsApiClient::pingCompany();
            $err = (string)($res['error'] ?? '');
            $http = (int)($res['http'] ?? 0);
            if ($http === 401 || $http === 403) {
                $err = ($err !== '' ? $err . ' — ' : '')
                    . 'check partner_token + user_token rights for this company_id';
            }
            YclientsSyncStateRepository::mark(
                'ping',
                !empty($res['ok']),
                $err,
                [
                    'http' => $http,
                    'via' => 'session',
                    'title' => is_array($res['data']['data'] ?? null)
                        ? (string)($res['data']['data']['title'] ?? $res['data']['data']['company_name'] ?? '')
                        : '',
                ]
            );
            if ($wantsJson) {
                if (empty($res['ok'])) {
                    AgentHttp::error($err !== '' ? $err : 'ping failed', 502, [
                        'detail' => $res,
                        'setup' => YclientsConfig::agentSetup(),
                    ]);
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
