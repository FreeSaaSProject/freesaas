<?php
declare(strict_types=1);

$message = '';
$error = '';
$newSecret = '';

$base = OAuthServer::publicBaseUrl();
$endpoints = [
    'mcp' => OAuthServer::mcpUrl(),
    'authorize' => OAuthServer::authorizeUrl(),
    'token' => OAuthServer::tokenUrl(),
    'revoke' => OAuthServer::revokeUrl(),
    'oauth_as_metadata' => $base . '/.well-known/oauth-authorization-server',
    'oauth_resource_metadata' => $base . '/.well-known/oauth-protected-resource',
    'openapi' => OAuthServer::openapiUrl(),
    'api_tools' => $base . '/llm/api/tools',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (OAuthClientsRepository::delete($id)) {
            $message = t('llm.oauth.deleted');
        } else {
            $error = t('llm.oauth.err.not_found');
        }
    }
}

$clients = OAuthClientsRepository::listAll();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'clients' => array_map(static function (array $c): array {
            unset($c['client_secret_hash']);
            return $c;
        }, $clients),
        'endpoints' => $endpoints,
    ]);
}

View::render('oauth_clients', [
    'title' => t('llm.oauth.title'),
    'message' => $message,
    'error' => $error,
    'clients' => $clients,
    'endpoints' => $endpoints,
    'assistant_enabled' => ModuleManager::isEnabled('assistant'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('llm.menu.group'), 'href' => '/llm/tokens'],
        ['label' => t('llm.menu.oauth'), 'current' => true],
    ]),
], 'layout', 'llm');
