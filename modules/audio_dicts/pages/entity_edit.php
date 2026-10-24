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

$listIdMap = [
    'dicts' => 'audio_dicts',
    'keys' => 'audio_keys',
    'tags' => 'audio_tags',
    'trashes' => 'audio_trashes',
];
$listId = $listIdMap[$entity] ?? 'audio_dicts';

$error = '';
$keyOverlaps = [];
$keyForceChecked = false;

if (!$isNew && EntityDelete::tryHandle($listId, $id, $path, [
    'stay_path' => $path . '/' . $id . '/edit',
])) {
    return;
}

// Prefill new key from coach / deep links: ?name=&dict=&looklike=
if ($entity === 'keys' && $isNew && $row === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $preName = trim((string)($_GET['name'] ?? ''));
    $preDict = max(0, (int)($_GET['dict'] ?? $_GET['id_dictonary'] ?? 0));
    $preLook = max(0, (int)($_GET['looklike'] ?? $_GET['id_looklike'] ?? 0));
    if ($preName !== '' || $preDict > 0 || $preLook > 0) {
        $row = [
            'name' => mb_substr($preName, 0, 255),
            'id_dictonary' => $preDict,
            'id_looklike' => $preLook,
            'disabled' => 0,
        ];
        if ($preName !== '') {
            $keyOverlaps = RecordsKeysRepository::findOverlaps($preName, 0, 12);
            if ($preLook < 1 && $keyOverlaps !== []) {
                $row['id_looklike'] = (int)($keyOverlaps[0]['id'] ?? 0);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = $repo::dataFromPost($_POST);
        if ($entity === 'keys') {
            $keyForceChecked = !empty($_POST['force']);
            $keyOverlaps = RecordsKeysRepository::findOverlaps(
                (string)$data['name'],
                $isNew ? 0 : $id,
                12
            );
            $hasExact = false;
            foreach ($keyOverlaps as $ov) {
                if ((string)($ov['relation'] ?? '') === 'exact') {
                    $hasExact = true;
                    break;
                }
            }
            if ($hasExact) {
                throw new InvalidArgumentException(t('audio_dicts.keys.err_exact'));
            }
            // Soft "contains" (existing key is wider) — allow without force, same as coach apply.
            $softOnly = RecordsKeysRepository::isSoftContainsOnly($keyOverlaps);
            if ($keyOverlaps !== [] && !$softOnly && !$keyForceChecked) {
                if ((int)($data['id_looklike'] ?? 0) < 1) {
                    $data['id_looklike'] = (int)($keyOverlaps[0]['id'] ?? 0);
                }
                throw new InvalidArgumentException(t('audio_dicts.keys.err_overlap'));
            }
            if ($keyOverlaps !== [] && ($keyForceChecked || $softOnly) && (int)($data['id_looklike'] ?? 0) < 1) {
                $data['id_looklike'] = (int)($keyOverlaps[0]['id'] ?? 0);
            }
        }
        if ($isNew) {
            $newId = $repo::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'entity' => $entity, 'id' => $newId, 'item' => $repo::find($newId)]);
                return;
            }
            if ($entity === 'keys') {
                flash_set('ok', t('audio_dicts.keys.saved'));
            }
            // Stay on the new key edit page — do not carry coach ret into the redirect.
            if (class_exists('NavContext', false)) {
                NavContext::setReturnUrl('');
            }
            redirect($path . '/' . $newId . '/edit');
        }
        $repo::update($id, $data);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'entity' => $entity, 'id' => $id, 'item' => $repo::find($id)]);
            return;
        }
        if ($entity === 'keys') {
            flash_set('ok', t('audio_dicts.keys.saved'));
            if (class_exists('NavContext', false)) {
                NavContext::setReturnUrl('');
            }
        }
        redirect($path . '/' . $id . '/edit');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        if (isset($data) && is_array($data)) {
            $row = array_merge($row ?? [], $data);
        } else {
            $row = array_merge($row ?? [], [
                'name' => (string)($_POST['name'] ?? ''),
                'descr' => (string)($_POST['descr'] ?? ''),
                'color' => (string)($_POST['color'] ?? ''),
                'color_background' => (string)($_POST['color_background'] ?? ''),
                'tracking_code' => (string)($_POST['tracking_code'] ?? ''),
                'disabled' => !empty($_POST['disabled']) ? 1 : 0,
                'is_review_req' => !empty($_POST['is_review_req']) ? 1 : 0,
                'id_dictonary' => (int)($_POST['id_dictonary'] ?? 0),
                'id_looklike' => (int)($_POST['id_looklike'] ?? 0),
            ]);
        }
    }
}

$keyCount = 0;
if ($entity === 'dicts' && !$isNew && $id > 0 && Database::tableExists('base_records_keys')) {
    $st = Database::pdo()->prepare(
        'SELECT COUNT(*) FROM base_records_keys WHERE ID_DICTONARY=?'
    );
    $st->execute([$id]);
    $keyCount = (int)$st->fetchColumn();
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
    'flash' => $entity === 'keys' ? flash_take() : null,
    'path' => $path,
    'id' => $id,
    'listId' => $listId,
    'keyCount' => $keyCount,
];
if ($entity === 'keys') {
    $viewData['dictOptions'] = RecordsDictsRepository::options(true);
    $viewData['overlaps'] = $keyOverlaps;
    $viewData['forceChecked'] = $keyForceChecked;
    $viewData['forceNeeded'] = $keyOverlaps !== []
        && !RecordsKeysRepository::isSoftContainsOnly($keyOverlaps);
    if ($isNew) {
        $viewData['title'] = t('audio_dicts.keys.title_new');
        $viewData['breadcrumbs'] = Breadcrumbs::withHome([
            ['label' => t('audio.menu.group'), 'href' => '/audio'],
            ['label' => t($cfg['title']), 'href' => $path],
            ['label' => t('audio_dicts.keys.title_new'), 'current' => true],
        ]);
    }
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
