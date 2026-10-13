<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!LikespaConfig::restoresEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('likespa.restores.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('likespa.restores.err.disabled'),
    ]);
    return;
}

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$isSuper = Auth::capability('SUPERADMIN_ACCESS');

$row = [
    'id' => 0,
    'dt_create' => time(),
    'dt_review' => 0,
    'id_procedure' => 0,
    'id_admin_create' => Auth::userId(),
    'id_operator_paid' => 0,
    'id_operator_reviewed' => 0,
    'reason' => '',
    'summ' => '',
];

if (!$isNew) {
    $found = ProcedureRestoresRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('likespa.restores.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('likespa.restores.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$keepProc = $isNew ? 0 : (int)$row['id_procedure'];
$candidates = ProcedureRestoresRepository::candidatesForRestore($keepProc);
$lookbackDays = LikespaConfig::restoresLookbackDays();

$message = '';
$error = '';
$paymentId = null;
// Non-super may create; editing reason/sum on existing rows is director-only.
$canEditFields = $isNew || $isSuper;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canEditFields) {
            throw new RuntimeException(t('likespa.restores.err.super_only_edit'));
        }
        if ($isNew && $candidates === []) {
            throw new InvalidArgumentException(t('likespa.restores.err.no_candidates'));
        }
        $data = ProcedureRestoresRepository::dataFromPost($_POST, $isNew ? null : $row);
        if ($isNew) {
            $res = ProcedureRestoresRepository::createWithSettle($data);
            $paymentId = $res['payment_id'];
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'create',
                    'id' => $res['id'],
                    'payment_id' => $paymentId,
                    'row' => ProcedureRestoresRepository::find($res['id']),
                ]);
                return;
            }
            $qs = 'saved=1';
            if ($paymentId) {
                $qs .= '&payment_id=' . (int)$paymentId;
            }
            redirect('/likespa/restores/' . $res['id'] . '/edit?' . $qs);
            return;
        }
        $res = ProcedureRestoresRepository::updateWithSettle($id, $data);
        $paymentId = $res['payment_id'];
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'action' => 'update',
                'id' => $id,
                'payment_id' => $paymentId,
                'row' => ProcedureRestoresRepository::find($id),
            ]);
            return;
        }
        $message = t('core.common.saved');
        if ($paymentId) {
            $message .= ' ' . t('likespa.restores.flash.payment_created', ['id' => $paymentId]);
        }
        $row = ProcedureRestoresRepository::find($id) ?: $row;
        $candidates = ProcedureRestoresRepository::candidatesForRestore((int)$row['id_procedure']);
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, [
            'reason' => (string)($_POST['reason'] ?? $row['reason'] ?? ''),
            'summ' => (string)($_POST['summ'] ?? $row['summ'] ?? ''),
            'id_procedure' => (int)($_POST['id_procedure'] ?? $row['id_procedure'] ?? 0),
        ]);
    }
}

if ($wantsJson) {
    if ($isNew) {
        AgentHttp::emit([
            'ok' => true,
            'candidates' => $candidates,
            'lookback_days' => $lookbackDays,
            'is_superadmin' => $isSuper,
        ]);
        return;
    }
    AgentHttp::emit([
        'ok' => true,
        'row' => $row,
        'candidates' => $candidates,
        'lookback_days' => $lookbackDays,
        'is_superadmin' => $isSuper,
    ]);
    return;
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
    $pid = (int)($_GET['payment_id'] ?? 0);
    if ($pid > 0) {
        $message .= ' ' . t('likespa.restores.flash.payment_created', ['id' => $pid]);
    }
}

$canSubmit = $canEditFields && ($isNew ? $candidates !== [] : true);

View::render('restore_edit', [
    'title' => $isNew
        ? t('likespa.restores.edit.title_new')
        : t('likespa.restores.edit.title', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.restores'), 'href' => '/likespa/restores'],
        [
            'label' => $isNew ? t('likespa.restores.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'candidates' => $candidates,
    'lookbackDays' => $lookbackDays,
    'isSuper' => $isSuper,
    'canEditFields' => $canEditFields,
    'canSubmit' => $canSubmit,
    'message' => $message,
    'error' => $error,
], 'layout', 'likespa');
