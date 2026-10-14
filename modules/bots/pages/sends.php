<?php
declare(strict_types=1);

$path = '/bots/sends';
$listId = 'bots_sends';

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
$audience = trim((string)($_GET['audience'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => BotSendsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_filter([
    'status' => $status >= 0 ? $status : null,
    'audience' => $audience !== '' ? $audience : null,
    'q' => $q !== '' ? $q : null,
], static fn ($v) => $v !== null && $v !== '');
$filters = array_merge($filters, $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$result = BotSendsRepository::list([
    'status' => $status,
    'audience' => $audience,
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'pending' => BotSendsRepository::countPending($audience !== '' ? $audience : null),
        'items' => $result['items'],
    ]);
}

View::render('sends', [
    'title' => t('bots.sends.title'),
    'path' => $path,
    'items' => $result['items'],
    'total' => $result['total'],
    'status' => $status,
    'audience' => $audience,
    'q' => $q,
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'pending' => BotSendsRepository::countPending(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bots.menu.settings'), 'href' => '/bots/settings'],
        ['label' => t('bots.menu.sends'), 'current' => true],
    ]),
], 'layout', 'bots');
