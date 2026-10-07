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
$lq = ListQuery::resolve([
    'list_id' => 'terminals_bracelets',
    'allowed' => BraceletsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge(['q' => $q], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('terminals_bracelets', '/terminals/bracelets', $filters);

$result = BraceletsRepository::list([
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q],
    ]);
    return;
}

View::render('bracelets_list', [
    'title' => t('terminals.menu.bracelets'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.bracelets'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'canEdit' => Auth::can('terminals.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'terminals');
