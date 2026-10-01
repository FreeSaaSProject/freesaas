<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$returnUrl = ProceduresRepository::sanitizeReturnUrl(
    (string)($_POST['ret'] ?? $_GET['ret'] ?? '')
);

$row = [];
if (!$isNew) {
    $found = ProceduresRepository::find($id);
    if (!$found) {
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
    ];
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = ProceduresRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = ProceduresRepository::create($data);
            redirect(ListQuery::url('/procedures/' . $newId . '/edit', [
                'saved' => 1,
                'ret' => $returnUrl,
            ]));
            return;
        }
        ProceduresRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = ProceduresRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staffNames = [];
foreach (UsersRepository::listForCopy() as $u) {
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

View::render('edit', [
    'title' => $isNew
        ? t('procedures.edit.title_new')
        : t('procedures.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
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
