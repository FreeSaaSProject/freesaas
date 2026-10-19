<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error('POST required', 405);
}

$raw = request_raw_body();
$bodyIn = [];
if ($raw !== '' && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $bodyIn = $decoded;
    }
}
if ($bodyIn === []) {
    $bodyIn = $_POST;
}

$action = trim((string)($bodyIn['action'] ?? ''));
$tokens = [];
if (isset($bodyIn['tokens']) && is_array($bodyIn['tokens'])) {
    foreach ($bodyIn['tokens'] as $tok) {
        $tok = trim((string)$tok);
        if ($tok !== '') {
            $tokens[] = $tok;
        }
    }
}
$token = trim((string)($bodyIn['token'] ?? ''));
if ($token !== '') {
    $tokens[] = $token;
}
$tokens = array_values(array_unique($tokens));
if ($tokens === [] || $action === '') {
    AgentHttp::error('token(s) and action required');
}

$userId = Auth::userId();
$result = count($tokens) === 1
    ? AssistantHarness::resolvePending($tokens[0], $action, $userId) + ['done' => 0, 'failed' => 0, 'results' => []]
    : AssistantHarness::resolvePendingBatch($tokens, $action, $userId);

if (count($tokens) === 1) {
    // Normalize single resolve into batch-shaped reply fields.
    if (!empty($result['ok'])) {
        $result['done'] = 1;
        $result['failed'] = 0;
    } else {
        $result['done'] = 0;
        $result['failed'] = 1;
    }
    $result['results'] = [$result];
}

if (empty($result['ok']) && (int)($result['done'] ?? 0) < 1) {
    AgentHttp::error(
        ($result['error'] ?? '') !== '' ? (string)$result['error'] : t('assistant.chat.error'),
        400,
        ['action' => $result['action'] ?? $action]
    );
}

$message = AssistantHarness::formatConfirmBatchDone($result);

if ($userId > 0 && class_exists('AssistantDialog', false)) {
    $history = AssistantDialog::getHistory($userId);
    $history[] = ['role' => 'assistant', 'content' => $message];
    AssistantDialog::saveHistory($userId, $history);
}

$emitAction = (string)($result['action'] ?? $action);
$uiActions = [];
if (
    $emitAction === 'confirm'
    && (int)($result['done'] ?? 0) > 0
    && class_exists('AssistantUiActions', false)
) {
    $uiActions = AssistantUiActions::reload();
}

AgentHttp::emit([
    'ok' => true,
    'action' => $emitAction,
    'result' => $result['result'] ?? null,
    'done' => (int)($result['done'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
    'message' => $message,
    'ui_actions' => $uiActions,
    'history' => class_exists('AssistantDialog', false)
        ? AssistantDialog::getHistory($userId)
        : [],
]);
