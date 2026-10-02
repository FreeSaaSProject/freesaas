<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['id_account' => (int)($_GET['id_account'] ?? 0), 'direction' => 1, 'dt' => time()];
if (!$isNew) {
    $found = SheetMovesRepository::find($id);
    if (!$found || !empty($found['deleted'])) {
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
        $data = SheetMovesRepository::dataFromPost($_POST);
        if ($isNew) {
            redirect('/sheets/moves/' . SheetMovesRepository::create($data, Auth::userId()) . '/edit?saved=1');
            return;
        }
        SheetMovesRepository::update($id, $data, Auth::userId());
        $message = t('core.common.saved');
        $row = SheetMovesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}
View::render('move_edit', [
    'title' => $isNew ? t('sheets.move.edit_new') : t('sheets.move.edit', ['id' => $id]),
    'isNew' => $isNew, 'row' => $row,
    'accounts' => SheetAccountsRepository::names(true),
    'message' => $message, 'error' => $error,
], 'layout', 'sheets');
