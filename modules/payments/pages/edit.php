<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$canSeeOthers = PaymentsRepository::canSeeOthers();
$commentId = (int)($_GET['id_comment'] ?? 0);

$prefillUserId = 0;
if ($isNew && $canSeeOthers) {
    $prefillUserId = (int)($_GET['user_id'] ?? 0);
    if ($prefillUserId < 1) {
        $ret = (string)($_GET['ret'] ?? '');
        if ($ret !== '') {
            $retQuery = parse_url($ret, PHP_URL_QUERY);
            if (is_string($retQuery) && $retQuery !== '') {
                parse_str($retQuery, $retParams);
                $prefillUserId = (int)($retParams['user_id'] ?? 0);
            }
        }
    }
}

$row = [
    // New: empty staff/direction unless prefilled from list filter or comment — never default to self.
    'user_id' => $canSeeOthers ? $prefillUserId : Auth::userId(),
    'dt' => time(),
    'descr' => '',
    'direction' => '',
    'sum' => '',
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


if (!$isNew && EntityDelete::tryHandle('payments', $id, '/payments', [
    'stay_path' => '/payments/' . $id . '/edit',
])) {
    return;
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
            $postedDir = array_key_exists('direction', $_POST) ? (int)$_POST['direction'] : ($row['direction'] ?? '');
            $row = array_merge($row, [
                'user_id' => (int)($_POST['user_id'] ?? $row['user_id']),
                'dt' => (string)($_POST['dt'] ?? $row['dt']),
                'descr' => (string)($_POST['descr'] ?? $row['descr']),
                'direction' => $postedDir,
                'sum' => $_POST['sum'] ?? $row['sum'],
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
    'canSeeOthers' => $canSeeOthers,
    'ledgerEnabled' => $ledgerEnabled,
    'canViewLedger' => $canViewLedger,
    'dtValue' => $dtValue,
    'linkedMoves' => $linkedMoves,
    'linkedMoveIds' => $linkedMoveIds,
    'showCashMarks' => $showCashMarks,
    'showCommentId' => $isNew && $commentId > 0,
    'message' => $message,
    'error' => $error,
    'warn' => $warn,
], 'layout', 'payments');
