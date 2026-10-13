<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = CertificatesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('certificates.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('certificates.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $now = time();
    $row = [
        'code' => CertificatesRepository::generateCode(),
        'kind' => CertificatesRepository::KIND_MONEY,
        'id_operator' => (int)(Auth::userId() ?? 0),
        'id_buyer' => (int)($_GET['buyer'] ?? $_GET['idClient'] ?? 0),
        'id_holder' => 0,
        'id_event_create' => (int)($_GET['event'] ?? 0),
        'sale_amount' => (float)($_GET['sale'] ?? $_GET['price'] ?? 0),
        'face_value' => (float)($_GET['sale'] ?? $_GET['price'] ?? 0),
        'used_amount' => 0.0,
        'balance' => 0.0,
        'dt_create' => $now,
        'dt_activation_expire' => $now + 86400 * 180,
        'dt_use_expire' => $now + 86400 * 360,
        'expired' => 0,
        'partial_forbidden' => 0,
        'bonus_forbidden' => 0,
        'use_limit_pct' => 0,
        'type_ids' => [],
        'product_ids' => [],
        'content' => [],
        'content_used' => [],
        'descr' => '',
    ];
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = CertificatesRepository::dataFromPost($_POST, $isNew);
        if ($isNew) {
            if ((int)$data['id_operator'] < 1) {
                $data['id_operator'] = (int)(Auth::userId() ?? 0);
            }
            $newId = CertificatesRepository::create($data);
            $issue = CertificateProcessor::issue($newId, (int)$data['id_operator']);
            if (empty($issue['ok']) && (float)$data['face_value'] > 0) {
                throw new RuntimeException((string)($issue['error'] ?? t('certificates.err.apply_failed')));
            }
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'create',
                    'id' => $newId,
                    'row' => CertificatesRepository::find($newId),
                    'ledger_id' => (int)($issue['ledger_id'] ?? 0),
                ]);
                return;
            }
            redirect('/certificates/' . $newId . '/edit?saved=1');
            return;
        }
        CertificatesRepository::update($id, $data);
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'action' => 'update',
                'id' => $id,
                'row' => CertificatesRepository::find($id),
            ]);
            return;
        }
        $message = t('core.common.saved');
        $row = CertificatesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, [
            'code' => (string)($_POST['code'] ?? $row['code'] ?? ''),
            'kind' => (int)($_POST['kind'] ?? $row['kind'] ?? 1),
            'id_operator' => (int)($_POST['id_operator'] ?? 0),
            'id_buyer' => (int)($_POST['id_buyer'] ?? 0),
            'id_holder' => (int)($_POST['id_holder'] ?? 0),
            'sale_amount' => (float)($_POST['sale_amount'] ?? 0),
            'face_value' => (float)($_POST['face_value'] ?? 0),
            'descr' => (string)($_POST['descr'] ?? ''),
        ]);
    }
}

if ($wantsJson && $isNew) {
    AgentHttp::emit([
        'ok' => true,
        'action' => 'blank',
        'row' => $row,
    ]);
    return;
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('edit', [
    'title' => $isNew
        ? t('certificates.edit.title_new')
        : t('certificates.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('certificates.menu.list'), 'href' => '/certificates'],
        [
            'label' => $isNew ? t('certificates.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'certificates');
