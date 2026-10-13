<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);

$client = null;
if ($id > 0 && class_exists('ClientsRepository', false)) {
    $client = ClientsRepository::find($id);
}
if (!$client) {
    if ($wantsJson) {
        AgentHttp::error(t('bonuses.client.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('bonuses.client.not_found', ['id' => $id]),
    ]);
    return;
}

$balance = BonusMovesRepository::balance($id);
$history = BonusMovesRepository::list([
    'client_id' => $id,
    'limit' => 200,
    'offset' => 0,
    'order' => 'm.id ASC',
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'client_id' => $id,
        'client_name' => (string)($client['name'] ?? ''),
        'balance' => $balance,
        'total' => $history['total'],
        'items' => $history['items'],
    ]);
    return;
}

$name = trim((string)($client['name'] ?? ''));
if ($name === '') {
    $name = '#' . $id;
}

View::render('client', [
    'title' => t('bonuses.client.title', ['name' => $name]),
    'client' => $client,
    'clientId' => $id,
    'clientName' => $name,
    'balance' => $balance,
    'items' => $history['items'],
    'canEdit' => Auth::can('bonuses.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bonuses.menu.list'), 'href' => '/bonuses'],
        ['label' => $name, 'current' => true],
    ]),
], 'layout', 'bonuses');
