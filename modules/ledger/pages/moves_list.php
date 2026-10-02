<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$accountId = (int)($_GET['account_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? ($_GET['payment'] ?? 0));
$supplierId = (int)($_GET['supplier_id'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'ledger_moves',
    'allowed' => LedgerMovesRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge([
    'q' => $q,
    'account_id' => $accountId > 0 ? (string)$accountId : '',
    'type_id' => $typeId > 0 ? (string)$typeId : '',
    'year' => $year > 0 ? (string)$year : '',
    'month' => $month > 0 ? (string)$month : '',
    'payment_id' => $paymentId > 0 ? (string)$paymentId : '',
    'supplier_id' => $supplierId > 0 ? (string)$supplierId : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('ledger_moves', '/ledger/moves', $filters);

$result = LedgerMovesRepository::list([
    'q' => $q,
    'account_id' => $accountId,
    'type_id' => $typeId,
    'year' => $year,
    'month' => $month,
    'payment_id' => $paymentId,
    'supplier_id' => $supplierId,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$reconFlash = '';
$reconError = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && Auth::can('ledger.reconcile')) {
    $action = (string)($_POST['recon_action'] ?? '');
    try {
        if ($action === 'save') {
            LedgerReconciliationsRepository::create(
                (int)($_POST['recon_account_id'] ?? 0),
                (float)str_replace(',', '.', (string)($_POST['bank_balance'] ?? '0')),
                strtotime((string)($_POST['recon_dt'] ?? 'today')) ?: time(),
                Auth::userId()
            );
            $reconFlash = t('core.common.saved');
        } elseif ($action === 'disable') {
            LedgerReconciliationsRepository::disable(
                (int)($_POST['recon_id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                Auth::userId()
            );
            $reconFlash = t('core.common.saved');
        }
    } catch (Throwable $e) {
        $reconError = $e->getMessage();
    }
}

$accounts = LedgerAccountsRepository::names();
$types = AccountMoveTypesRepository::leafNames();
$suppliers = [];
if (class_exists('SuppliersRepository')) {
    $suppliers = SuppliersRepository::names(true);
}
$recons = $accountId > 0 ? LedgerReconciliationsRepository::listForAccount($accountId, 10) : [];
$balance = $accountId > 0 ? LedgerMovesRepository::accountBalance($accountId) : null;

View::render('moves_list', [
    'title' => t('ledger.menu.moves'),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('ledger.menu.moves'), 'current' => true]]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'accountId' => $accountId,
    'typeId' => $typeId,
    'year' => $year,
    'month' => $month,
    'paymentId' => $paymentId,
    'supplierId' => $supplierId,
    'accounts' => $accounts,
    'types' => $types,
    'suppliers' => $suppliers,
    'recons' => $recons,
    'balance' => $balance,
    'canEdit' => Auth::can('ledger.edit'),
    'canRecon' => Auth::can('ledger.reconcile'),
    'years' => range((int)date('Y'), (int)date('Y') - 8),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'reconFlash' => $reconFlash,
    'reconError' => $reconError,
], 'layout', 'ledger');
