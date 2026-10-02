<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['name' => '', 'descr' => '', 'color' => ''];

if (!$isNew) {
    $found = PaymentTypesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('payments.types.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = PaymentTypesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = PaymentTypesRepository::create($data);
            redirect('/payment-types/' . $newId . '/edit?saved=1');
            return;
        }
        PaymentTypesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = PaymentTypesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('types_edit', [
    'title' => $isNew
        ? t('payments.types.edit_new')
        : t('payments.types.edit', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('payments.menu.types'), 'href' => '/payment-types'],
        [
            'label' => $isNew ? t('payments.types.edit_new') : (string)($row['name'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'payments');
