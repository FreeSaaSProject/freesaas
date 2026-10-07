<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['id_account' => (int)($_GET['id_account'] ?? 0), 'direction' => 1, 'dt' => time()];
if (!$isNew) {
    $found = SheetMovesRepository::find($id);
    if (!$found || !empty($found['deleted'])) {
        if ($wantsJson) {
            AgentHttp::error(t('sheets.err.not_found', ['id' => $id]), 404);
            return;
        }
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
            $newId = SheetMovesRepository::create($data, Auth::userId());
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => SheetMovesRepository::find($newId)]);
                return;
            }
            redirect('/sheets/moves/' . $newId . '/edit?saved=1');
            return;
        }
        SheetMovesRepository::update($id, $data, Auth::userId());
        $row = SheetMovesRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$accounts = SheetAccountsRepository::names(true);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'accounts' => $accounts,
    ]);
    return;
}

View::render('move_edit', [
    'title' => $isNew ? t('sheets.move.edit_new') : t('sheets.move.edit', ['id' => $id]),
    'isNew' => $isNew, 'row' => $row,
    'accounts' => $accounts,
    'message' => $message, 'error' => $error,
], 'layout', 'sheets');
