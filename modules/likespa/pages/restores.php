<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!LikespaConfig::restoresEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('likespa.restores.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('likespa.restores.err.disabled'),
    ]);
    return;
}

$q = trim((string)($_GET['q'] ?? ''));

$lq = ListQuery::resolve([
    'list_id' => 'likespa_restores',
    'allowed' => ProcedureRestoresRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('likespa_restores', '/likespa/restores', $filters);

$result = ProcedureRestoresRepository::list([
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

$bulkFlash = BulkActions::consumeFlash();
$canSuper = Auth::capability('SUPERADMIN_ACCESS');

View::render('restores_list', [
    'title' => t('likespa.menu.restores'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.restores'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'canEdit' => Auth::can('likespa.restores'),
    'canSuper' => $canSuper,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'likespa');
