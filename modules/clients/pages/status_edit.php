<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = ClientStatusesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('clients.status.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('clients.status.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (!$isNew && EntityDelete::tryHandle('client_statuses', $id, '/client-statuses', [
    'stay_path' => '/client-statuses/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = ClientStatusesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = ClientStatusesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => ClientStatusesRepository::find($newId),
                ]);
                return;
            }
            redirect('/client-statuses/' . $newId . '/edit?saved=1');
            return;
        }
        ClientStatusesRepository::update($id, $data);
        $row = ClientStatusesRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
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

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'item' => $row, 'is_new' => $isNew]);
    return;
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
