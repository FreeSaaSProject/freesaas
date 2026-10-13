<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$client = ClientsRepository::find($id);
if (!$client) {
    if ($wantsJson) {
        AgentHttp::error(t('clients.err.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('clients.err.not_found', ['id' => $id])]);
    return;
}
if (!ClientsRepository::canAccessClient($id)) {
    if ($wantsJson) {
        AgentHttp::error(t('clients.err.forbidden_day'), 403);
        return;
    }
    http_response_code(403);
    View::render('error', ['title' => t('core.common.forbidden'), 'message' => t('clients.err.forbidden_day')]);
    return;
}

$statuses = ClientStatusesRepository::names(false);
$statusName = $statuses[(int)$client['status_id']] ?? ((int)$client['status_id'] > 0 ? ('#' . $client['status_id']) : '—');

$staffName = '';
$staffId = (int)($client['staff_user_id'] ?? 0);
if ($staffId > 0 && Database::tableExists('base_users')) {
    $st = Database::pdo()->prepare('SELECT name, login FROM base_users WHERE id = ? LIMIT 1');
    $st->execute([$staffId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $staffName = (string)($u['name'] ?: $u['login']);
    }
}

$profile = EventBus::emit('clients.view.profile', [
    'html' => '',
    'client' => $client,
    'id' => $id,
]);
$extraHtml = is_array($profile) ? (string)($profile['html'] ?? '') : '';
if (is_array($profile) && isset($profile['client']) && is_array($profile['client'])) {
    $client = $profile['client'];
}

$tagNames = ClientsRepository::tagNamesByClientIds([$id])[$id] ?? [];

if ($wantsJson) {
    $item = ClientsRepository::forAgent($client, true);
    AgentHttp::emit([
        'ok' => true,
        'item' => $item,
        'status_name' => $statusName !== '—' ? $statusName : null,
        'staff_name' => $staffName !== '' ? $staffName : null,
        'tag_names' => $tagNames,
        'can_edit' => Auth::can('clients.edit'),
    ]);
    return;
}

View::render('view', [
    'title' => $client['name'] ?: t('clients.view.title_fallback', ['id' => $id]),
    'client' => $client,
    'statusName' => $statusName,
    'staffName' => $staffName,
    'tagNames' => $tagNames,
    'canEdit' => Auth::can('clients.edit'),
    'extraHtml' => $extraHtml,
], 'layout', 'clients');
