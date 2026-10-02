<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [
    'operator_id' => (int)($_GET['operator_id'] ?? 0),
    'dt' => time(),
    'descr' => '',
    'content' => [],
    'content_sales' => [],
    'content_income' => [],
    'content_terminal' => [],
    'loss' => 0,
    'loss_fix' => 0,
    'offers' => 0,
];

if (!$isNew) {
    $found = RatesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('rates.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = RatesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = RatesRepository::create($data);
            redirect('/rates/' . $newId . '/edit?saved=1');
            return;
        }
        RatesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = RatesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'operator_id' => (int)($_POST['operator_id'] ?? $row['operator_id']),
            'dt' => (string)($_POST['dt'] ?? $row['dt']),
            'descr' => (string)($_POST['descr'] ?? $row['descr']),
            'loss' => $_POST['loss'] ?? $row['loss'],
            'loss_fix' => $_POST['loss_fix'] ?? $row['loss_fix'],
            'offers' => $_POST['offers'] ?? $row['offers'],
            'content' => is_array($_POST['content'] ?? null) ? $_POST['content'] : $row['content'],
            'content_sales' => is_array($_POST['content_sales'] ?? null) ? $_POST['content_sales'] : $row['content_sales'],
            'content_income' => is_array($_POST['content_income'] ?? null) ? $_POST['content_income'] : $row['content_income'],
            'content_terminal' => is_array($_POST['content_terminal'] ?? null) ? $_POST['content_terminal'] : $row['content_terminal'],
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
$typeNames = ProcedureTypesRepository::names();
$dtValue = is_numeric($row['dt'] ?? null)
    ? date('Y-m-d\TH:i', (int)$row['dt'])
    : (string)($row['dt'] ?? '');

View::render('edit', [
    'title' => $isNew
        ? t('rates.edit.title_new')
        : t('rates.edit.title', ['name' => (string)($row['descr'] ?? ('#' . $id))]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('rates.menu.list'), 'href' => '/rates'],
        [
            'label' => $isNew
                ? t('rates.edit.heading_new')
                : (string)($row['descr'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'staff' => $staff,
    'typeNames' => $typeNames,
    'dtValue' => $dtValue,
    'message' => $message,
    'error' => $error,
], 'layout', 'rates');
