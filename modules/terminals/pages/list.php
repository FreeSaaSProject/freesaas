<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!TerminalsConfig::registryEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('terminals.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('terminals.err.disabled')]);
    return;
}

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');

$lq = ListQuery::resolve([
    'list_id' => 'terminals',
    'allowed' => TerminalsRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q, 'enabled' => $enabled], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('terminals', '/terminals', $filters);

$result = TerminalsRepository::list([
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q, 'enabled' => $enabled],
    ]);
    return;
}

View::render('list', [
    'title' => t('terminals.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('terminals.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'terminals');
