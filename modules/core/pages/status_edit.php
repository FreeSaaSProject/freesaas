<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/StatusesRepository.php');

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = StatusesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('core.status_edit.err.not_found', ['id' => $id]), 404);
        }
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
$shiftOptions = class_exists('WorkweeksRepository', false)
    ? WorkweeksRepository::shiftOptionsForStatus()
    : [0 => '—'];


if (!$isNew && EntityDelete::tryHandle('core_statuses', $id, '/statuses', [
    'stay_path' => '/statuses/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = StatusesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = StatusesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'saved' => true,
                    'item' => StatusesRepository::find($newId),
                ]);
            }
            redirect('/statuses/' . $newId . '/edit?saved=1');
            return;
        }
        StatusesRepository::update($id, $data);
        $row = StatusesRepository::find($id) ?: $row;
        if (Auth::statusId() === $id) {
            Auth::setStatusContext($id, Auth::statusId2());
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'saved' => true,
                'item' => $row,
            ]);
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
        }
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row ?: null,
        'groups' => $groups,
        'audio_options' => $audioOptions,
        'shift_options' => $shiftOptions,
    ]);
}

View::render('status_edit', [
    'title' => $isNew
        ? t('core.status_edit.title_new')
        : t('core.status_edit.title', ['name' => (string)($row['NAME'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'groups' => $groups,
    'audioOptions' => $audioOptions,
    'shiftOptions' => $shiftOptions,
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
