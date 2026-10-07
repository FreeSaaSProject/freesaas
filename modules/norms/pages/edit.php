<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['id_proc' => (int)($_GET['id_proc'] ?? 0), 'qtyu2' => 1];
if (!$isNew) {
    $found = NormsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('norms.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('norms.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = NormsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = NormsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'saved' => true]);
                return;
            }
            redirect('/norms/' . $newId . '/edit?saved=1');
            return;
        }
        NormsRepository::update($id, $data);
        $row = NormsRepository::find($id) ?: $row;
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
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(true) : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'is_new' => $isNew,
        'products' => $products,
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew ? t('norms.edit.title_new') : t('norms.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'products' => $products,
    'message' => $message,
    'error' => $error,
], 'layout', 'norms');
