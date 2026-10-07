<?php
declare(strict_types=1);

$message = '';
$error = '';
$newSecret = '';

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
        'endpoints' => [
            'authorize' => OAuthServer::authorizeUrl(),
            'token' => OAuthServer::tokenUrl(),
            'revoke' => OAuthServer::revokeUrl(),
            'mcp' => OAuthServer::mcpUrl(),
            'openapi' => OAuthServer::openapiUrl(),
        ],
    ]);
}

View::render('oauth_clients', [
    'title' => t('llm.oauth.title'),
    'message' => $message,
    'error' => $error,
    'clients' => $clients,
    'endpoints' => [
        'authorize' => OAuthServer::authorizeUrl(),
        'token' => OAuthServer::tokenUrl(),
        'revoke' => OAuthServer::revokeUrl(),
        'mcp' => OAuthServer::mcpUrl(),
        'openapi' => OAuthServer::openapiUrl(),
        'api_tools' => OAuthServer::publicBaseUrl() . '/llm/api/tools',
    ],
    'assistant_enabled' => ModuleManager::isEnabled('assistant'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('llm.menu.group'), 'href' => '/llm/tokens'],
        ['label' => t('llm.menu.oauth'), 'current' => true],
    ]),
], 'layout', 'llm');
