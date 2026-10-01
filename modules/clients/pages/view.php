<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$client = ClientsRepository::find($id);
if (!$client) {
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('clients.err.not_found', ['id' => $id])]);
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

View::render('view', [
    'title' => $client['name'] ?: t('clients.view.title_fallback', ['id' => $id]),
    'client' => $client,
    'statusName' => $statusName,
    'staffName' => $staffName,
    'tagNames' => ClientsRepository::tagNamesByClientIds([$id])[$id] ?? [],
    'canEdit' => Auth::can('clients.edit'),
], 'layout', 'clients');
