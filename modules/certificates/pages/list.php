<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$idBuyer = (int)($_GET['id_buyer'] ?? 0);
$idHolder = (int)($_GET['id_holder'] ?? 0);
$kind = (int)($_GET['kind'] ?? 0);
$expiredRaw = $_GET['expired'] ?? '';
$expired = $expiredRaw === '' ? null : (int)$expiredRaw;
$moneyLeft = (int)($_GET['money_left'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'certificates',
    'allowed' => CertificatesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'id_buyer' => $idBuyer > 0 ? (string)$idBuyer : '',
    'id_holder' => $idHolder > 0 ? (string)$idHolder : '',
    'kind' => $kind > 0 ? (string)$kind : '',
    'expired' => $expiredRaw !== '' ? (string)$expired : '',
    'money_left' => $moneyLeft > 0 ? '1' : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('certificates', '/certificates', $filters);

$result = CertificatesRepository::list([
    'q' => $q,
    'id_buyer' => $idBuyer,
    'id_holder' => $idHolder,
    'kind' => $kind,
    'expired' => $expired,
    'money_left' => $moneyLeft,
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
            'id_buyer' => $idBuyer,
            'id_holder' => $idHolder,
            'kind' => $kind,
            'expired' => $expired,
            'money_left' => $moneyLeft,
        ],
    ]);
    return;
}

$bulkFlash = BulkActions::consumeFlash();

View::render('list', [
    'title' => t('certificates.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('certificates.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idBuyer' => $idBuyer,
    'idHolder' => $idHolder,
    'kind' => $kind,
    'expired' => $expired,
    'moneyLeft' => $moneyLeft,
    'canEdit' => Auth::can('certificates.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'certificates');
