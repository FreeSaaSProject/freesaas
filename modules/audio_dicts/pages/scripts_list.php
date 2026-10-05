<?php
declare(strict_types=1);

$path = '/audio/scripts';
$listId = 'audio_scripts';

$q = trim((string)($_GET['q'] ?? ''));
$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => RecordsScriptsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

$result = RecordsScriptsRepository::listRoots([
    'q' => $q,
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
    'q' => $q,
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
], 'layout', 'audio_dicts');
