<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$provider = (string)Router::param('provider');

try {
    $started = BankOAuth::start($provider);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
    redirect('/banks');
}

if (!empty($started['sandbox_connection_id'])) {
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'sandbox' => true,
            'connection_id' => (int)$started['sandbox_connection_id'],
            'authorize_url' => $started['url'],
        ]);
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
    ]);
}

redirect($started['url']);
