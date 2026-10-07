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
    AgentHttp::error('messages[] required');
}

$opts = [
    'profile' => isset($bodyIn['profile']) ? (string)$bodyIn['profile'] : null,
    'timeout' => isset($bodyIn['timeout']) ? (int)$bodyIn['timeout'] : 60,
    'temperature' => isset($bodyIn['temperature']) ? (float)$bodyIn['temperature'] : 0.2,
    'json' => !empty($bodyIn['json']) || !empty($bodyIn['response_format']),
];
if (isset($bodyIn['max_tokens'])) {
    $opts['max_tokens'] = (int)$bodyIn['max_tokens'];
}
if (isset($bodyIn['model'])) {
    $opts['model'] = (string)$bodyIn['model'];
}

$norm = [];
foreach ($messages as $m) {
    if (!is_array($m)) {
        continue;
    }
    $role = trim((string)($m['role'] ?? ''));
    $content = (string)($m['content'] ?? '');
    if ($role === '' || $content === '') {
        continue;
    }
    $norm[] = ['role' => $role, 'content' => $content];
}
if ($norm === []) {
    AgentHttp::error('messages[] required');
}

$res = LlmClient::complete($norm, $opts);
if (!$res['ok']) {
    AgentHttp::error($res['error'] !== '' ? $res['error'] : t('llm.err.config'), 502, [
        'profile' => $res['profile'],
    ]);
}

AgentHttp::emit([
    'ok' => true,
    'text' => $res['text'],
    'json' => $res['json'],
    'profile' => $res['profile'],
    'raw' => $res['raw'],
]);
