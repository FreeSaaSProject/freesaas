<?php
declare(strict_types=1);

ClientPortalAuth::ensureBag();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$done = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $token = trim((string)($_POST['token'] ?? $token));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if ($pass !== $pass2) {
        $error = t('client_portal.reset.err_password_mismatch');
    } else {
        $res = ClientPortalPasswordReset::consume($token, $pass);
        if (empty($res['ok'])) {
            $error = (string)($res['error'] ?? t('client_portal.reset.err_token'));
        } else {
            $done = true;
            ClientPortalAuth::login((int)$res['client_id']);
            if (AgentHttp::wantsJson()) {
                AgentHttp::emit(['ok' => true, 'client_id' => (int)$res['client_id']]);
                return;
            }
            redirect('/cabinet');
        }
    }
    if (AgentHttp::wantsJson()) {
        AgentHttp::error($error !== '' ? $error : t('client_portal.reset.err_token'), 400);
        return;
    }
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'has_token' => $token !== '']);
    return;
}

ClientPortalView::render('reset', [
    'title' => t('client_portal.reset.title'),
    'token' => $token,
    'error' => $error,
    'done' => $done,
]);
