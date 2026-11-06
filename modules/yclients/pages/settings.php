<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$authHint = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'auth_login') {
            $login = (string)($_POST['login'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $code = (string)($_POST['code'] ?? '');
            $res = YclientsApiClient::authLogin($login, $password, $code);
            // Never keep password in response logs beyond this request.
            unset($_POST['password'], $password);
            if (!empty($res['needs_2fa'])) {
                if ($wantsJson) {
                    AgentHttp::error('2fa code required', 401, [
                        'needs_2fa' => true,
                        'detail' => $res['data'] ?? null,
                        'setup' => YclientsConfig::agentSetup(),
                    ]);
                    return;
                }
                $error = t('yclients.settings.auth_2fa');
            } elseif (empty($res['ok'])) {
                if ($wantsJson) {
                    AgentHttp::error((string)($res['error'] ?? 'auth failed'), 401, [
                        'http' => (int)($res['http'] ?? 0),
                        'setup' => YclientsConfig::agentSetup(),
                    ]);
                    return;
                }
                $error = (string)($res['error'] ?? t('yclients.settings.auth_failed'));
            } else {
                YclientsConfig::saveUserToken((string)$res['user_token']);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'auth_login',
                        'user' => $res['user'] ?? null,
                        'user_token_masked' => YclientsConfig::maskSecret((string)$res['user_token']),
                        'settings' => YclientsConfig::formValues(),
                        'setup' => YclientsConfig::agentSetup(),
                    ]);
                    return;
                }
                $message = t('yclients.settings.auth_saved');
                $authHint = (string)(($res['user']['name'] ?? '') !== ''
                    ? t('yclients.settings.auth_as', ['name' => (string)$res['user']['name']])
                    : '');
            }
        } else {
            YclientsConfig::saveFromPost($_POST);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'settings' => YclientsConfig::formValues(),
                    'setup' => YclientsConfig::agentSetup(),
                ]);
                return;
            }
            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$cfg = YclientsConfig::formValues();
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'setup' => YclientsConfig::agentSetup(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('yclients.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('yclients.menu.status'), 'href' => '/yclients'],
        ['label' => t('yclients.menu.settings'), 'current' => true],
    ]),
    'message' => $message !== '' ? trim($message . ($authHint !== '' ? ' — ' . $authHint : '')) : '',
    'error' => $error,
    'cfg' => $cfg,
], 'layout', 'yclients');
