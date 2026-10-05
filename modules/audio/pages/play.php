<?php
declare(strict_types=1);

$file = (string)($_GET['file'] ?? '');
if ($file === '') {
    http_response_code(400);
    echo t('audio.play.missing');
    return;
}

// Only allow paths under configured audio dirs (no ..)
$file = '/' . ltrim(str_replace('\\', '/', $file), '/');
if (strpos($file, '..') !== false) {
    http_response_code(403);
    echo t('audio.play.forbidden');
    return;
}

$cfg = AudioSettingsRepository::storageConfig();
$allowedRoots = [
    '/' . trim($cfg['audio_inbox'], '/'),
    '/' . trim($cfg['audio_parsed'], '/'),
    '/' . trim($cfg['audio_chunks'], '/'),
];
$ok = false;
foreach ($allowedRoots as $root) {
    if ($root !== '/' && (strpos($file, $root . '/') === 0 || $file === $root)) {
        $ok = true;
        break;
    }
}
if (!$ok) {
    http_response_code(403);
    echo t('audio.play.forbidden');
    return;
}

$storage = FileStorage::fromSettings();
$contents = $storage->getFileContents($file);
if ($contents === false) {
    http_response_code(404);
    echo t('audio.play.not_found');
    return;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'm4a' => 'audio/mp4',
];
$download = !empty($_GET['download']);
$mime = $types[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)strlen($contents));
header('Cache-Control: private, max-age=3600');
if ($download) {
    $base = basename($file) !== '' ? basename($file) : 'record.' . ($ext !== '' ? $ext : 'bin');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $base) . '"');
}
echo $contents;
