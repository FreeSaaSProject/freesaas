<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'ping') {
    $res = AmocrmApiClient::ping();
    if (!empty($res['ok'])) {
        $message = t('amocrm.flash.ping_ok');
    } else {
        $error = (string)($res['error'] ?? t('amocrm.flash.ping_fail'));
    }
    if ($wantsJson) {
        AgentHttp::emit(['ok' => !empty($res['ok']), 'ping' => $res, 'error' => $error !== '' ? $error : null]);
        return;
    }
}

$counts = AmocrmIdMapRepository::countsByEntity(AmocrmConfig::accountKey());
$pending = AmocrmOutboxRepository::pendingCount();
$cfg = AmocrmConfig::formValues();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'api_ready' => AmocrmConfig::apiReady(),
        'pull_enabled' => AmocrmConfig::pullEnabled(),
        'push_enabled' => AmocrmConfig::pushEnabled(),
        'map_counts' => $counts,
        'outbox_pending' => $pending,
        'account' => AmocrmConfig::accountKey(),
        'missing' => array_values(array_filter([
            AmocrmConfig::apiReady() ? null : 'base_url/access_token',
            AmocrmConfig::workerTokenConfigured() ? null : 'worker_token',
            AmocrmConfig::webhookTokenConfigured() ? null : 'webhook_token',
        ])),
    ]);
    return;
}

View::render('status', [
    'title' => t('amocrm.menu.status'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('amocrm.menu.status'), 'current' => true],
    ]),
    'counts' => $counts,
    'pending' => $pending,
    'cfg' => $cfg,
    'apiReady' => AmocrmConfig::apiReady(),
    'message' => $message,
    'error' => $error,
], 'layout', 'amocrm');
