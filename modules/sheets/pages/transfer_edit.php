<?php
declare(strict_types=1);

$row = ['id_account' => 0, 'id_account_to' => 0, 'summ' => 0, 'descr' => '', 'weight' => 0, 'dt' => time()];
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $from = (int)($_POST['id_account'] ?? 0);
        $to = (int)($_POST['id_account_to'] ?? 0);
        $data = [
            'id_account' => $from,
            'id_account_to' => $to,
            'summ' => (int)($_POST['summ'] ?? 0),
            'descr' => trim((string)($_POST['descr'] ?? '')),
            'weight' => (float)str_replace(',', '.', (string)($_POST['weight'] ?? '0')),
            'dt' => max(0, (int)($_POST['dt'] ?? time())) ?: time(),
        ];
        $id = SheetMovesRepository::createTransfer($data, Auth::userId());
        redirect('/sheets/moves/' . $id . '/edit?saved=1');
        return;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
View::render('transfer_edit', [
    'title' => t('sheets.transfer.title'),
    'row' => $row,
    'accounts' => SheetAccountsRepository::names(true),
    'message' => $message,
    'error' => $error,
], 'layout', 'sheets');
