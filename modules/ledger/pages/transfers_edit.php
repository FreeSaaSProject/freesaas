<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [
    'type_id' => 0,
    'account_id' => (int)($_GET['account_id'] ?? 0),
    'account_to' => 0,
    'summ' => 0,
    'descr' => '',
    'dt' => time(),
];
$pairIds = ['from_id' => 0, 'to_id' => 0];
$message = '';
$error = '';

if (!$isNew) {
    $pair = LedgerMovesRepository::findTransferPair($id);
    if ($pair === null) {
        if ($wantsJson) {
            AgentHttp::error(t('ledger.moves.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('ledger.moves.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $from = $pair['from'];
    $to = $pair['to'];
    $pairIds = ['from_id' => (int)$from['id'], 'to_id' => (int)$to['id']];
    $row = [
        'type_id' => (int)$from['type_id'],
        'account_id' => (int)$from['account_id'],
        'account_to' => (int)$to['account_id'],
        'summ' => $from['summ'],
        'descr' => (string)($from['descr'] ?? ''),
        'dt' => (int)$from['dt'],
    ];
}

if (!$isNew && EntityDelete::tryHandle('ledger_moves', $id, '/ledger/moves?deleted=1', [
    'stay_path' => '/ledger/transfers/' . $id . '/edit',
    'confirm' => 'ledger.moves.transfer_confirm_delete',
])) {
    return;
}

$transferTypes = [];
foreach (AccountMoveTypesRepository::list(['limit' => 500])['items'] as $t) {
    if (!empty($t['linked_enabled']) && !empty($t['enabled'])) {
        $transferTypes[(int)$t['id']] = (string)$t['name'];
    }
}
// Keep current type in select even if later disabled.
if (!$isNew && (int)$row['type_id'] > 0 && !isset($transferTypes[(int)$row['type_id']])) {
    $t = AccountMoveTypesRepository::find((int)$row['type_id']);
    if ($t) {
        $transferTypes[(int)$t['id']] = (string)$t['name'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $dt = strtotime((string)($_POST['dt'] ?? '')) ?: time();
        $typeId = (int)($_POST['type_id'] ?? 0);
        $accountFrom = (int)($_POST['account_id'] ?? 0);
        $accountTo = (int)($_POST['account_to'] ?? 0);
        $summ = money_parse((string)($_POST['summ'] ?? '0'));
        $descr = trim((string)($_POST['descr'] ?? ''));
        if ($isNew) {
            $pair = LedgerMovesRepository::createTransfer(
                $typeId,
                $accountFrom,
                $accountTo,
                $summ,
                $dt,
                $descr
            );
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'create',
                    'from_id' => $pair['from_id'],
                    'to_id' => $pair['to_id'],
                    'from' => LedgerMovesRepository::find((int)$pair['from_id']),
                    'to' => LedgerMovesRepository::find((int)$pair['to_id']),
                ]);
                return;
            }
            redirect('/ledger/transfers/' . $pair['from_id'] . '/edit?saved=1');
            return;
        }
        $pair = LedgerMovesRepository::updateTransfer(
            $id,
            $typeId,
            $accountFrom,
            $accountTo,
            $summ,
            $dt,
            $descr
        );
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'action' => 'update',
                'from_id' => $pair['from_id'],
                'to_id' => $pair['to_id'],
                'from' => LedgerMovesRepository::find((int)$pair['from_id']),
                'to' => LedgerMovesRepository::find((int)$pair['to_id']),
            ]);
            return;
        }
        $message = t('core.common.saved');
        $pairIds = $pair;
        $row = [
            'type_id' => $typeId,
            'account_id' => $accountFrom,
            'account_to' => $accountTo,
            'summ' => $summ,
            'descr' => $descr,
            'dt' => $dt,
        ];
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, [
            'type_id' => (int)($_POST['type_id'] ?? $row['type_id']),
            'account_id' => (int)($_POST['account_id'] ?? $row['account_id']),
            'account_to' => (int)($_POST['account_to'] ?? $row['account_to']),
            'summ' => $_POST['summ'] ?? $row['summ'],
            'descr' => (string)($_POST['descr'] ?? $row['descr']),
            'dt' => (string)($_POST['dt'] ?? $row['dt']),
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$dtValue = is_numeric($row['dt'] ?? null) ? date('Y-m-d\TH:i', (int)$row['dt']) : (string)($row['dt'] ?? '');

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'row' => $row,
        'from_id' => $pairIds['from_id'],
        'to_id' => $pairIds['to_id'],
        'transfer_types' => $transferTypes,
        'accounts' => LedgerAccountsRepository::names(true),
        'dt_value' => $dtValue,
    ]);
    return;
}

$title = $isNew
    ? t('ledger.moves.transfer_new')
    : t('ledger.moves.transfer_edit', ['id' => (int)$pairIds['from_id']]);

View::render('transfers_edit', [
    'title' => $title,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('ledger.menu.moves'), 'href' => '/ledger/moves'],
        ['label' => $isNew ? t('ledger.moves.transfer_new') : ('#' . (int)$pairIds['from_id'] . ' ↔ #' . (int)$pairIds['to_id']), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'fromId' => (int)$pairIds['from_id'],
    'toId' => (int)$pairIds['to_id'],
    'accounts' => LedgerAccountsRepository::names(true),
    'transferTypes' => $transferTypes,
    'dtValue' => $dtValue,
    'message' => $message,
    'error' => $error,
], 'layout', 'ledger');
