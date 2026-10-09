<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

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
        if ($wantsJson) {
            AgentHttp::error(t('payments.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('payments.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    if (!$canSeeOthers && (int)$found['user_id'] !== Auth::userId()) {
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
        if (!$isNew && !empty($_POST['create_dps']) && PaymentsRepository::ledgerEnabled()) {
            $res = PaymentsRepository::createDpsByIds([$id]);
            $row = PaymentsRepository::find($id) ?: $row;
            if ($wantsJson) {
                if ($res['done'] > 0) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'create_dps',
                        'id' => $id,
                        'done' => $res['done'],
                        'row' => $row,
                    ]);
                } else {
                    $reason = $res['skipped'][0]['reason'] ?? t('payments.flash.dps_none');
                    AgentHttp::error($reason, 400, ['result' => $res]);
                }
                return;
            }
            if ($res['done'] > 0) {
                $message = t('payments.flash.dps_created', ['n' => $res['done']]);
            } else {
                $reason = $res['skipped'][0]['reason'] ?? t('payments.flash.dps_none');
                $error = $reason;
            }
        } else {
            if (!$canSeeOthers) {
                $_POST['user_id'] = (string)Auth::userId();
            }
            $data = PaymentsRepository::dataFromPost($_POST, $isNew ? null : $row);
            if ($isNew) {
                $newId = PaymentsRepository::create($data);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'id' => $newId,
                        'row' => PaymentsRepository::find($newId),
                    ]);
                    return;
                }
                redirect('/payments/' . $newId . '/edit?saved=1');
                return;
            }
            PaymentsRepository::update($id, $data);
            $row = PaymentsRepository::find($id) ?: $row;
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'row' => $row,
                ]);
                return;
            }
            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        if (empty($_POST['create_dps'])) {
            $row = array_merge($row, [
                'user_id' => (int)($_POST['user_id'] ?? $row['user_id']),
                'dt' => (string)($_POST['dt'] ?? $row['dt']),
                'descr' => (string)($_POST['descr'] ?? $row['descr']),
                'direction' => (int)($_POST['direction'] ?? $row['direction']),
                'sum' => $_POST['sum'] ?? $row['sum'],
                'type_id' => (int)($_POST['type_id'] ?? $row['type_id']),
                'account' => (int)($_POST['account'] ?? $row['account']),
                'comment_id' => (int)($_POST['comment_id'] ?? $row['comment_id']),
            ]);
        }
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staff = UsersRepository::staffNamesIncluding([(int)($row['user_id'] ?? 0)]);
$typeNames = PaymentTypesRepository::names();
$dtValue = is_numeric($row['dt'] ?? null)
    ? date('Y-m-d\TH:i', (int)$row['dt'])
    : (string)($row['dt'] ?? '');

$ledgerEnabled = PaymentsRepository::ledgerEnabled();
$linkedMoveIds = [];
if (!$isNew && $ledgerEnabled && class_exists('LedgerMovesRepository')) {
    $linkedMoveIds = class_exists('PaymentMoveLinksRepository', false)
        ? (PaymentMoveLinksRepository::moveIdsByPaymentIds([$id])[$id] ?? [])
        : LedgerMovesRepository::moveIdsForPayment($id);
}
$linkedMoves = $linkedMoveIds !== [];
$showCashMarks = (int)($row['direction'] ?? 0) < 0 && (int)($row['account'] ?? 0) === 2;
$canViewLedger = $ledgerEnabled && Auth::can('ledger.view');

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'id' => $isNew ? null : $id,
        'row' => $row,
        'staff' => $staff,
        'type_names' => $typeNames,
        'can_see_others' => $canSeeOthers,
        'ledger_enabled' => $ledgerEnabled,
        'linked_move_ids' => $linkedMoveIds,
    ]);
    return;
}

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
    'ledgerEnabled' => $ledgerEnabled,
    'canViewLedger' => $canViewLedger,
    'dtValue' => $dtValue,
    'linkedMoves' => $linkedMoves,
    'linkedMoveIds' => $linkedMoveIds,
    'showCashMarks' => $showCashMarks,
    'message' => $message,
    'error' => $error,
    'warn' => $warn,
], 'layout', 'payments');
