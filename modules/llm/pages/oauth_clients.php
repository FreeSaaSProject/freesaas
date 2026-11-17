<?php
declare(strict_types=1);

$message = '';
$error = '';
$newSecret = '';

$base = OAuthServer::publicBaseUrl();
$mcpUrl = OAuthServer::mcpUrl();
$endpoints = [
    'mcp' => $mcpUrl,
    'authorize' => OAuthServer::authorizeUrl(),
    'token' => OAuthServer::tokenUrl(),
    'register' => OAuthServer::registerUrl(),
    'revoke' => OAuthServer::revokeUrl(),
    'oauth_as_metadata' => $base . '/.well-known/oauth-authorization-server',
    'oauth_resource_metadata' => $base . '/.well-known/oauth-protected-resource',
    'openapi' => OAuthServer::openapiUrl(),
    'api_tools' => $base . '/llm/api/tools',
];

// Tokens do not store issuer/domain; OAuth issuer is publicBaseUrl(). Warn when admin UI host differs.
$oauthHost = strtolower((string)(parse_url($base, PHP_URL_HOST) ?: ''));
$reqHost = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '')));
if (strpos($reqHost, ',') !== false) {
    $reqHost = trim(explode(',', $reqHost)[0]);
}
if (strpos($reqHost, ':') !== false && !preg_match('/^\[[^\]]+\]/', $reqHost)) {
    $reqHost = explode(':', $reqHost)[0];
}
$hostMismatch = $oauthHost !== '' && $reqHost !== '' && $oauthHost !== $reqHost;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (OAuthClientsRepository::delete($id)) {
            $message = t('llm.oauth.deleted');
        } else {
            $error = t('llm.oauth.err.not_found');
        }
    } elseif ($action === 'revoke_connection') {
        $id = (int)($_POST['id'] ?? 0);
        if (OAuthTokensRepository::revokeById($id)) {
            $message = t('llm.oauth.connection_revoked');
        } else {
            $error = t('llm.oauth.err.connection_not_found');
        }
    }
}

$clients = OAuthClientsRepository::listAll();
$connections = OAuthTokensRepository::listActiveConnections();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'clients' => array_map(static function (array $c): array {
            unset($c['client_secret_hash']);
            return $c;
        }, $clients),
        'connections' => $connections,
        'endpoints' => $endpoints,
    ]);
    return;
}

View::render('oauth_clients', [
    'title' => t('llm.oauth.title'),
    'message' => $message,
    'error' => $error,
    'clients' => $clients,
    'connections' => $connections,
    'endpoints' => $endpoints,
    'mcpUrl' => $mcpUrl,
    'oauthBase' => $base,
    'hostMismatch' => $hostMismatch,
    'requestHost' => $reqHost,
    'oauthHost' => $oauthHost,
    'assistant_enabled' => ModuleManager::isEnabled('assistant'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('llm.menu.group'), 'href' => '/llm/tokens'],
        ['label' => t('llm.menu.oauth'), 'current' => true],
    ]),
], 'layout', 'llm');
