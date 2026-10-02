<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['name' => '', 'descr' => '', 'enabled' => 1, 'id_type' => 2];
if (!$isNew) {
    $found = LedgerAccountsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('ledger.accounts.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = LedgerAccountsRepository::dataFromPost($_POST);
        if ($isNew) {
            redirect('/accounts/' . LedgerAccountsRepository::create($data) . '/edit?saved=1');
            return;
        }
        LedgerAccountsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = LedgerAccountsRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}
View::render('accounts_edit', [
    'title' => $isNew ? t('ledger.accounts.edit_new') : t('ledger.accounts.edit', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('ledger.menu.accounts'), 'href' => '/accounts'],
        ['label' => $isNew ? t('ledger.accounts.edit_new') : (string)($row['name'] ?? '#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'ledger');
