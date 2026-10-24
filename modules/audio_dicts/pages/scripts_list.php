<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$path = '/audio/scripts';
$listId = 'audio_scripts';

$q = trim((string)($_GET['q'] ?? ''));
$position = (int)($_GET['position'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => RecordsScriptsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q], $lq['query']);
if ($position > 0) {
    $filters['position'] = (string)$position;
}
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('audio_dicts.edit')) {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'toggle_disabled') {
        $id = (int)($_POST['id'] ?? 0);
        // checked switch = enabled → DISABLED=0
        $disabled = empty($_POST['enabled']);
        try {
            RecordsScriptsRepository::setDisabled($id, $disabled);
        } catch (Throwable $e) {
            // fall through
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        redirect($path . ($qs !== '' ? '?' . $qs : ''));
        return;
    }
}

BulkActions::tryHandle($listId, $path, $filters);

$result = RecordsScriptsRepository::listRoots([
    'q' => $q,
    'position' => $position,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$bulkFlash = BulkActions::consumeFlash();
$error = trim((string)($_GET['err'] ?? ''));
$positionOptions = RecordsScriptsRepository::positionOptions();
$positionFilterOptions = RecordsScriptsRepository::positionOptionsUsedInScripts();
// Keep selected filter visible even if somehow orphaned
if ($position > 0 && !isset($positionFilterOptions[$position])) {
    $positionFilterOptions[$position] = $positionOptions[$position] ?? ('#' . $position);
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'q' => $q,
        'position' => $position,
        'can_edit' => Auth::can('audio_dicts.edit'),
    ]);
    return;
}

View::render('scripts_list', [
    'title' => t('audio_dicts.menu.scripts'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_dicts.menu.scripts'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'position' => $position,
    'positionOptions' => $positionOptions,
    'positionFilterOptions' => $positionFilterOptions,
    'canEdit' => Auth::can('audio_dicts.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
    'path' => $path,
    'listId' => $listId,
    'error' => $error,
    'existingScriptNames' => RecordsScriptsRepository::listRootNames(),
    'roleLabels' => [
        'ordinary' => t('audio_dicts.scripts.role.ordinary'),
        'trigger' => t('audio_dicts.scripts.role.trigger'),
        'conversion' => t('audio_dicts.scripts.role.conversion'),
        'closer' => t('audio_dicts.scripts.role.closer'),
        'breaker' => t('audio_dicts.scripts.role.breaker'),
        'killer' => t('audio_dicts.scripts.role.killer'),
    ],
], 'layout', 'audio_dicts');
