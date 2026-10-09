<?php
declare(strict_types=1);

/**
 * Authenticated audio fetch for Whisper workers (job_token, not worker_token alone).
 */
$jobId = trim((string)($_GET['job_id'] ?? ''));
$jobToken = trim((string)($_GET['job_token'] ?? ''));
if ($jobId === '' || $jobToken === '') {
    http_response_code(400);
    echo 'bad request';
    return;
}

$job = VoiceTranscribeQueue::load('processing', $jobId);
if ($job === null) {
    $job = VoiceTranscribeQueue::load('pending', $jobId);
}
if ($job === null || !hash_equals((string)($job['job_token'] ?? ''), $jobToken)) {
    http_response_code(404);
    echo 'not found';
    return;
}

$rel = (string)($job['audio_rel_path'] ?? '');
$abs = $rel !== '' ? VoiceTranscribeQueue::baseDir() . '/' . $rel : '';
if ($abs === '' || !is_file($abs)) {
    http_response_code(404);
    echo 'audio missing';
    return;
}

$ext = strtolower((string)pathinfo($abs, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'ogg' || $ext === 'oga') {
    $mime = 'audio/ogg';
} elseif ($ext === 'mp3') {
    $mime = 'audio/mpeg';
} elseif ($ext === 'wav') {
    $mime = 'audio/wav';
} elseif ($ext === 'm4a') {
    $mime = 'audio/mp4';
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($abs));
header('Cache-Control: no-store');
readfile($abs);
