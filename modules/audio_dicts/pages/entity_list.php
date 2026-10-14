<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$entity = (string)($GLOBALS['audio_dicts_entity'] ?? '');
$map = [
    'dicts' => ['repo' => RecordsDictsRepository::class, 'path' => '/audio/dicts', 'title' => 'audio_dicts.menu.dicts', 'list_id' => 'audio_dicts', 'view' => 'dicts_list'],
    'keys' => ['repo' => RecordsKeysRepository::class, 'path' => '/audio/keys', 'title' => 'audio_dicts.menu.keys', 'list_id' => 'audio_keys', 'view' => 'keys_list'],
    'tags' => ['repo' => RecordsTagsRepository::class, 'path' => '/audio/tags', 'title' => 'audio_dicts.menu.tags', 'list_id' => 'audio_tags', 'view' => 'tags_list'],
    'trashes' => ['repo' => RecordsTrashesRepository::class, 'path' => '/audio/trashes', 'title' => 'audio_dicts.menu.trashes', 'list_id' => 'audio_trashes', 'view' => 'trashes_list'],
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
$listId = $cfg['list_id'];

$q = trim((string)($_GET['q'] ?? ''));
$dict = (int)($_GET['dict'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => $repo::listSortMap(),
    'default' => 'id',
    'default_dir' => $entity === 'keys' ? 'desc' : 'asc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q], $lq['query']);
if ($entity === 'keys' && $dict > 0) {
    $filters['dict'] = (string)$dict;
}
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

$listOpts = [
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
];
if ($entity === 'keys') {
    $listOpts['dict'] = $dict;
    // Qualify order columns for joined query
    $orderSql = (string)$lq['order_sql'];
    $orderSql = preg_replace('/\bID\b/', 'k.ID', $orderSql) ?? $orderSql;
    $orderSql = preg_replace('/\bNAME\b/', 'k.NAME', $orderSql) ?? $orderSql;
    $orderSql = preg_replace('/\bDISABLED\b/', 'k.DISABLED', $orderSql) ?? $orderSql;
    $orderSql = preg_replace('/\bID_DICTONARY\b/', 'k.ID_DICTONARY', $orderSql) ?? $orderSql;
    $listOpts['order'] = $orderSql;
}

$result = $repo::list($listOpts);
$bulkFlash = BulkActions::consumeFlash();

$viewData = [
    'title' => t($cfg['title']),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t($cfg['title']), 'current' => true],
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
];
if ($entity === 'keys') {
    $viewData['dict'] = $dict;
    $viewData['dictOptions'] = RecordsDictsRepository::options(true);
}

if ($wantsJson) {
    $payload = [
        'ok' => true,
        'entity' => $entity,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'can_edit' => Auth::can('audio_dicts.edit'),
    ];
    if ($entity === 'keys') {
        $payload['filters']['dict'] = $dict > 0 ? $dict : null;
        $payload['dict_options'] = RecordsDictsRepository::options(true);
    }
    AgentHttp::emit($payload);
    return;
}

View::render($cfg['view'], $viewData, 'layout', 'audio_dicts');
