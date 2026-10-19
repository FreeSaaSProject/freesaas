<?php
declare(strict_types=1);

$adminId = (int)($_POST['admin'] ?? 0);
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$day = (int)($_POST['day'] ?? 0);

$res = ReviewModerationLlm::run($adminId, $year, $month, $day);

AgentHttp::emit([
    'ok' => !empty($res['ok']),
    'text' => (string)($res['text'] ?? ''),
    'error' => (string)($res['error'] ?? ''),
    'pending_count' => (int)($res['pending_count'] ?? 0),
    'context_count' => (int)($res['context_count'] ?? 0),
]);
