<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/StatusesRepository.php');

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = StatusesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.status_edit.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
$groups = StatusesRepository::visibleFieldGroups();
$audioOptions = StatusesRepository::optionsForAudio($id);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = StatusesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = StatusesRepository::create($data);
            redirect('/statuses/' . $newId . '/edit?saved=1');
            return;
        }
        StatusesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = StatusesRepository::find($id) ?: $row;
        if (Auth::statusId() === $id) {
            Auth::setStatusContext($id, Auth::statusId2());
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('status_edit', [
    'title' => $isNew
        ? t('core.status_edit.title_new')
        : t('core.status_edit.title', ['name' => (string)($row['NAME'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'groups' => $groups,
    'audioOptions' => $audioOptions,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.statuses'), 'href' => '/statuses'],
        [
            'label' => $isNew
                ? t('core.status_edit.title_new')
                : (string)($row['NAME'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
]);
