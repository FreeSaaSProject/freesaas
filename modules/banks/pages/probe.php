<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$provider = BanksConfig::normalizeProvider((string)($_POST['provider'] ?? $_GET['provider'] ?? 'alfa_ul'));
if ($provider !== 'alfa_ul') {
    if ($wantsJson) {
        AgentHttp::error(t('banks.probe.only_alfa_ul'), 400);
        return;
    }
    flash_set('error', t('banks.probe.only_alfa_ul'));
    redirect('/banks/setup/alfa_ul?step=sandbox_creds');
}

try {
    BanksEgressIp::assertUsableOrThrow();
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    flash_set('error', $e->getMessage());
    redirect('/banks/setup/alfa_ul?step=egress');
}

$result = AlfaUlClient::probeCredentials();
if ($wantsJson) {
    if (!empty($result['credentials_ok'])) {
        AgentHttp::emit(['ok' => true] + $result);
        return;
    }
    AgentHttp::error((string)($result['message'] ?? t('banks.err.oauth_token')), 400, $result);
    return;
}

$msg = (string)($result['message'] ?? '');
if (!empty($result['authorize_url'])) {
    $msg .= "\n" . t('banks.probe.next_oauth') . ': ' . (string)$result['authorize_url'];
}
flash_set(!empty($result['credentials_ok']) ? 'ok' : 'error', $msg);
redirect('/banks/setup/alfa_ul?step=sandbox_creds');
