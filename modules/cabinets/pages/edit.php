<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = CabinetsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('cabinets.err.not_found', ['id' => $id]), 404);
            return;
        }
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
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'saved' => true]);
                return;
            }
            redirect('/cabinets/' . $newId . '/edit?saved=1');
            return;
        }
        CabinetsRepository::update($id, $data);
        $row = CabinetsRepository::find($id) ?: $row;
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

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'item' => $row, 'is_new' => $isNew]);
    return;
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
