<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['enabled' => 1, 'proc_type_ids' => [], 'gender_ids' => []];
if (!$isNew) {
    $found = TablesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('tables.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('tables.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';

if (!$isNew && EntityDelete::tryHandle('tables', $id, '/tables', [
    'stay_path' => '/tables/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = TablesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = TablesRepository::create($data, Auth::userId());
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'saved' => true]);
                return;
            }
            redirect('/tables/' . $newId . '/edit?saved=1');
            return;
        }
        TablesRepository::update($id, $data);
        $row = TablesRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'saved' => true]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
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

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'is_new' => $isNew,
        'procedure_types' => $procTypes,
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew ? t('tables.edit.title_new') : t('tables.edit.title', ['name' => (string)($row['name'] ?? '')]),
    'isNew' => $isNew,
    'row' => $row,
    'procTypes' => $procTypes,
    'message' => $message,
    'error' => $error,
], 'layout', 'tables');
