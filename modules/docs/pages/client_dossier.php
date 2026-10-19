<?php
declare(strict_types=1);

/** Full medcard print: reuse the client's unprinted dossier or start a new one (each freeze = audit row). */
$clientId = (int)(Router::param('id') ?? 0);
$client = $clientId > 0 && class_exists('ClientsRepository', false) ? ClientsRepository::find($clientId) : null;
DocsDefsRepository::ensureSeeded();
$def = DocsDefsRepository::firstOfKind('medcard_dossier');
$msg = '';
if (!$client) {
    $msg = t('docs.err.client_not_found', ['id' => $clientId]);
} elseif ($def === null) {
    $msg = t('docs.err.no_dossier_def');
} elseif (!Auth::can('docs.print')) {
    $msg = t('docs.err.no_print');
}
if ($msg !== '') {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error($msg, 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => $msg, 'message' => $msg]);
    return;
}

$current = DocsInstancesRepository::latestForClient($clientId, 'medcard_dossier');
$id = ($current !== null && (int)$current['dt_print'] === 0)
    ? (int)$current['id']
    : DocsInstancesRepository::create($def, ['client' => $clientId, 'source' => 'spawn']);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'client_id' => $clientId, 'id' => $id, 'print' => '/documents/' . $id . '/print']);
    return;
}
redirect('/documents/' . $id . '/print');
