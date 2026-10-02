<?php
declare(strict_types=1);

$row = [
    'type_id' => 0,
    'account_id' => (int)($_GET['account_id'] ?? 0),
    'account_to' => 0,
    'summ' => 0,
    'descr' => '',
    'dt' => time(),
];
$message = '';
$error = '';

$transferTypes = [];
foreach (AccountMoveTypesRepository::list(['limit' => 500])['items'] as $t) {
    if (!empty($t['linked_enabled']) && !empty($t['enabled'])) {
        $transferTypes[(int)$t['id']] = (string)$t['name'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $dt = strtotime((string)($_POST['dt'] ?? '')) ?: time();
        $pair = LedgerMovesRepository::createTransfer(
            (int)($_POST['type_id'] ?? 0),
            (int)($_POST['account_id'] ?? 0),
            (int)($_POST['account_to'] ?? 0),
            (float)str_replace(',', '.', (string)($_POST['summ'] ?? '0')),
            $dt,
            trim((string)($_POST['descr'] ?? ''))
        );
        redirect('/ledger/moves/' . $pair['from_id'] . '/edit?saved=1');
        return;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

$dtValue = is_numeric($row['dt'] ?? null) ? date('Y-m-d\TH:i', (int)$row['dt']) : (string)($row['dt'] ?? '');

View::render('transfers_edit', [
    'title' => t('ledger.moves.transfer_new'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('ledger.menu.moves'), 'href' => '/ledger/moves'],
        ['label' => t('ledger.moves.transfer_new'), 'current' => true],
    ]),
    'row' => $row,
    'accounts' => LedgerAccountsRepository::names(true),
    'transferTypes' => $transferTypes,
    'dtValue' => $dtValue,
    'message' => $message,
    'error' => $error,
], 'layout', 'ledger');
