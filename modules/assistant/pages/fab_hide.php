<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error('POST required', 405);
}

$userId = Auth::userId();
if ($userId < 1) {
    AssistantAccess::denyJson(401);
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

$path = trim((string)($bodyIn['path'] ?? ''));
if ($path === '') {
    $path = request_path();
}
$path = AssistantPrefs::normalizePath($path);
if ($path === '' || $path === '/assistant' || strpos($path, '/assistant/') === 0) {
    AgentHttp::error(t('assistant.prefs.err.path'), 400);
}

AssistantPrefs::hidePath($userId, $path);

AgentHttp::emit([
    'ok' => true,
    'hidden' => true,
    'path' => $path,
    'hidden_paths' => AssistantPrefs::listHiddenPaths($userId),
]);
