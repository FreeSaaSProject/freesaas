<?php
declare(strict_types=1);

$studyId = (int)($_GET['study_id'] ?? $_POST['study_id'] ?? 0);
$limit = max(1, min(100, (int)($_GET['limit'] ?? $_POST['limit'] ?? 1)));
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'process'));

if ($action === 'reset' && $studyId > 0) {
    $res = StudyRunner::reset($studyId);
    AgentHttp::emit(['ok' => $res['ok'], 'error' => $res['error'], 'reset' => $res['reset'], 'progress' => StudyRunner::progress($studyId)]);
}
if ($action === 'summary' && $studyId > 0) {
    $res = StudyRunner::maybeGenerateSummary($studyId);
    AgentHttp::emit([
        'ok' => $res['ok'],
        'attempted' => $res['attempted'],
        'error' => $res['error'],
        'summary' => $res['summary'],
        'progress' => StudyRunner::progress($studyId),
    ]);
}
if ($action === 'retry' && $studyId > 0) {
    $recordId = (int)($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
    $res = StudyRunner::retryResult($studyId, $recordId);
    AgentHttp::emit(['ok' => $res['ok'], 'error' => $res['error'], 'record_id' => $res['record_id'], 'progress' => StudyRunner::progress($studyId)]);
}
if ($action === 'process_all' && $studyId > 0) {
    $res = StudyRunner::processUntilDone($studyId, 200);
    $sum = StudyRunner::maybeGenerateSummary($studyId);
    AgentHttp::emit([
        'ok' => $res['ok'],
        'processed' => $res['processed'],
        'error' => $res['error'],
        'summary' => $sum,
        'progress' => StudyRunner::progress($studyId),
    ]);
}

$before = $studyId > 0 ? StudyRunner::progress($studyId) : null;
$result = StudyRunner::processNext($studyId, $limit);
$progress = StudyRunner::progress((int)$result['study_id']);
$categories = [];
if (!empty($progress['complete']) && (int)$result['study_id'] > 0) {
    $categories['summary'] = StudyRunner::maybeGenerateSummary((int)$result['study_id']);
    $progress = StudyRunner::progress((int)$result['study_id']);
}

AgentHttp::emit([
    'ok' => true,
    'processed' => $result['processed'],
    'study_id' => $result['study_id'],
    'remaining' => $result['remaining'],
    'last_record_id' => $result['last_record_id'],
    'last_result' => $result['last_result'],
    'progress_before' => $before,
    'progress' => $progress,
    'categories' => $categories,
]);
