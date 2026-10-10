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

$userId = Auth::userId();
if ($userId < 1) {
    AgentHttp::error(t('assistant.bot.denied'), 401);
}

$message = trim((string)($bodyIn['message'] ?? ''));
$history = AssistantDialog::getHistory($userId);

if ($message !== '') {
    $textConfirm = AssistantHarness::tryResolveConfirmText($message, $userId);
    if (is_array($textConfirm) && !empty($textConfirm['handled'])) {
        $history[] = ['role' => 'user', 'content' => $message];
        if (!empty($textConfirm['ok']) && ($textConfirm['action'] ?? '') === 'confirm') {
            $reply = t('assistant.confirm.done');
            if (is_array($textConfirm['result'] ?? null)) {
                $snippet = json_encode($textConfirm['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($snippet) && $snippet !== '' && strlen($snippet) <= 2000) {
                    $reply .= "\n" . $snippet;
                }
            }
        } elseif (!empty($textConfirm['ok'])) {
            $reply = t('assistant.confirm.cancelled');
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
if ($pendingOut === [] && preg_match('/\b(confirm|подтверд)/iu', $reply)) {
    $reply .= "\n\n" . t('assistant.confirm.none');
}
$history[] = ['role' => 'assistant', 'content' => $reply];
AssistantDialog::saveHistory($userId, $history);

AgentHttp::emit([
    'ok' => true,
    'text' => $reply,
    'pending_actions' => $pendingOut,
    'tool_trace' => $result['tool_trace'],
    'profile' => $result['profile'],
    'history' => AssistantDialog::getHistory($userId),
]);
