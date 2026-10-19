<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
DocsDefsRepository::ensureSeeded();

$kind = trim((string)($_GET['kind'] ?? ''));
if ($kind !== '' && !in_array($kind, DocsDefsRepository::KINDS, true)) {
    $kind = '';
}
$clientId = (int)($_GET['client'] ?? 0);
$eventId = (int)($_GET['event'] ?? 0);
$defId = (int)($_GET['def'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '' && !in_array($status, DocsInstancesRepository::STATUSES, true)) {
    $status = '';
}

$visibleKinds = array_values(array_filter(
    DocsDefsRepository::KINDS,
    static fn(string $k): bool => DocsConfig::canSeeKind($k)
));

$lq = ListQuery::resolve([
    'list_id' => 'docs_instances',
    'allowed' => DocsInstancesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'kind' => $kind,
    'client' => $clientId > 0 ? (string)$clientId : '',
    'event' => $eventId > 0 ? (string)$eventId : '',
    'def' => $defId > 0 ? (string)$defId : '',
    'status' => $status,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('docs_instances', '/documents', $filters);

$result = DocsInstancesRepository::list([
    'kind' => in_array($kind, $visibleKinds, true) ? $kind : '',
    'kinds' => $visibleKinds,
    'client' => $clientId,
    'event' => $eventId,
    'def' => $defId,
    'status' => $status,
    'with_void' => $status === 'void',
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => [
            'kind' => $kind,
            'client' => $clientId,
            'event' => $eventId,
            'def' => $defId,
            'status' => $status,
        ],
    ]);
    return;
}

View::render('list', [
    'title' => t('docs.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'kind' => $kind,
    'clientId' => $clientId,
    'eventId' => $eventId,
    'status' => $status,
    'visibleKinds' => $visibleKinds,
    'defs' => array_values(array_filter(
        DocsDefsRepository::listAll(true),
        static fn(array $d): bool => DocsConfig::canSeeKind((string)$d['kind'])
    )),
    'canEdit' => Auth::can('docs.edit'),
    'canPrint' => Auth::can('docs.print'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'docs');
