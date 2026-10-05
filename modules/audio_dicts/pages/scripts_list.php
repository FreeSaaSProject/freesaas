<?php
declare(strict_types=1);

$path = '/audio/scripts';
$listId = 'audio_scripts';

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => RecordsScriptsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = $lq['query'];
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
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$bulkFlash = BulkActions::consumeFlash();
$error = trim((string)($_GET['err'] ?? ''));

View::render('scripts_list', [
    'title' => t('audio_dicts.menu.scripts'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_dicts.menu.scripts'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
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
    'roleLabels' => [
        'ordinary' => t('audio_dicts.scripts.role.ordinary'),
        'trigger' => t('audio_dicts.scripts.role.trigger'),
        'conversion' => t('audio_dicts.scripts.role.conversion'),
        'breaker' => t('audio_dicts.scripts.role.breaker'),
        'killer' => t('audio_dicts.scripts.role.killer'),
    ],
], 'layout', 'audio_dicts');
