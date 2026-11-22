<?php
declare(strict_types=1);

$message = '';
$error = '';
$results = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $res = NotifySend::send($_POST);
    if ($res['ok']) {
        $message = t('notify.send.ok');
        $results = $res['results'];
    } else {
        $error = (string)$res['error'];
        $results = $res['results'];
    }
}

if (AgentHttp::wantsJson()) {
    if ($error !== '') {
        AgentHttp::error($error, 400);
    }
    AgentHttp::emit(['ok' => true, 'message' => $message, 'results' => $results]);
}

View::render('send', [
    'title' => t('notify.send.title'),
    'message' => $message,
    'error' => $error,
    'results' => $results,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('notify.menu.send'), 'current' => true],
    ]),
], 'layout', 'notify');
