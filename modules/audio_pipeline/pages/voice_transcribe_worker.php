<?php
declare(strict_types=1);

/**
 * Mac/Whisper worker twin of oper voice_transcribe_worker.php.
 * Auth: X-Audio-Token (same as ASR jobs).
 *
 * GET  ?action=next
 * POST ?action=submit JSON {job_id, job_token, text?, error?}
 */
AsrWorkerAuth::assertToken();
header('Content-Type: application/json; charset=utf-8');

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($body) && isset($body['action'])) {
        $action = strtolower(trim((string)$body['action']));
    }
}

if ($action === 'next') {
    $job = VoiceTranscribeQueue::claimNext();
    if ($job === null) {
        echo json_encode(['ok' => true, 'job' => null], JSON_UNESCAPED_UNICODE);
        return;
    }
    $jobId = (string)($job['job_id'] ?? '');
    $jobToken = (string)($job['job_token'] ?? '');
    $job['audio_url'] = VoiceTranscribeQueue::publicFileUrl($jobId, $jobToken);
    echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_UNICODE);
    return;
}

if ($action === 'submit') {
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        $body = $_POST;
    }
    $jobId = trim((string)($body['job_id'] ?? ''));
    $jobToken = trim((string)($body['job_token'] ?? ''));
    $text = trim((string)($body['text'] ?? ''));
    $error = trim((string)($body['error'] ?? ''));
    if ($jobId === '' || $jobToken === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'job_id and job_token required'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $res = VoiceTranscribeQueue::submit($jobId, $jobToken, $text, $error);
    if (!$res['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $res['error']], JSON_UNESCAPED_UNICODE);
        return;
    }
    $job = is_array($res['job'] ?? null) ? $res['job'] : [];
    VoiceTranscribeBotPlugin::deliverResult($job, $text, $error);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    return;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
