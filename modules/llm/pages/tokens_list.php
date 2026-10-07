<?php
declare(strict_types=1);

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['delete_id'])) {
    $res = LlmConfig::deleteProfile((string)$_POST['delete_id']);
    if ($res['ok']) {
        $message = t('core.common.saved');
    } else {
        $error = $res['error'];
    }
}

$profiles = LlmConfig::listForUi();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'default_profile' => LlmConfig::load()['default_profile'],
        'profiles' => array_values($profiles),
    ]);
}

View::render('tokens_list', [
    'title' => t('llm.tokens.title'),
    'message' => $message,
    'error' => $error,
    'profiles' => $profiles,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('llm.menu.tokens'), 'current' => true],
    ]),
], 'layout', 'llm');
