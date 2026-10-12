<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$returnUrl = ProceduresRepository::sanitizeReturnUrl(
    (string)($_POST['ret'] ?? $_GET['ret'] ?? '')
);

$row = [];
if (!$isNew) {
    $found = ProceduresRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('procedures.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('procedures.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $row = [
        'enabled' => 1,
        'dtstart' => time(),
        'id_admin' => Auth::userId(),
        'id_operator' => Auth::userId(),
    ];
}

$acl = ProceduresEditAcl::forRow($row, $isNew);
if (empty($acl['allowed'])) {
    if ($wantsJson) {
        AgentHttp::error((string)($acl['deny_reason'] ?: t('procedures.edit.err.foreign')), 403);
        return;
    }
    http_response_code(403);
    $deny = (string)($acl['deny_reason'] ?: t('procedures.edit.err.foreign'));
    View::render('error', [
        'title' => $deny,
        'message' => $deny,
    ], 'layout');
    return;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        // Recompute ACL from posted id_good so timing lock follows product change.
        $probe = $row;
        if (ProceduresEditAcl::canEditField($acl, 'id_good') && isset($_POST['id_good'])) {
            $probe['id_good'] = (int)$_POST['id_good'];
        }
        $acl = ProceduresEditAcl::forRow($probe, $isNew);
        $data = ProceduresEditAcl::dataFromPost($_POST, $isNew ? [] : $row, $acl);
        if ($isNew) {
            $newId = ProceduresRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => ProceduresRepository::find($newId),
                ]);
                return;
            }
            redirect(ListQuery::url('/procedures/' . $newId . '/edit', [
                'saved' => 1,
                'ret' => $returnUrl,
            ]));
            return;
        }
        ProceduresRepository::update($id, $data);
        $row = ProceduresRepository::find($id) ?: $row;
        $acl = ProceduresEditAcl::forRow($row, false);
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => $row,
            ]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
        $acl = ProceduresEditAcl::forRow($row, $isNew);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staffNames = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffNames[(int)$u['id']] = $label;
}

$clientLabel = '';
$clientId = (int)($row['id_user'] ?? 0);
if ($clientId > 0) {
    $client = ClientsRepository::find($clientId);
    if ($client) {
        $clientLabel = trim((string)($client['name'] ?: $client['tel'] ?: ''));
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'is_new' => $isNew,
        'acl' => [
            'editable' => $acl['editable'],
            'lock_dtend_timing' => !empty($acl['lock_dtend_timing']),
        ],
        'procedure_types' => ProcedureTypesRepository::names(),
        'staff' => $staffNames,
        'client_label' => $clientLabel,
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew
        ? t('procedures.edit.title_new')
        : t('procedures.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'acl' => $acl,
    'message' => $message,
    'error' => $error,
    'returnUrl' => $returnUrl,
    'typeNames' => ProcedureTypesRepository::names(),
    'productNames' => ProductsRepository::names(true),
    'staffNames' => $staffNames,
    'clientLabel' => $clientLabel,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('procedures.menu.list'), 'href' => '/procedures'],
        [
            'label' => $isNew ? t('procedures.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'procedures');
