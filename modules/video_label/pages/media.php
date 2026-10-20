<?php
declare(strict_types=1);

/** Serve label images when staff has video_label.view but not necessarily video.view. */
if (!class_exists(VideoStorage::class, false)) {
    http_response_code(503);
    return;
}
$rel = trim((string)($_GET['rel'] ?? ''));
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(400);
    return;
}
$abs = VideoStorage::abs($rel);
if (!is_file($abs)) {
    http_response_code(404);
    return;
}
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
};
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=60');
readfile($abs);
