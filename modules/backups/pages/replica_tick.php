<?php
declare(strict_types=1);

/**
 * Public crumb endpoint for an in-progress replica pull.
 * Auth is the job tick_token (session dies after wipe of base_users).
 * Always emit JSON — never fall through to HTML 500 for the UI popup.
 */
header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        AgentHttp::error('POST required', 405);
        return;
    }

    $token = trim((string)($_POST['tick_token'] ?? ''));
    if (!BackupReplica::jobTokenOk($token)) {
        AgentHttp::error(t('backups.replica.err.bad_token'), 403);
        return;
    }

    $wall = (int)($_POST['wall'] ?? BackupReplica::BUDGET_SEC);
    $wall = max(5, min(25, $wall));

    $job = BackupReplica::jobResumeAndAdvance($wall);
    if ((string)($job['status'] ?? '') === 'done' && !empty($job['relogin'])) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    // LongJobPanel shape + legacy fields for older clients.
    $shaped = BackupReplicaLongJob::publicFromDomain($job, $token);
    AgentHttp::emit(array_merge($job, [
        'ok' => ($shaped['status'] ?? '') !== 'error',
        'job' => $shaped,
        'tick_token' => $token,
    ]));
} catch (Throwable $e) {
    // Prefer structured JSON even on unexpected failures (proxy still may cut long ticks).
    if (!headers_sent()) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
