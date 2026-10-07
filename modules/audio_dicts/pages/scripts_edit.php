<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$path = '/audio/scripts';
$id = (int)(Router::param('id') ?? 0);
$canEdit = Auth::can('audio_dicts.edit');
$tree = RecordsScriptsRepository::findWithParts($id);
if ($tree === null) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_dicts.scripts.err.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('audio_dicts.scripts.err.not_found', ['id' => $id]),
        'message' => t('audio_dicts.scripts.err.not_found', ['id' => $id]),
    ], 'error', 'core');
    return;
}

$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = trim((string)($_POST['action'] ?? 'save'));
    try {
        if ($action === 'copy') {
            $newId = RecordsScriptsRepository::copy($id);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => 'copy', 'id' => $newId, 'tree' => RecordsScriptsRepository::findWithParts($newId)]);
                return;
            }
            redirect($path . '/' . $newId);
        }
        $meta = RecordsScriptsRepository::metaFromPost($_POST);
        $parts = RecordsScriptsRepository::partsFromPost($_POST);
        if ($parts === []) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.parts_required'));
        }
        RecordsScriptsRepository::update($id, $meta, $parts);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'tree' => RecordsScriptsRepository::findWithParts($id)]);
            return;
        }
        redirect($path . '/' . $id . '?saved=1');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $tree = [
            'root' => array_merge($tree['root'], [
                'name' => trim((string)($_POST['name'] ?? $tree['root']['name'])),
                'disabled' => !empty($_POST['disabled']) ? 1 : 0,
                'position_ids' => [],
            ]),
            'parts' => RecordsScriptsRepository::partsFromPost($_POST),
        ];
        $posRaw = $_POST['positions'] ?? [];
        if (is_array($posRaw)) {
            $tree['root']['position_ids'] = array_values(array_filter(array_map('intval', $posRaw)));
        }
    }
}

if (!empty($_GET['saved'])) {
    $flash = t('audio_dicts.scripts.saved');
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'tree' => $tree,
        'can_edit' => $canEdit,
        'parts_url' => $path . '/' . $id . '/parts',
    ]);
    return;
}

View::render('scripts_canvas', [
    'title' => t('audio_dicts.scripts.edit_title', ['name' => (string)$tree['root']['name']]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_dicts.menu.scripts'), 'href' => $path],
        ['label' => (string)$tree['root']['name'], 'current' => true],
    ]),
    'path' => $path,
    'id' => $id,
    'root' => $tree['root'],
    'parts' => $tree['parts'],
    'canEdit' => $canEdit,
    'error' => $error,
    'flash' => $flash,
    'dictOptions' => RecordsDictsRepository::options(true),
    'positionOptions' => RecordsScriptsRepository::positionOptions(),
    'roleOptions' => [
        'ordinary' => t('audio_dicts.scripts.role.ordinary'),
        'trigger' => t('audio_dicts.scripts.role.trigger'),
        'conversion' => t('audio_dicts.scripts.role.conversion'),
        'breaker' => t('audio_dicts.scripts.role.breaker'),
        'killer' => t('audio_dicts.scripts.role.killer'),
    ],
    'roleHints' => [
        'ordinary' => t('audio_dicts.scripts.role.ordinary_hint'),
        'trigger' => t('audio_dicts.scripts.role.trigger_hint'),
        'conversion' => t('audio_dicts.scripts.role.conversion_hint'),
        'breaker' => t('audio_dicts.scripts.role.breaker_hint'),
        'killer' => t('audio_dicts.scripts.role.killer_hint'),
    ],
    'partsUrl' => $path . '/' . $id . '/parts',
], 'layout', 'audio_dicts');
