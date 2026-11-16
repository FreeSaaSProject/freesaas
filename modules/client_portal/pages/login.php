<?php
declare(strict_types=1);

ClientPortalAuth::ensureBag();

if (ClientPortalAuth::check()) {
    redirect('/cabinet');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $phone = (string)($_POST['phone'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $result = ClientPortalAuth::attempt($phone, $password);
    if (!empty($result['ok'])) {
        if (AgentHttp::wantsJson()) {
            AgentHttp::emit(['ok' => true, 'client_id' => (int)$result['client_id']]);
            return;
        }
        redirect('/cabinet');
    }
    $error = (string)($result['error'] ?? t('client_portal.login.error'));
    if (AgentHttp::wantsJson()) {
        AgentHttp::error($error, 401);
        return;
    }
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'logged_in' => false, 'path' => '/cabinet/login']);
    return;
}

ClientPortalView::render('login', [
    'title' => t('client_portal.login.title'),
    'error' => $error,
]);
