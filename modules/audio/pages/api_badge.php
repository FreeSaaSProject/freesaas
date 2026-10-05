<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('audio.badge.err.auth')], JSON_UNESCAPED_UNICODE);
    return;
}

$badgeId = trim((string)($_POST['badge_id'] ?? ''));
$result = BadgeAssignmentsRepository::save(Auth::userId(), $badgeId);
if (!$result['success']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
