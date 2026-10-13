<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error('POST required', 405);
}

$userId = Auth::userId();
if ($userId < 1) {
    AssistantAccess::denyJson(401);
}

AssistantDialog::clearSession($userId);

AgentHttp::emit([
    'ok' => true,
    'text' => t('assistant.chat.cleared'),
    'history' => [],
    'pending_actions' => [],
]);
