<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$provider = (string)Router::param('provider');
$code = trim((string)($_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if ($error !== '') {
    $msg = t('banks.oauth.callback_fail') . ': ' . $error;
    if ($wantsJson) {
        AgentHttp::error($msg, 400);
    }
    flash_set('error', $msg);
    redirect('/banks');
}

try {
    $done = BankOAuth::complete($provider, $code, $state);
    $id = (int)$done['connection_id'];
    // Pull remote accounts once
    try {
        foreach (BankOAuth::fetchRemoteAccounts($id) as $remote) {
            BankAccountLinksRepository::upsertRemote($id, $remote);
        }
    } catch (Throwable $e) {
        // connection is still valid; accounts can be refreshed later
    }
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'connection_id' => $id]);
    }
    flash_set('ok', t('banks.oauth.callback_ok'));
    redirect('/banks/connections/' . $id);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', t('banks.oauth.callback_fail') . ': ' . $e->getMessage());
    redirect('/banks');
}
