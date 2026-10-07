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

$messages = $bodyIn['messages'] ?? null;
if (!is_array($messages) || $messages === []) {
    AgentHttp::error(t('assistant.chat.empty'));
}

$opts = [
    'user_id' => Auth::userId(),
    'timeout' => isset($bodyIn['timeout']) ? (int)$bodyIn['timeout'] : 90,
];
if (isset($bodyIn['profile'])) {
    $opts['profile'] = (string)$bodyIn['profile'];
}
if (isset($bodyIn['temperature'])) {
    $opts['temperature'] = (float)$bodyIn['temperature'];
}

$result = AssistantHarness::run($messages, $opts);
if (!$result['ok']) {
    AgentHttp::error(
        $result['error'] !== '' ? $result['error'] : t('assistant.chat.error'),
        502,
        [
            'pending_actions' => $result['pending_actions'],
            'tool_trace' => $result['tool_trace'],
            'profile' => $result['profile'],
        ]
    );
}

AgentHttp::emit([
    'ok' => true,
    'text' => $result['text'],
    'pending_actions' => $result['pending_actions'],
    'tool_trace' => $result['tool_trace'],
    'profile' => $result['profile'],
]);
