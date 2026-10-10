<?php
declare(strict_types=1);

$pending = GcalSyncRepository::pendingCount();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' || isset($_GET['run'])) {
    if (!GcalConfig::enabled()) {
        if (AgentHttp::wantsJson()) {
            AgentHttp::error(t('gcal.err.disabled'), 400);
        }
        flash_set('error', t('gcal.err.disabled'));
        redirect('/gcal/settings');
    }
    $limit = min(100, max(1, (int)($_REQUEST['limit'] ?? 20)));
    $reconcile = !isset($_REQUEST['reconcile']) || (string)$_REQUEST['reconcile'] !== '0';
    $res = GcalSyncService::drain($limit, $reconcile);
    if (AgentHttp::wantsJson() || $method === 'POST') {
        AgentHttp::emit([
            'ok' => true,
            'pending_before' => $pending,
            'pending_after' => GcalSyncRepository::pendingCount(),
            'result' => $res,
        ]);
    }
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'enabled' => GcalConfig::enabled(),
        'pending' => $pending,
    ]);
}

redirect('/gcal/settings');
