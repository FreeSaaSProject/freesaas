<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error('POST required', 405);
}

$raw = file_get_contents('php://input');
$bodyIn = [];
if (is_string($raw) && $raw !== '' && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $bodyIn = $decoded;
    }
}
if ($bodyIn === []) {
    $bodyIn = $_POST;
}

$token = trim((string)($bodyIn['token'] ?? ''));
$action = trim((string)($bodyIn['action'] ?? ''));
if ($token === '' || $action === '') {
    AgentHttp::error('token and action required');
}

$userId = Auth::userId();
$result = AssistantHarness::resolvePending($token, $action, $userId);
if (!$result['ok']) {
    AgentHttp::error(
        $result['error'] !== '' ? $result['error'] : t('assistant.chat.error'),
        400,
        ['action' => $result['action']]
    );
}

$message = $result['action'] === 'confirm'
    ? AssistantHarness::formatConfirmDone($result['result'] ?? null)
    : t('assistant.confirm.cancelled');

if ($userId > 0 && class_exists('AssistantDialog', false)) {
    $history = AssistantDialog::getHistory($userId);
    $history[] = ['role' => 'assistant', 'content' => $message];
    AssistantDialog::saveHistory($userId, $history);
}

AgentHttp::emit([
    'ok' => true,
    'action' => $result['action'],
    'result' => $result['result'],
    'message' => $message,
    'history' => class_exists('AssistantDialog', false)
        ? AssistantDialog::getHistory($userId)
        : [],
]);
