<?php
declare(strict_types=1);

$clientId = (int)(Router::param('id') ?? 0);
$client = $clientId > 0 && class_exists('ClientsRepository', false) ? ClientsRepository::find($clientId) : null;
$profileId = $client ? DocsSpawner::ensureProfile($clientId) : null;

if (AgentHttp::wantsJson()) {
    if ($profileId === null) {
        AgentHttp::error(t('docs.err.client_not_found', ['id' => $clientId]), 404);
        return;
    }
    AgentHttp::emit(['ok' => true, 'client_id' => $clientId, 'id' => $profileId, 'item' => DocsInstancesRepository::find($profileId)]);
    return;
}
if ($profileId === null) {
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('docs.err.client_not_found', ['id' => $clientId])]);
    return;
}
redirect('/documents/' . $profileId . '/edit');
