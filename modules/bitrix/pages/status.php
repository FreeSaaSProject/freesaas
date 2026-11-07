<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'ping') {
    $res = BitrixApiClient::ping();
    if (!empty($res['ok'])) {
        $message = t('bitrix.flash.ping_ok');
    } else {
        $error = (string)($res['error'] ?? t('bitrix.flash.ping_fail'));
    }
    if ($wantsJson) {
        AgentHttp::emit(['ok' => !empty($res['ok']), 'ping' => $res, 'error' => $error !== '' ? $error : null]);
        return;
    }
}

$counts = BitrixIdMapRepository::countsByEntity(BitrixConfig::portal() !== '' ? BitrixConfig::portal() : 'default');
$pending = BitrixOutboxRepository::pendingCount();
$cfg = BitrixConfig::formValues();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'api_ready' => BitrixConfig::apiReady(),
        'pull_enabled' => BitrixConfig::pullEnabled(),
        'push_enabled' => BitrixConfig::pushEnabled(),
        'map_counts' => $counts,
        'outbox_pending' => $pending,
        'portal' => BitrixConfig::portal(),
        'missing' => array_values(array_filter([
            BitrixConfig::apiReady() ? null : 'rest_url',
            BitrixConfig::workerTokenConfigured() ? null : 'worker_token',
            BitrixConfig::webhookTokenConfigured() ? null : 'webhook_token',
        ])),
    ]);
    return;
}

View::render('status', [
    'title' => t('bitrix.menu.status'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bitrix.menu.status'), 'current' => true],
    ]),
    'counts' => $counts,
    'pending' => $pending,
    'cfg' => $cfg,
    'apiReady' => BitrixConfig::apiReady(),
    'message' => $message,
    'error' => $error,
], 'layout', 'bitrix');
