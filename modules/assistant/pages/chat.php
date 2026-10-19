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

$userId = Auth::userId();
if ($userId < 1) {
    AssistantAccess::denyJson(401);
}

$message = trim((string)($bodyIn['message'] ?? ''));
$history = AssistantDialog::getHistory($userId);

if ($message !== '') {
    $textConfirm = AssistantHarness::tryResolveConfirmText($message, $userId);
    if (is_array($textConfirm) && !empty($textConfirm['handled'])) {
        $history[] = ['role' => 'user', 'content' => $message];
        if (!empty($textConfirm['ok']) || (int)($textConfirm['done'] ?? 0) > 0) {
            $reply = AssistantHarness::formatConfirmBatchDone($textConfirm);
        } else {
            $reply = (string)($textConfirm['error'] ?? t('assistant.chat.error'));
        }
        $history[] = ['role' => 'assistant', 'content' => $reply];
        AssistantDialog::saveHistory($userId, $history);
        AgentHttp::emit([
            'ok' => !empty($textConfirm['ok']),
            'text' => $reply,
            'pending_actions' => [],
            'tool_trace' => [],
            'profile' => null,
            'history' => AssistantDialog::getHistory($userId),
            'confirm_action' => (string)($textConfirm['action'] ?? ''),
            'confirm_result' => $textConfirm['result'] ?? null,
            'error' => !empty($textConfirm['ok']) ? '' : (string)($textConfirm['error'] ?? ''),
        ]);
        return;
    }
    $history[] = ['role' => 'user', 'content' => $message];
} elseif (is_array($bodyIn['messages'] ?? null) && $bodyIn['messages'] !== []) {
    // Agent / legacy: treat provided messages as the full thread to run.
    $history = AssistantDialog::normalizeMessages($bodyIn['messages']);
} else {
    AgentHttp::error(t('assistant.chat.empty'));
}

$history = AssistantDialog::trim($history);

$opts = [
    'user_id' => $userId,
    'timeout' => isset($bodyIn['timeout']) ? (int)$bodyIn['timeout'] : 90,
];
if (isset($bodyIn['profile'])) {
    $opts['profile'] = (string)$bodyIn['profile'];
}
if (isset($bodyIn['temperature'])) {
    $opts['temperature'] = (float)$bodyIn['temperature'];
}

// Page-chat (FAB): shrink tools to current module + help; inject screen JSON into prompt.
$mode = trim((string)($bodyIn['mode'] ?? ''));
$pageCtxRaw = $bodyIn['page_context'] ?? null;
if (($mode === 'page' || is_array($pageCtxRaw)) && class_exists('AssistantPageContext', false)) {
    $ctx = AssistantPageContext::fromClient(is_array($pageCtxRaw) ? $pageCtxRaw : []);
    if ($ctx !== []) {
        $opts['page_context'] = $ctx;
        $allOps = AssistantTools::availableOps();
        $filtered = AssistantPageContext::filterOpsForPage($ctx, $allOps);
        // Never leave the model with zero tools (e.g. module without agent.ops).
        $opts['ops'] = $filtered !== [] ? $filtered : $allOps;
        // Full catalog for assistant.tools.modules / list / unlock mid-run.
        $opts['full_ops'] = $allOps;
    }
}

$result = AssistantHarness::run($history, $opts);
if (!$result['ok']) {
    $err = $result['error'] !== '' ? $result['error'] : t('assistant.chat.error');
    $history[] = ['role' => 'assistant', 'content' => $err];
    AssistantDialog::saveHistory($userId, $history);
    AgentHttp::error(
        $err,
        502,
        [
            'pending_actions' => $result['pending_actions'],
            'tool_trace' => $result['tool_trace'],
            'profile' => $result['profile'],
            'history' => AssistantDialog::getHistory($userId),
        ]
    );
}

$reply = trim((string)$result['text']);
if ($reply === '') {
    $reply = t('assistant.chat.thinking');
}
$pendingOut = is_array($result['pending_actions'] ?? null) ? $result['pending_actions'] : [];
if ($pendingOut === [] && preg_match(
    '/нажм\w*.{0,48}(confirm|подтверд)|(press|click).{0,40}confirm|кнопк\w*.{0,24}(confirm|подтверд)|ожида\w*.{0,24}подтверд/iu',
    $reply
)) {
    $reply = AssistantConfig::t('assistant.confirm.not_queued');
}
$history[] = ['role' => 'assistant', 'content' => $reply];
AssistantDialog::saveHistory($userId, $history);

// Browser shadow UI (FAB / full chat). Bots never hit this page.
$uiActions = [];
if ($pendingOut !== [] && class_exists('AssistantUiActions', false)) {
    $opsForUi = [];
    if (isset($opts['full_ops']) && is_array($opts['full_ops'])) {
        $opsForUi = $opts['full_ops'];
    } elseif (isset($opts['ops']) && is_array($opts['ops'])) {
        $opsForUi = $opts['ops'];
    } elseif (class_exists('AssistantTools', false)) {
        $opsForUi = AssistantTools::availableOps();
    }
    $uiActions = AssistantUiActions::fromPending($pendingOut, $opsForUi);
}

AgentHttp::emit([
    'ok' => true,
    'text' => $reply,
    'pending_actions' => $pendingOut,
    'ui_actions' => $uiActions,
    'tool_trace' => $result['tool_trace'],
    'profile' => $result['profile'],
    'history' => AssistantDialog::getHistory($userId),
]);
