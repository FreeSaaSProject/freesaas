<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [
    'account_id' => (int)($_GET['account_id'] ?? 0),
    'type_id' => (int)($_GET['type_id'] ?? 0),
    'direction' => -1,
    'summ' => 0,
    'descr' => '',
    'dt' => time(),
    'unconfirmed' => 0,
    'payment_id' => (int)($_GET['payment_id'] ?? 0),
    'supplier_id' => 0,
    'user_payment' => 0,
    'fund_movement_id' => 0,
];
if (!$isNew) {
    $found = LedgerMovesRepository::find($id);
    if (!$found || (int)$found['deleted'] > 0) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('ledger.moves.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = LedgerMovesRepository::dataFromPost($_POST, $isNew ? null : $row);
        if ($isNew) {
            redirect('/ledger/moves/' . LedgerMovesRepository::create($data) . '/edit?saved=1');
            return;
        }
        LedgerMovesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = LedgerMovesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'account_id' => (int)($_POST['account_id'] ?? $row['account_id']),
            'type_id' => (int)($_POST['type_id'] ?? $row['type_id']),
            'summ' => $_POST['summ'] ?? $row['summ'],
            'descr' => (string)($_POST['descr'] ?? $row['descr']),
            'dt' => (string)($_POST['dt'] ?? $row['dt']),
            'payment_id' => (int)($_POST['payment_id'] ?? 0),
            'supplier_id' => (int)($_POST['supplier_id'] ?? 0),
            'user_payment' => (int)($_POST['user_payment'] ?? 0),
            'fund_movement_id' => (int)($_POST['fund_movement_id'] ?? 0),
            'unconfirmed' => !empty($_POST['unconfirmed']) ? 1 : 0,
        ]);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$type = AccountMoveTypesRepository::find((int)($row['type_id'] ?? 0));
$dtValue = is_numeric($row['dt'] ?? null) ? date('Y-m-d\TH:i', (int)$row['dt']) : (string)($row['dt'] ?? '');
$staff = [];
foreach (UsersRepository::listForCopy() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}
$suppliers = class_exists('SuppliersRepository') ? SuppliersRepository::names(true) : [];

View::render('moves_edit', [
    'title' => $isNew ? t('ledger.moves.edit_new') : t('ledger.moves.edit', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('ledger.menu.moves'), 'href' => '/ledger/moves'],
        ['label' => $isNew ? t('ledger.moves.edit_new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'type' => $type,
    'accounts' => LedgerAccountsRepository::names(true),
    'types' => AccountMoveTypesRepository::leafNames(),
    'staff' => $staff,
    'suppliers' => $suppliers,
    'dtValue' => $dtValue,
    'message' => $message,
    'error' => $error,
], 'layout', 'ledger');
