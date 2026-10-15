<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['name' => '', 'descr' => '', 'enabled' => 1, 'id_type' => 2];
if (!$isNew) {
    $found = LedgerAccountsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('ledger.accounts.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('ledger.accounts.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';

if (!$isNew && EntityDelete::tryHandle('ledger_accounts', $id, '/ledger/accounts', [
    'stay_path' => '/ledger/accounts/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($wantsJson && !Auth::can('ledger.edit')) {
        AgentHttp::error(t('ledger.err.forbidden'), 403);
        return;
    }
    try {
        $data = LedgerAccountsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = LedgerAccountsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => 'create', 'id' => $newId, 'row' => LedgerAccountsRepository::find($newId)]);
                return;
            }
            redirect('/accounts/' . $newId . '/edit?saved=1');
            return;
        }
        LedgerAccountsRepository::update($id, $data);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'update', 'id' => $id, 'row' => LedgerAccountsRepository::find($id)]);
            return;
        }
        $message = t('core.common.saved');
        $row = LedgerAccountsRepository::find($id) ?: $row;
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
    AgentHttp::emit(['ok' => true, 'is_new' => $isNew, 'row' => $row]);
    return;
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
