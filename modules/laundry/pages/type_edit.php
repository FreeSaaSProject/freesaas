<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = null;
if (!$isNew) {
    $row = LaundryItemTypesRepository::find($id);
    if (!$row) {
        if ($wantsJson) {
            AgentHttp::error(t('laundry.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('laundry.err.not_found', ['id' => $id])]);
        return;
    }
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = LaundryItemTypesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = LaundryItemTypesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId]);
                return;
            }
            redirect('/laundry/types/' . $newId . '/edit?saved=1');
            return;
        }
        LaundryItemTypesRepository::update($id, $data);
        $row = LaundryItemTypesRepository::find($id);
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row ?? [], $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('type_edit', [
    'title' => $isNew ? t('laundry.type.edit_new') : t('laundry.type.edit', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.menu.settings'), 'href' => '/laundry/settings'],
        ['label' => t('laundry.menu.types'), 'href' => '/laundry/types'],
        ['label' => $isNew ? t('laundry.type.edit_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row ?? ['code' => '', 'name' => '', 'sort' => 100, 'enabled' => 1],
    'message' => $message,
    'error' => $error,
], 'layout', 'laundry');
