<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = Auth::userId();
if ($uid < 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    return;
}

$raw = file_get_contents('php://input') ?: '';
$data = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if ($data === []) {
    $data = $_POST;
}

$version = trim((string)($data['version'] ?? ''));
if ($version === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'version required'], JSON_UNESCAPED_UNICODE);
    return;
}

if (!HomeSplash::ack($uid, $version)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'version mismatch',
        'current' => HomeSplash::currentVersion(),
    ], JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode([
    'ok' => true,
    'version' => HomeSplash::currentVersion(),
], JSON_UNESCAPED_UNICODE);
