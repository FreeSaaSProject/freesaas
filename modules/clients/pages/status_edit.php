<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = ClientStatusesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('clients.status.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = ClientStatusesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = ClientStatusesRepository::create($data);
            redirect('/client-statuses/' . $newId . '/edit?saved=1');
            return;
        }
        ClientStatusesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = ClientStatusesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('status_edit', [
    'title' => $isNew
        ? t('clients.status.title_new')
        : t('clients.status.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'clients');
