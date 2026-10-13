<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$idCert = (int)($_GET['id_cert'] ?? 0);
$idEvent = (int)($_GET['id_event'] ?? 0);
$idOrder = (int)($_GET['id_order'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['undo_id'])) {
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        if ($wantsJson) {
            AgentHttp::error(t('certificates.err.forbidden'), 403);
            return;
        }
        flash_set('error', t('certificates.err.forbidden'));
        redirect('/certificates/ledger');
    }
    $undo = CertificateProcessor::undoSpend((int)$_POST['undo_id']);
    if ($wantsJson) {
        if (empty($undo['ok'])) {
            AgentHttp::error((string)($undo['error'] ?? t('certificates.err.apply_failed')), 400);
            return;
        }
        AgentHttp::emit(['ok' => true, 'undo' => $undo]);
        return;
    }
    if (empty($undo['ok'])) {
        flash_set('error', (string)($undo['error'] ?? t('certificates.err.apply_failed')));
    } else {
        flash_set('ok', t('core.common.saved'));
    }
    $ret = '/certificates/ledger';
    if ($idCert > 0) {
        $ret .= '?id_cert=' . $idCert;
    }
    redirect($ret);
}

$lq = ListQuery::resolve([
    'list_id' => 'certificates_ledger',
    'allowed' => CertificateLedgerRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'id_cert' => $idCert > 0 ? (string)$idCert : '',
    'id_event' => $idEvent > 0 ? (string)$idEvent : '',
    'id_order' => $idOrder > 0 ? (string)$idOrder : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$result = CertificateLedgerRepository::list([
    'q' => $q,
    'id_cert' => $idCert,
    'id_event' => $idEvent,
    'id_order' => $idOrder,
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
            'id_cert' => $idCert,
            'id_event' => $idEvent,
            'id_order' => $idOrder,
        ],
    ]);
    return;
}

View::render('ledger', [
    'title' => t('certificates.menu.ledger'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('certificates.menu.list'), 'href' => '/certificates'],
        ['label' => t('certificates.menu.ledger'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idCert' => $idCert,
    'idEvent' => $idEvent,
    'idOrder' => $idOrder,
    'canUndo' => Auth::capability('SUPERADMIN_ACCESS'),
    'flash' => flash_take(),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
], 'layout', 'certificates');
