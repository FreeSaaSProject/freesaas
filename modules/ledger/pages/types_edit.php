<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [
    'name' => '', 'descr' => '', 'pid' => 0, 'enabled' => 1, 'account_id' => 0, 'num' => 0,
    'direction' => -1, 'odds_type' => 1, 'payment_enabled' => 0, 'fot_kind' => 0, 'user_payment_enabled' => 0,
    'supplier_enabled' => 0, 'linked_enabled' => 0, 'fund_enabled' => 0, 'act_in_enabled' => 0, 'exclude_pl' => 0,
];
if (!$isNew) {
    $found = AccountMoveTypesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('ledger.types.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('ledger.types.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';

if (!$isNew && EntityDelete::tryHandle('ledger_types', $id, '/ledger/types', [
    'stay_path' => '/ledger/types/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = AccountMoveTypesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = AccountMoveTypesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => 'create', 'id' => $newId, 'row' => AccountMoveTypesRepository::find($newId)]);
                return;
            }
            redirect('/account-move-types/' . $newId . '/edit?saved=1');
            return;
        }
        AccountMoveTypesRepository::update($id, $data);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'update', 'id' => $id, 'row' => AccountMoveTypesRepository::find($id)]);
            return;
        }
        $message = t('core.common.saved');
        $row = AccountMoveTypesRepository::find($id) ?: $row;
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
$parentOptions = [0 => t('ledger.types.root')] + AccountMoveTypesRepository::names();
if (!$isNew) {
    unset($parentOptions[$id]);
}
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'row' => $row,
        'parent_options' => $parentOptions,
        'accounts' => LedgerAccountsRepository::names(),
        'soft' => [
            'suppliers' => ModuleManager::isEnabled('suppliers'),
            'stickers' => ModuleManager::isEnabled('stickers'),
            'funds' => ModuleManager::isEnabled('funds'),
        ],
    ]);
    return;
}
View::render('types_edit', [
    'title' => $isNew ? t('ledger.types.edit_new') : t('ledger.types.edit', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('ledger.menu.move_types'), 'href' => '/account-move-types'],
        ['label' => $isNew ? t('ledger.types.edit_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'parentOptions' => $parentOptions,
    'accounts' => LedgerAccountsRepository::names(),
    'message' => $message,
    'error' => $error,
], 'layout', 'ledger');
