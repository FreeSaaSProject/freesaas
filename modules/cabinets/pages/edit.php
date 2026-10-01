<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = CabinetsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('cabinets.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = CabinetsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = CabinetsRepository::create($data);
            redirect('/cabinets/' . $newId . '/edit?saved=1');
            return;
        }
        CabinetsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = CabinetsRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('edit', [
    'title' => $isNew
        ? t('cabinets.edit.title_new')
        : t('cabinets.edit.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('cabinets.menu.list'), 'href' => '/cabinets'],
        [
            'label' => $isNew
                ? t('cabinets.edit.heading_new')
                : (string)($row['name'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'cabinets');
