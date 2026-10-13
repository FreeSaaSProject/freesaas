<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$idPoll = (int)($_GET['id_poll'] ?? 0);
$progress = (string)($_GET['progress'] ?? '');
$idOperator = (int)($_GET['id_operator'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'poll_invites',
    'allowed' => PollInvitesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'id_poll' => $idPoll > 0 ? (string)$idPoll : '',
    'progress' => $progress,
    'id_operator' => $idOperator > 0 ? (string)$idOperator : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('poll_invites', '/poll-invites', $filters);

$result = PollInvitesRepository::list([
    'q' => $q,
    'id_poll' => $idPoll,
    'progress' => $progress,
    'id_operator' => $idOperator,
    'user_id' => Auth::userId(),
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$pollNames = PollsRepository::rootNames(false);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $result['items'],
        'total' => $result['total'],
    ]);
    return;
}

View::render('list', [
    'title' => t('poll_invites.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idPoll' => $idPoll,
    'progress' => $progress,
    'idOperator' => $idOperator,
    'pollNames' => $pollNames,
    'canEdit' => Auth::capability('SUPERADMIN_ACCESS'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_invites.menu.list'), 'current' => true],
    ]),
], 'layout', 'poll_invites');
