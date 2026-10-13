<?php
declare(strict_types=1);

/**
 * Staff/MCP help body from docs/agents via module.json agent.docs.
 * Agent op: core.help.get
 */
$id = trim((string)(Router::param('id') ?? ''));
$section = trim((string)($_GET['section'] ?? 'all'));
$max = (int)($_GET['max_chars'] ?? 0);
if ($max <= 0) {
    $max = 16000;
}
if ($max > 50000) {
    $max = 50000;
}

$payload = HelpDocs::get($id, $section, $max);
if ($payload === null) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('core.help.err.not_found', ['id' => $id]), 404);
    }
    http_response_code(404);
    echo e(t('core.help.err.not_found', ['id' => $id]));
    return;
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($payload);
    return;
}

View::render('help_get', [
    'title' => $payload['title'] ?? $id,
    'payload' => $payload,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.help.search_title'), 'href' => '/help'],
        ['label' => (string)($payload['title'] ?? $id), 'current' => true],
    ]),
], 'layout', 'core');
