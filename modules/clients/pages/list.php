<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '1');
if (!in_array($enabled, ['', '0', '1'], true)) {
    $enabled = '1';
}
$statusId = (int)($_GET['status_id'] ?? 0);
$tagId = (int)($_GET['tag_id'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'clients',
    'allowed' => ClientsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'enabled' => $enabled,
    'status_id' => $statusId > 0 ? $statusId : '',
    'tag_id' => $tagId > 0 ? $tagId : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('clients', '/clients', $filters);

$result = ClientsRepository::list([
    'q' => $q,
    'enabled' => $enabled === '' ? null : $enabled,
    'status_id' => $statusId > 0 ? $statusId : null,
    'tag_id' => $tagId > 0 ? $tagId : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$list = EventBus::emit('clients.list', [
    'items' => $result['items'],
    'extra_columns' => [],
]);
$items = is_array($list) && isset($list['items']) && is_array($list['items'])
    ? $list['items']
    : $result['items'];
$extraColumns = is_array($list) && isset($list['extra_columns']) && is_array($list['extra_columns'])
    ? $list['extra_columns']
    : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => ClientsRepository::listForAgent($items),
        'filters' => [
            'q' => $q,
            'enabled' => $enabled,
            'status_id' => $statusId > 0 ? $statusId : null,
            'tag_id' => $tagId > 0 ? $tagId : null,
        ],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

$bulkFlash = BulkActions::consumeFlash();

$colData = ListQuery::columnsWithData($items, [
    'status' => static function (array $r) {
        return (int)($r['status_id'] ?? 0) > 0 ? ($r['status_name'] ?? 1) : null;
    },
    'tags' => static function (array $r) {
        return $r['tag_names'] ?? [];
    },
    'birthday' => 'birthday',
]);

View::render('list', [
    'title' => t('clients.menu.base'),
    'items' => $items,
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'statusId' => $statusId,
    'tagId' => $tagId,
    'statuses' => ClientStatusesRepository::names(false),
    'tags' => ClientTagsRepository::names(false),
    'showStatusColumn' => !empty($colData['status']),
    'showTagsColumn' => !empty($colData['tags']),
    'showBirthdayColumn' => !empty($colData['birthday']),
    'extraColumns' => $extraColumns,
    'canEdit' => Auth::can('clients.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'clients');
