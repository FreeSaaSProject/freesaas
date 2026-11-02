<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canSeeOthers = PaymentsRepository::canSeeOthers();

if (!$canSeeOthers) {
    if ($wantsJson) {
        AgentHttp::error(t('payments.err.forbidden'), 403);
        return;
    }
    http_response_code(403);
    View::render('error', [
        'title' => t('payments.err.forbidden'),
        'message' => t('payments.err.forbidden'),
    ]);
    return;
}

$fromId = (int)($_GET['user_id'] ?? $_GET['from_id'] ?? 0);
$toId = (int)($_GET['to_id'] ?? 0);
$sum = '';
$descr = '';
$dt = time();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $fromId = (int)($_POST['from_id'] ?? 0);
        $toId = (int)($_POST['to_id'] ?? 0);
        $sumRaw = (string)($_POST['sum'] ?? '');
        $sumVal = function_exists('money_parse') ? money_parse($sumRaw) : (float)str_replace(',', '.', $sumRaw);
        $descr = trim((string)($_POST['descr'] ?? ''));
        $dtRaw = trim((string)($_POST['dt'] ?? ''));
        $dt = $dtRaw !== '' ? (strtotime($dtRaw) ?: time()) : time();
        $pair = PaymentsRepository::createTransfer($fromId, $toId, $sumVal, $dt, $descr, Auth::userId());
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'action' => 'transfer',
                'debit_id' => $pair['debit_id'],
                'credit_id' => $pair['credit_id'],
                'debit' => PaymentsRepository::find($pair['debit_id']),
                'credit' => PaymentsRepository::find($pair['credit_id']),
            ]);
            return;
        }
        redirect('/payments?user_id=' . $fromId . '&transferred=1');
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $sum = (string)($_POST['sum'] ?? '');
        $descr = trim((string)($_POST['descr'] ?? ''));
        $fromId = (int)($_POST['from_id'] ?? $fromId);
        $toId = (int)($_POST['to_id'] ?? $toId);
        $dtRaw = trim((string)($_POST['dt'] ?? ''));
        if ($dtRaw !== '') {
            $parsed = strtotime($dtRaw);
            if ($parsed !== false) {
                $dt = $parsed;
            }
        }
    }
}

$staff = UsersRepository::staffNamesIncluding([$fromId, $toId]);
$dtValue = date('Y-m-d\TH:i', $dt > 0 ? $dt : time());

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'from_id' => $fromId,
        'to_id' => $toId,
        'staff' => $staff,
    ]);
    return;
}

View::render('transfer', [
    'title' => t('payments.transfer.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('payments.menu.list'), 'href' => '/payments'],
        ['label' => t('payments.transfer.title'), 'current' => true],
    ]),
    'fromId' => $fromId,
    'toId' => $toId,
    'sum' => $sum,
    'descr' => $descr,
    'dtValue' => $dtValue,
    'staff' => $staff,
    'error' => $error,
], 'layout', 'payments');
