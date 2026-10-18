<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = null;
if (!$isNew) {
    $row = LaundryAccountsRepository::find($id);
    if (!$row) {
        if ($wantsJson) {
            AgentHttp::error(t('laundry.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('laundry.err.not_found', ['id' => $id])]);
        return;
    }
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = LaundryAccountsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = LaundryAccountsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId]);
                return;
            }
            redirect('/laundry/accounts/' . $newId . '/edit?saved=1');
            return;
        }
        LaundryAccountsRepository::update($id, $data);
        $row = LaundryAccountsRepository::find($id);
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row ?? [], $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('account_edit', [
    'title' => $isNew ? t('laundry.account.edit_new') : t('laundry.account.edit', ['name' => (string)($row['name'] ?? '')]),
    'isNew' => $isNew,
    'row' => $row ?? [],
    'message' => $message,
    'error' => $error,
], 'layout', 'laundry');
