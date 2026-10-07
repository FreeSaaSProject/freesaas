<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$lq = ListQuery::resolve(['list_id' => 'funds', 'allowed' => FundsRepository::listSortMap(), 'default' => 'name', 'default_dir' => 'asc', 'per_page' => 100]);
$filters = array_merge(['q' => $q], $lq['query']);
if (!empty($lq['show_all'])) { $filters['page'] = 'all'; }

$accrualFlash = null;
$accrualRows = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Auth::can('funds.edit')) {
    $action = (string)($_POST['funds_action'] ?? '');
    if ($action === 'calculate') {
        $res = FundsAccrualRepository::calculatePayments();
        $accrualFlash = ['ok' => !empty($res['ok']), 'message' => (string)$res['message']];
        $accrualRows = $res['rows'] ?? [];
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => !empty($res['ok']),
                'action' => 'calculate',
                'message' => (string)$res['message'],
                'rows' => $accrualRows,
            ]);
            return;
        }
    } elseif ($action === 'reprice') {
        $res = FundsAccrualRepository::repriceToReal();
        $accrualFlash = ['ok' => !empty($res['ok']), 'message' => (string)$res['message']];
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => !empty($res['ok']),
                'action' => 'reprice',
                'message' => (string)$res['message'],
            ]);
            return;
        }
    }
}

BulkActions::tryHandle('funds', '/funds', $filters);
$result = FundsRepository::list(['q' => $q, 'limit' => ListQuery::sqlLimit($lq), 'offset' => $lq['offset'], 'order' => $lq['order_sql']]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'names' => FundsRepository::names(),
        'filters' => ['q' => $q],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

View::render('list', [
    'title' => t('funds.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('funds.menu.list'), 'current' => true]]),
    'items' => $result['items'], 'total' => $result['total'], 'q' => $q,
    'names' => FundsRepository::names(), 'canEdit' => Auth::can('funds.edit'),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'], 'filters' => $filters, 'bulkFlash' => BulkActions::consumeFlash(),
    'accrualFlash' => $accrualFlash, 'accrualRows' => $accrualRows,
], 'layout', 'funds');
