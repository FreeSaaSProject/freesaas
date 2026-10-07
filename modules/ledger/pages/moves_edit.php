<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [
    'account_id' => (int)($_GET['account_id'] ?? 0),
    'type_id' => (int)($_GET['type_id'] ?? 0),
    'direction' => -1,
    'summ' => (string)($_GET['summ'] ?? '0'),
    'descr' => (string)($_GET['descr'] ?? ''),
    'dt' => time(),
    'unconfirmed' => 1,
    'payment_id' => (int)($_GET['payment_id'] ?? 0),
    'supplier_id' => (int)($_GET['supplier_id'] ?? 0),
    'user_payment' => 0,
    'fund_movement_id' => 0,
    'act2_in' => 0,
    'act2_not_req' => 0,
    'dt_promised' => 0,
    'promise_executed' => 0,
];
if ($isNew && isset($_GET['dt']) && trim((string)$_GET['dt']) !== '') {
    $rawDt = trim((string)$_GET['dt']);
    if (ctype_digit($rawDt)) {
        $row['dt'] = (int)$rawDt;
    } else {
        $parsed = strtotime(str_replace('T', ' ', $rawDt));
        if ($parsed !== false) {
            $row['dt'] = $parsed;
        }
    }
}
if ($isNew && isset($_GET['direction']) && $_GET['direction'] !== '') {
    $d = (int)$_GET['direction'];
    if (in_array($d, [-1, 0, 1], true)) {
        $row['direction'] = $d;
    }
}
if (!$isNew) {
    $found = LedgerMovesRepository::find($id);
    if (!$found || (int)$found['deleted'] > 0) {
        if ($wantsJson) {
            AgentHttp::error(t('ledger.moves.err.not_found', ['id' => $id]), 404);
            return;
        }
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
            $newId = LedgerMovesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => 'create', 'id' => $newId, 'row' => LedgerMovesRepository::find($newId)]);
                return;
            }
            redirect('/ledger/moves/' . $newId . '/edit?saved=1');
            return;
        }
        LedgerMovesRepository::update($id, $data);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'update', 'id' => $id, 'row' => LedgerMovesRepository::find($id)]);
            return;
        }
        $message = t('core.common.saved');
        $row = LedgerMovesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, [
            'account_id' => (int)($_POST['account_id'] ?? $row['account_id']),
            'type_id' => (int)($_POST['type_id'] ?? $row['type_id']),
            'direction' => (int)($_POST['direction'] ?? ($row['direction'] ?? -1)),
            'summ' => $_POST['summ'] ?? $row['summ'],
            'descr' => (string)($_POST['descr'] ?? $row['descr']),
            'dt' => (string)($_POST['dt'] ?? $row['dt']),
            'payment_id' => (int)($_POST['payment_id'] ?? 0),
            'supplier_id' => (int)($_POST['supplier_id'] ?? 0),
            'user_payment' => (int)($_POST['user_payment'] ?? 0),
            'fund_movement_id' => (int)($_POST['fund_movement_id'] ?? 0),
            'act2_in' => (int)($_POST['act2_in'] ?? 0),
            'act2_not_req' => !empty($_POST['act2_not_req']) ? 1 : 0,
            'dt_promised' => (string)($_POST['dt_promised'] ?? ''),
            'promise_executed' => !empty($_POST['promise_executed']) ? 1 : 0,
            'unconfirmed' => !empty($_POST['unconfirmed']) ? 1 : 0,
        ]);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$typeMeta = AccountMoveTypesRepository::leafMeta();
$type = null;
$typeId = (int)($row['type_id'] ?? 0);
if ($typeId > 0) {
    $type = AccountMoveTypesRepository::find($typeId) ?: ($typeMeta[$typeId] ?? null);
}

$prevSuggestions = ['defaults' => [], 'buttons' => []];
$prevApplied = [];
if ($isNew && $typeId > 0) {
    $prevSuggestions = LedgerMovesRepository::prevSuggestionsForType($typeId);
    // Apply unanimous defaults only when field still empty / not forced from GET.
    $defs = $prevSuggestions['defaults'];
    if ((int)($row['account_id'] ?? 0) < 1 && isset($defs['account_id']) && (int)$defs['account_id'] > 0) {
        $row['account_id'] = (int)$defs['account_id'];
        $prevApplied['account_id'] = true;
    }
    if (!isset($_GET['direction']) && isset($defs['direction'])) {
        $row['direction'] = (int)$defs['direction'];
        $prevApplied['direction'] = true;
    }
    if ((int)($row['supplier_id'] ?? 0) < 1 && isset($defs['supplier_id'])) {
        $row['supplier_id'] = (int)$defs['supplier_id'];
        $prevApplied['supplier_id'] = true;
    }
}

// Type default account if still empty.
if ($type && (int)($row['account_id'] ?? 0) < 1 && (int)($type['account_id'] ?? 0) > 0) {
    $row['account_id'] = (int)$type['account_id'];
}
if ($type && !isset($_GET['direction']) && empty($prevApplied['direction']) && isset($type['direction'])) {
    // Keep row direction if already set from type default on create without prev.
    if ($isNew && (int)($row['direction'] ?? 0) === -1 && (int)$type['direction'] !== 0) {
        $row['direction'] = (int)$type['direction'];
    }
}

$dtValue = is_numeric($row['dt'] ?? null) ? date('Y-m-d\TH:i', (int)$row['dt']) : (string)($row['dt'] ?? '');
$dtPromisedRaw = $row['dt_promised'] ?? 0;
if (is_numeric($dtPromisedRaw) && (int)$dtPromisedRaw > 0 && (int)date('Y', (int)$dtPromisedRaw) > 1970) {
    $dtPromisedValue = date('Y-m-d', (int)$dtPromisedRaw);
} elseif (is_string($dtPromisedRaw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $dtPromisedRaw)) {
    $dtPromisedValue = substr($dtPromisedRaw, 0, 10);
} else {
    $dtPromisedValue = '';
}
$staff = UsersRepository::staffNamesIncluding([(int)($row['user_payment'] ?? 0)]);
$suppliers = class_exists('SuppliersRepository') ? SuppliersRepository::names(true) : [];
$stickersEnabled = ModuleManager::isEnabled('stickers');
$actOptions = [];
if ($stickersEnabled && class_exists('Acts2InRepository')) {
    $actOptions = Acts2InRepository::optionsForSelect(80, (int)($row['act2_in'] ?? 0));
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'row' => $row,
        'type' => is_array($type) ? $type : null,
        'type_meta' => $typeMeta,
        'prev_suggestions' => $prevSuggestions,
        'prev_applied' => $prevApplied,
        'accounts' => LedgerAccountsRepository::names(true),
        'staff' => $staff,
        'suppliers' => $suppliers,
        'act_options' => $actOptions,
        'soft' => [
            'payments' => ModuleManager::isEnabled('payments'),
            'suppliers' => ModuleManager::isEnabled('suppliers'),
            'stickers' => $stickersEnabled,
            'funds' => ModuleManager::isEnabled('funds'),
        ],
        'dt_value' => $dtValue,
        'dt_promised_value' => $dtPromisedValue,
    ]);
    return;
}

View::render('moves_edit', [
    'title' => $isNew ? t('ledger.moves.edit_new') : t('ledger.moves.edit', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('ledger.menu.moves'), 'href' => '/ledger/moves'],
        ['label' => $isNew ? t('ledger.moves.edit_new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'type' => is_array($type) ? $type : null,
    'typeMeta' => $typeMeta,
    'prevSuggestions' => $prevSuggestions,
    'prevApplied' => $prevApplied,
    'accounts' => LedgerAccountsRepository::names(true),
    'staff' => $staff,
    'suppliers' => $suppliers,
    'actOptions' => $actOptions,
    'fundsEnabled' => ModuleManager::isEnabled('funds'),
    'stickersEnabled' => $stickersEnabled,
    'dtValue' => $dtValue,
    'dtPromisedValue' => $dtPromisedValue,
    'message' => $message,
    'error' => $error,
], 'layout', 'ledger');
