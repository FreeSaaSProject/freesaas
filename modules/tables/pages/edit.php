<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['enabled' => 1, 'proc_type_ids' => [], 'gender_ids' => []];
if (!$isNew) {
    $found = TablesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('tables.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = TablesRepository::dataFromPost($_POST);
        if ($isNew) {
            redirect('/tables/' . TablesRepository::create($data, Auth::userId()) . '/edit?saved=1');
            return;
        }
        TablesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = TablesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'name' => (string)($_POST['name'] ?? ''),
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'proc_type_ids' => array_map('intval', (array)($_POST['proc_types'] ?? [])),
            'gender_ids' => array_map('intval', (array)($_POST['gender'] ?? [])),
        ]);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}
$procTypes = class_exists('ProcedureTypesRepository', false)
    ? ProcedureTypesRepository::names()
    : [];
View::render('edit', [
    'title' => $isNew ? t('tables.edit.title_new') : t('tables.edit.title', ['name' => (string)($row['name'] ?? '')]),
    'isNew' => $isNew,
    'row' => $row,
    'procTypes' => $procTypes,
    'message' => $message,
    'error' => $error,
], 'layout', 'tables');
