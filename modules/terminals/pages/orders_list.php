<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!TerminalsConfig::analyticsEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('terminals.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('terminals.err.disabled')]);
    return;
}

$day = trim((string)($_GET['day'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$lq = ListQuery::resolve([
    'list_id' => 'terminals_orders',
    'allowed' => TermOrdersRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge(['day' => $day, 'from' => $from, 'to' => $to], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$result = TermOrdersRepository::list([
    'day' => $day,
    'from' => $from,
    'to' => $to,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['day' => $day, 'from' => $from, 'to' => $to],
    ]);
    return;
}

View::render('orders_list', [
    'title' => t('terminals.menu.orders'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.orders'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'day' => $day,
    'from' => $from,
    'to' => $to,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
], 'layout', 'terminals');
