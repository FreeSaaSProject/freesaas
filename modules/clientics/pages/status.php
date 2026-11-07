<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    if ($action === 'ping') {
        if (!ClienticsConfig::apiReady()) {
            if ($wantsJson) {
                AgentHttp::error('api config incomplete — POST /clientics/settings first', 400, [
                    'setup' => ClienticsConfig::agentSetup(),
                ]);
                return;
            }
        } else {
            $res = ClienticsApiClient::ping();
            ClienticsSyncStateRepository::mark(
                'ping',
                !empty($res['ok']),
                (string)($res['error'] ?? ''),
                [
                    'count' => (int)($res['count'] ?? 0),
                    'http' => (int)($res['http'] ?? 0),
                    'via' => 'session',
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
                    'account_id' => ClienticsConfig::accountId(),
                    'count' => (int)($res['count'] ?? 0),
                    'setup' => ClienticsConfig::agentSetup(),
                ]);
                return;
            }
        }
    } elseif ($action === 'inventory') {
        if (!ClienticsConfig::apiReady()) {
            if ($wantsJson) {
                AgentHttp::error('api config incomplete', 400, ['setup' => ClienticsConfig::agentSetup()]);
                return;
            }
        } else {
            $inv = ClienticsApiInventory::probe(true);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => !empty($inv['ok']),
                    'action' => 'inventory',
                    'inventory' => $inv,
                    'setup' => ClienticsConfig::agentSetup(),
                ]);
                return;
            }
        }
    } elseif ($wantsJson) {
        AgentHttp::error('unknown action; use action=ping|inventory', 400);
        return;
    }
}

$payload = [
    'ok' => true,
    'pull_enabled' => ClienticsConfig::pullEnabled(),
    'push_enabled' => ClienticsConfig::pushEnabled(),
    'appointment_sot' => ClienticsConfig::appointmentSot(),
    'default_operator_id' => ClienticsConfig::defaultOperatorId(),
    'api_ready' => ClienticsConfig::apiReady(),
    'worker_token_configured' => ClienticsConfig::workerTokenConfigured(),
    'webhook_token_configured' => ClienticsConfig::webhookTokenConfigured(),
    'account_id' => ClienticsConfig::accountId(),
    'outbox_pending' => ClienticsOutboxRepository::pendingCount(),
    'map_counts' => ClienticsIdMapRepository::countsByEntity(ClienticsConfig::accountId()),
    'inventory' => ClienticsApiInventory::last(),
    'jobs' => ClienticsSyncStateRepository::all(),
    'setup' => ClienticsConfig::agentSetup(),
];

if ($wantsJson) {
    AgentHttp::emit($payload);
    return;
}

View::render('status', [
    'title' => t('clientics.status.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('clientics.menu.status'), 'current' => true],
    ]),
    'payload' => $payload,
], 'layout', 'clientics');
