<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$entity = (string)($GLOBALS['audio_dicts_entity'] ?? '');
$map = [
    'dicts' => ['repo' => RecordsDictsRepository::class, 'path' => '/audio/dicts', 'title' => 'audio_dicts.menu.dicts', 'view' => 'dicts_edit'],
    'keys' => ['repo' => RecordsKeysRepository::class, 'path' => '/audio/keys', 'title' => 'audio_dicts.menu.keys', 'view' => 'keys_edit'],
    'tags' => ['repo' => RecordsTagsRepository::class, 'path' => '/audio/tags', 'title' => 'audio_dicts.menu.tags', 'view' => 'tags_edit'],
    'trashes' => ['repo' => RecordsTrashesRepository::class, 'path' => '/audio/trashes', 'title' => 'audio_dicts.menu.trashes', 'view' => 'trashes_edit'],
];
if (!isset($map[$entity])) {
    http_response_code(404);
    echo 'Unknown entity';
    return;
}
$cfg = $map[$entity];
/** @var class-string $repo */
$repo = $cfg['repo'];
$path = $cfg['path'];
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = null;
if (!$isNew) {
    $row = $repo::find($id);
    if (!$row) {
        if ($wantsJson) {
            AgentHttp::error(t('audio_dicts.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('audio_dicts.err.not_found', ['id' => $id]),
            'message' => t('audio_dicts.err.not_found', ['id' => $id]),
        ], 'error', 'core');
        return;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = $repo::dataFromPost($_POST);
        if ($isNew) {
            $newId = $repo::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'entity' => $entity, 'id' => $newId, 'item' => $repo::find($newId)]);
                return;
            }
            redirect($path . '/' . $newId . '/edit');
        }
        $repo::update($id, $data);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'entity' => $entity, 'id' => $id, 'item' => $repo::find($id)]);
            return;
        }
        redirect($path . '/' . $id . '/edit');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row ?? [], [
            'name' => (string)($_POST['name'] ?? ''),
        ]);
    }
}

$viewData = [
    'title' => $isNew ? t('audio_dicts.edit.title_new') : t('audio_dicts.edit.title', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t($cfg['title']), 'href' => $path],
        ['label' => $isNew ? t('audio_dicts.edit.title_new') : (string)($row['name'] ?? '#' . $id), 'current' => true],
    ]),
    'row' => $row,
    'isNew' => $isNew,
    'error' => $error,
    'path' => $path,
    'id' => $id,
];
if ($entity === 'keys') {
    $viewData['dictOptions'] = RecordsDictsRepository::options(true);
}

if ($wantsJson) {
    $payload = [
        'ok' => true,
        'entity' => $entity,
        'is_new' => $isNew,
        'item' => $row,
    ];
    if ($entity === 'keys') {
        $payload['dict_options'] = RecordsDictsRepository::options(true);
    }
    AgentHttp::emit($payload);
    return;
}

View::render($cfg['view'], $viewData, 'layout', 'audio_dicts');
