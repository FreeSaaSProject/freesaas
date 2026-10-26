<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$provider = (string)Router::param('provider');
$integrationId = (int)($_GET['integration_id'] ?? $_POST['integration_id'] ?? 0);

try {
    $started = BankOAuth::start($provider, $integrationId);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    flash_set('error', $e->getMessage());
    redirect($integrationId > 0 ? '/banks/integrations/' . $integrationId : '/banks');
}

if (!empty($started['sandbox_connection_id'])) {
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'sandbox' => true,
            'connection_id' => (int)$started['sandbox_connection_id'],
            'integration_id' => (int)($started['integration_id'] ?? 0),
            'authorize_url' => $started['url'],
        ]);
        return;
    }
    flash_set('ok', t('banks.oauth.callback_ok'));
    redirect($started['url']);
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'authorize_url' => $started['url'],
        'provider' => $started['provider'],
        'consent_id' => $started['consent_id'],
        'integration_id' => (int)($started['integration_id'] ?? 0),
    ]);
    return;
}

redirect($started['url']);
