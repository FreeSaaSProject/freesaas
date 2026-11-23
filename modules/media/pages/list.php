<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ownerTable = trim((string)($_GET['owner_table'] ?? ''));
$ownerId = (int)($_GET['owner_id'] ?? 0);
$kind = trim((string)($_GET['kind'] ?? ''));
$idUser = (int)($_GET['id_user'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$filters = [
    'owner_table' => $ownerTable,
    'owner_id' => $ownerId > 0 ? (string)$ownerId : '',
    'kind' => $kind,
    'id_user' => $idUser > 0 ? (string)$idUser : '',
    'q' => $q,
];

BulkActions::tryHandle('media_attachments', '/media', $filters);

$result = MediaAttachmentsRepository::list([
    'owner_table' => $ownerTable,
    'owner_id' => $ownerId > 0 ? $ownerId : null,
    'kind' => $kind,
    'id_user' => $idUser > 0 ? $idUser : null,
    'q' => $q,
    'limit' => $perPage,
    'offset' => ($page - 1) * $perPage,
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $result['items'],
        'total' => $result['total'],
        'page' => $page,
        'per_page' => $perPage,
        'filters' => $filters,
    ]);
    return;
}

$bulkFlash = BulkActions::consumeFlash();

View::render('list', [
    'title' => t('media.list.title'),
    'items' => $result['items'],
    'total' => $result['total'],
    'page' => $page,
    'perPage' => $perPage,
    'filters' => [
        'owner_table' => $ownerTable,
        'owner_id' => $ownerId,
        'kind' => $kind,
        'id_user' => $idUser,
        'q' => $q,
    ],
    'bulkFlash' => $bulkFlash,
    'listId' => 'media_attachments',
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('media.menu.list'), 'current' => true],
    ]),
], 'layout', 'media');
