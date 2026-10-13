<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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

$before = (int)($data['before'] ?? $data['timeSave'] ?? 0);
if ($before < 1) {
    $before = time() + 1;
}

$marked = LikesRepository::markViewedForAuthor($uid, $before);

echo json_encode([
    'ok' => true,
    'marked' => $marked,
    'message' => t('core.widget.likes.marked'),
], JSON_UNESCAPED_UNICODE);
