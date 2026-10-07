<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$idUser = (int)($_GET['id_user'] ?? 0);
$directionRaw = $_GET['direction'] ?? '';
$direction = $directionRaw === '' ? null : (int)$directionRaw;

$lq = ListQuery::resolve([
    'list_id' => 'bonuses',
    'allowed' => BonusPayRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'id_user' => $idUser > 0 ? (string)$idUser : '',
    'direction' => $directionRaw !== '' ? (string)$direction : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('bonuses', '/bonuses', $filters);

$result = BonusPayRepository::list([
    'q' => $q,
    'id_user' => $idUser,
    'direction' => $direction,
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
            'id_user' => $idUser,
            'direction' => $direction,
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
    'idUser' => $idUser,
    'direction' => $direction,
    'canEdit' => Auth::can('bonuses.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'bonuses');
