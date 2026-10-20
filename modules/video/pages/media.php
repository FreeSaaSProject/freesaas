<?php
declare(strict_types=1);

/** Staff media serve for frames under var/video. */
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
