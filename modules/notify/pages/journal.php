<?php
declare(strict_types=1);

$clientId = (int)($_GET['client_id'] ?? 0);
$scope = trim((string)($_GET['scope'] ?? ''));
$event = trim((string)($_GET['event'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$list = NotifyDeliveriesRepository::list([
    'client_id' => $clientId,
    'scope' => $scope,
    'event' => $event,
    'status' => $status,
    'q' => $q,
    'limit' => $limit,
    'offset' => ($page - 1) * $limit,
]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $list['items'],
        'total' => $list['total'],
        'page' => $page,
        'per_page' => $limit,
    ]);
}

View::render('journal', [
    'title' => t('notify.journal.title'),
    'items' => $list['items'],
    'total' => $list['total'],
    'page' => $page,
    'limit' => $limit,
    'client_id' => $clientId,
    'scope' => $scope,
    'event' => $event,
    'status' => $status,
    'q' => $q,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('notify.menu.journal'), 'current' => true],
    ]),
], 'layout', 'notify');
