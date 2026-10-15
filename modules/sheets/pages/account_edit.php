<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['enabled' => 1];
if (!$isNew) {
    $found = SheetAccountsRepository::find($id);
    if (!$found) {
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

if (!$isNew && EntityDelete::tryHandle('sheet_accounts', $id, '/sheets/accounts', [
    'stay_path' => '/sheets/accounts/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = SheetAccountsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = SheetAccountsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => SheetAccountsRepository::find($newId)]);
                return;
            }
            redirect('/sheets/accounts/' . $newId . '/edit?saved=1');
            return;
        }
        SheetAccountsRepository::update($id, $data);
        $row = SheetAccountsRepository::find($id) ?: $row;
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

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'is_new' => $isNew, 'item' => $row]);
    return;
}

View::render('account_edit', [
    'title' => $isNew ? t('sheets.account.edit_new') : t('sheets.account.edit', ['name' => (string)($row['name'] ?? '')]),
    'isNew' => $isNew, 'row' => $row, 'message' => $message, 'error' => $error,
], 'layout', 'sheets');
