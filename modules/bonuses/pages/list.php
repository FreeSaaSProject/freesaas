<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$clientId = (int)($_GET['client_id'] ?? $_GET['id_user'] ?? 0);
$directionRaw = $_GET['direction'] ?? '';
$direction = $directionRaw === '' ? null : (int)$directionRaw;
$kind = trim((string)($_GET['kind'] ?? ''));

$lq = ListQuery::resolve([
    'list_id' => 'bonuses',
    'allowed' => BonusMovesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'client_id' => $clientId > 0 ? (string)$clientId : '',
    'direction' => $directionRaw !== '' ? (string)$direction : '',
    'kind' => $kind,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('bonuses', '/bonuses', $filters);

$result = BonusMovesRepository::list([
    'q' => $q,
    'client_id' => $clientId,
    'direction' => $direction,
    'kind' => $kind !== '' ? $kind : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => [
            'q' => $q,
            'client_id' => $clientId,
            'direction' => $direction,
            'kind' => $kind,
        ],
    ]);
    return;
}

$bulkFlash = BulkActions::consumeFlash();

View::render('list', [
    'title' => t('bonuses.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bonuses.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'clientId' => $clientId,
    'direction' => $direction,
    'kind' => $kind,
    'canEdit' => Auth::can('bonuses.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'bonuses');
