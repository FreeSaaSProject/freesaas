<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$prefillFrom = $_GET;
if ($isNew) {
    $ret = (string)($_GET['ret'] ?? '');
    if ($ret !== '') {
        $retQuery = parse_url($ret, PHP_URL_QUERY);
        if (is_string($retQuery) && $retQuery !== '') {
            parse_str($retQuery, $retParams);
            foreach (['account_id', 'type_id', 'supplier_id', 'payment_id', 'direction'] as $k) {
                if ((!isset($prefillFrom[$k]) || $prefillFrom[$k] === '' || $prefillFrom[$k] === '0')
                    && isset($retParams[$k]) && $retParams[$k] !== '' && $retParams[$k] !== '0'
                ) {
                    $prefillFrom[$k] = $retParams[$k];
                }
            }
        }
    }
}

$row = [
    'account_id' => $isNew ? (int)($prefillFrom['account_id'] ?? 0) : 0,
    'type_id' => $isNew ? (int)($prefillFrom['type_id'] ?? 0) : 0,
    'direction' => '',
    'summ' => $isNew ? (string)($_GET['summ'] ?? '') : '0',
    'descr' => (string)($_GET['descr'] ?? ''),
    'dt' => time(),
    'unconfirmed' => 0,
    'payment_id' => $isNew ? (int)($prefillFrom['payment_id'] ?? 0) : 0,
    'supplier_id' => $isNew ? (int)($prefillFrom['supplier_id'] ?? 0) : 0,
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
if ($isNew && isset($prefillFrom['direction']) && $prefillFrom['direction'] !== '') {
    $d = (int)$prefillFrom['direction'];
    if (in_array($d, [-1, 1], true)) {
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
    // Paired transfers always use the transfer form — never the single-move editor.
    if (LedgerMovesRepository::isTransferMove($found)) {
        if ($wantsJson) {
            AgentHttp::error(t('ledger.moves.err.use_transfer_form'), 400, [
                'redirect' => '/ledger/transfers/' . $id . '/edit',
                'transfer' => true,
            ]);
            return;
        }
        redirect('/ledger/transfers/' . $id . '/edit');
        return;
    }
    $row = $found;
}
$message = '';
$error = '';
if (!$isNew && EntityDelete::tryHandle('ledger_moves', $id, '/ledger/moves?deleted=1', [
    'stay_path' => '/ledger/moves/' . $id . '/edit',
    'confirm' => 'ledger.moves.confirm_delete',
])) {
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'copy') {
            if ($isNew) {
                throw new InvalidArgumentException(t('ledger.moves.err.not_found', ['id' => $id]));
            }
            $newId = LedgerMovesRepository::copyAndEnter($id);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'copy',
                    'source_id' => $id,
                    'id' => $newId,
                    'row' => LedgerMovesRepository::find($newId),
                ]);
                return;
            }
            redirect('/ledger/moves/' . $newId . '/edit');
            return;
        }
        if ($action === 'convert_to_transfer') {
            if ($isNew) {
                throw new InvalidArgumentException(t('ledger.moves.err.not_found', ['id' => $id]));
            }
            $transferTypeId = (int)($_POST['transfer_type_id'] ?? 0);
            $accountTo = (int)($_POST['transfer_account_to'] ?? 0);
            $pair = LedgerMovesRepository::convertToTransfer($id, $transferTypeId, $accountTo);
            $fromId = (int)$pair['from_id'];
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'convert_to_transfer',
                    'from_id' => $fromId,
                    'to_id' => (int)$pair['to_id'],
                    'redirect' => '/ledger/transfers/' . $fromId . '/edit',
                ]);
                return;
            }
            flash_set('ok', t('ledger.moves.convert_ok'));
            redirect('/ledger/transfers/' . $fromId . '/edit');
            return;
        }
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
        $postedDir = array_key_exists('direction', $_POST) ? (int)$_POST['direction'] : ($row['direction'] ?? '');
        $row = array_merge($row, [
            'account_id' => (int)($_POST['account_id'] ?? $row['account_id']),
            'type_id' => (int)($_POST['type_id'] ?? $row['type_id']),
            'direction' => $postedDir,
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
$transferTypes = [];
foreach ($typeMeta as $tid => $meta) {
    if (!empty($meta['linked_enabled'])) {
        $transferTypes[(int)$tid] = (string)$meta['name'];
    }
}
$canConvertToTransfer = !$isNew
    && $transferTypes !== []
    && (int)($row['deleted'] ?? 0) < 1
    && (int)($row['linked_id'] ?? 0) < 1;

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
    $dirEmpty = ($row['direction'] === '' || $row['direction'] === null);
    if ($dirEmpty && !isset($_GET['direction']) && isset($defs['direction'])) {
        $pd = (int)$defs['direction'];
        if (in_array($pd, [-1, 1], true)) {
            $row['direction'] = $pd;
            $prevApplied['direction'] = true;
        }
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
$dirEmpty = ($row['direction'] === '' || $row['direction'] === null);
if ($type && $isNew && $dirEmpty && empty($prevApplied['direction']) && !isset($_GET['direction'])
    && isset($type['direction'])
) {
    $td = (int)$type['direction'];
    if (in_array($td, [-1, 1], true)) {
        $row['direction'] = $td;
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
$supplierSuggestions = [];
if ($type && !empty($type['supplier_enabled']) && (int)($row['supplier_id'] ?? 0) < 1) {
    $sugAccount = (int)($row['account_id'] ?? 0);
    $sugSumm = round((float)($row['summ'] ?? 0), 2);
    if ($sugAccount > 0 && $sugSumm > 0) {
        $supplierSuggestions = LedgerMovesRepository::suggestSuppliers(
            $typeId,
            $sugAccount,
            $sugSumm,
            $isNew ? 0 : $id,
            3
        );
    }
}
if ($supplierSuggestions !== [] && class_exists('SuppliersRepository')) {
    $allSupNames = SuppliersRepository::names(false);
    foreach ($supplierSuggestions as $sug) {
        $sid = (int)($sug['id'] ?? 0);
        if ($sid > 0 && !isset($suppliers[$sid])) {
            $suppliers[$sid] = $allSupNames[$sid] ?? (string)($sug['name'] ?? ('#' . $sid));
        }
    }
}
$stickersEnabled = ModuleManager::isEnabled('stickers');
$actOptions = [];
if ($stickersEnabled && class_exists('Acts2InRepository')) {
    $actOptions = Acts2InRepository::optionsForSelect(80, (int)($row['act2_in'] ?? 0));
}

$moveBadgeHtml = '';
$extraSectionsHtml = '';
if (!$isNew && $id > 0) {
    $badgePayload = EventBus::emit('ledger.moves.badges', [
        'ids' => [$id],
        'type_by_move' => [$id => (int)($row['type_id'] ?? 0)],
        'by_id' => [],
    ]);
    $moveBadgeHtml = (string)(($badgePayload['by_id'][$id] ?? ''));
    if ($moveBadgeHtml !== '' && ModuleManager::isEnabled('banks')) {
        Assets::need('banks');
    }
    $sectionsPayload = EventBus::emit('ledger.moves.edit.sections', [
        'id' => $id,
        'row' => $row,
        'type' => is_array($type) ? $type : null,
        'html' => [],
    ]);
    foreach ($sectionsPayload['html'] ?? [] as $chunk) {
        if (is_string($chunk) && $chunk !== '') {
            $extraSectionsHtml .= $chunk;
        }
    }
}
$flash = flash_take();
if ($flash && $message === '' && $error === '') {
    if (($flash['type'] ?? '') === 'ok') {
        $message = (string)$flash['message'];
    } else {
        $error = (string)$flash['message'];
    }
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
        'supplier_suggestions' => $supplierSuggestions,
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
        'badge_html' => $moveBadgeHtml,
        'extra_sections_html' => $extraSectionsHtml,
        'can_convert_to_transfer' => $canConvertToTransfer,
        'transfer_types' => $transferTypes,
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
    'supplierSuggestions' => $supplierSuggestions,
    'accounts' => LedgerAccountsRepository::names(true),
    'staff' => $staff,
    'suppliers' => $suppliers,
    'actOptions' => $actOptions,
    'fundsEnabled' => ModuleManager::isEnabled('funds'),
    'stickersEnabled' => $stickersEnabled,
    'canConvertToTransfer' => $canConvertToTransfer,
    'transferTypes' => $transferTypes,
    'dtValue' => $dtValue,
    'dtPromisedValue' => $dtPromisedValue,
    'message' => $message,
    'error' => $error,
    'moveBadgeHtml' => $moveBadgeHtml,
    'extraSectionsHtml' => $extraSectionsHtml,
], 'layout', 'ledger');
