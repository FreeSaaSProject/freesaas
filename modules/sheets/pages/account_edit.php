<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['enabled' => 1];
if (!$isNew) {
    $found = SheetAccountsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('sheets.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = SheetAccountsRepository::dataFromPost($_POST);
        if ($isNew) {
            redirect('/sheets/accounts/' . SheetAccountsRepository::create($data) . '/edit?saved=1');
            return;
        }
        SheetAccountsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = SheetAccountsRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}
View::render('account_edit', [
    'title' => $isNew ? t('sheets.account.edit_new') : t('sheets.account.edit', ['name' => (string)($row['name'] ?? '')]),
    'isNew' => $isNew, 'row' => $row, 'message' => $message, 'error' => $error,
], 'layout', 'sheets');
