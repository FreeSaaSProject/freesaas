<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$canSeeOthers = PaymentsRepository::canSeeOthers();
$commentId = (int)($_GET['id_comment'] ?? 0);

$row = [
    'user_id' => $canSeeOthers ? Auth::userId() : Auth::userId(),
    'dt' => time(),
    'descr' => '',
    'direction' => -1,
    'sum' => 0,
    'type_id' => 1,
    'account' => 0,
    'comment_id' => 0,
    'is_partner' => 0,
    'admin_inworked' => 0,
    'admin_signed' => 0,
    'accept_user' => 0,
    'dt_create' => time(),
    'user_signed' => Auth::userId(),
    'movement_linked' => 0,
];

if ($isNew && $commentId > 0) {
    $prefill = PaymentsRepository::prefillFromComment($commentId);
    if ($prefill) {
        $row = array_merge($row, $prefill);
    }
}

if (!$isNew) {
    $found = PaymentsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('payments.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    if (!$canSeeOthers && (int)$found['user_id'] !== Auth::userId()) {
        http_response_code(403);
        View::render('error', [
            'title' => t('payments.err.forbidden'),
            'message' => t('payments.err.forbidden'),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
$warn = '';

if (!PaymentsRepository::ledgerEnabled()) {
    $warn = t('payments.flash.dps_stub');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canSeeOthers) {
            $_POST['user_id'] = (string)Auth::userId();
        }
        $data = PaymentsRepository::dataFromPost($_POST, $isNew ? null : $row);
        if ($isNew) {
            $newId = PaymentsRepository::create($data);
            redirect('/payments/' . $newId . '/edit?saved=1');
            return;
        }
        PaymentsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = PaymentsRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'user_id' => (int)($_POST['user_id'] ?? $row['user_id']),
            'dt' => (string)($_POST['dt'] ?? $row['dt']),
            'descr' => (string)($_POST['descr'] ?? $row['descr']),
            'direction' => (int)($_POST['direction'] ?? $row['direction']),
            'sum' => $_POST['sum'] ?? $row['sum'],
            'type_id' => (int)($_POST['type_id'] ?? $row['type_id']),
            'account' => (int)($_POST['account'] ?? $row['account']),
            'comment_id' => (int)($_POST['comment_id'] ?? $row['comment_id']),
            'is_partner' => !empty($_POST['is_partner']) ? 1 : 0,
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staff = [];
foreach (UsersRepository::listForCopy() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}
$typeNames = PaymentTypesRepository::names();
$dtValue = is_numeric($row['dt'] ?? null)
    ? date('Y-m-d\TH:i', (int)$row['dt'])
    : (string)($row['dt'] ?? '');

$linkedMoves = !$isNew && PaymentsRepository::hasLinkedMoves($id);
$showCashMarks = (int)($row['direction'] ?? 0) < 0 && (int)($row['account'] ?? 0) === 2;

View::render('edit', [
    'title' => $isNew
        ? t('payments.edit.title_new')
        : t('payments.edit.title', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('payments.menu.list'), 'href' => '/payments'],
        [
            'label' => $isNew ? t('payments.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'staff' => $staff,
    'typeNames' => $typeNames,
    'canSeeOthers' => $canSeeOthers,
    'ledgerEnabled' => PaymentsRepository::ledgerEnabled(),
    'dtValue' => $dtValue,
    'linkedMoves' => $linkedMoves,
    'showCashMarks' => $showCashMarks,
    'message' => $message,
    'error' => $error,
    'warn' => $warn,
], 'layout', 'payments');
