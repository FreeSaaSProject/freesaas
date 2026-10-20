<?php
declare(strict_types=1);

VideoWorkerAuth::assertToken();

$rel = trim((string)($_GET['rel'] ?? ''));
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'bad rel'], JSON_UNESCAPED_UNICODE);
    exit;
}

$abs = VideoStorage::abs($rel);
if (!is_file($abs)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mime = 'application/octet-stream';
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
if (in_array($ext, ['jpg', 'jpeg'], true)) {
    $mime = 'image/jpeg';
} elseif ($ext === 'png') {
    $mime = 'image/png';
} elseif ($ext === 'pt') {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($abs));
readfile($abs);
