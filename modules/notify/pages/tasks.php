<?php
declare(strict_types=1);

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Auth::can('notify.tasks.resolve')) {
        $error = t('notify.err.permission');
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'resolve') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $outcome = trim((string)($_POST['outcome'] ?? ''));
            $comment = trim((string)($_POST['comment'] ?? ''));
            $res = NotifyEngine::resolveTask($taskId, $outcome, Auth::userId(), $comment);
            if ($res['ok'] && $res['task'] !== null) {
                NotifyInbound::closeTaskMessage($res['task'], $outcome);
                $message = t('notify.tasks.resolved');
            } else {
                $error = (string)($res['error'] ?? t('notify.err.task_not_found'));
            }
        }
    }
}

$status = trim((string)($_GET['status'] ?? 'pending'));
$clientId = (int)($_GET['client_id'] ?? 0);
$list = NotifyTasksRepository::list([
    'status' => $status,
    'client_id' => $clientId,
    'limit' => 100,
]);

if (AgentHttp::wantsJson()) {
    if ($error !== '') {
        AgentHttp::error($error, 400);
    }
    AgentHttp::emit(['ok' => true, 'message' => $message, 'items' => $list['items'], 'total' => $list['total']]);
}

View::render('tasks', [
    'title' => t('notify.tasks.title'),
    'items' => $list['items'],
    'total' => $list['total'],
    'status' => $status,
    'client_id' => $clientId,
    'message' => $message,
    'error' => $error,
    'can_resolve' => Auth::can('notify.tasks.resolve'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('notify.menu.tasks'), 'current' => true],
    ]),
], 'layout', 'notify');
