<?php
declare(strict_types=1);

/**
 * OAuth 2.0 Dynamic Client Registration (RFC 7591) for ChatGPT Custom MCP.
 */
OAuthServer::corsHeaders();
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    OAuthServer::emitJson(['error' => 'invalid_request', 'error_description' => 'POST required'], 405);
}

$raw = file_get_contents('php://input');
$in = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($in)) {
    // Also accept form posts
    $in = $_POST;
}
if (!is_array($in) || $in === []) {
    OAuthServer::emitJson(['error' => 'invalid_client_metadata', 'error_description' => 'JSON body required'], 400);
}

$uris = [];
if (isset($in['redirect_uris']) && is_array($in['redirect_uris'])) {
    foreach ($in['redirect_uris'] as $u) {
        $u = trim((string)$u);
        if ($u === '') {
            continue;
        }
        if (!OAuthServer::isTrustedRedirectUri($u)) {
            OAuthServer::emitJson([
                'error' => 'invalid_redirect_uri',
                'error_description' => 'redirect_uri host not allowed: ' . $u,
            ], 400);
        }
        $uris[] = $u;
    }
}
$uris = array_values(array_unique($uris));
if ($uris === []) {
    OAuthServer::emitJson(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris required'], 400);
}

$authMethod = strtolower(trim((string)($in['token_endpoint_auth_method'] ?? 'none')));
if ($authMethod === '') {
    $authMethod = 'none';
}
$public = in_array($authMethod, ['none', ''], true);
if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
    OAuthServer::emitJson([
        'error' => 'invalid_client_metadata',
        'error_description' => 'unsupported token_endpoint_auth_method',
    ], 400);
}

$name = trim((string)($in['client_name'] ?? 'Dynamic MCP client'));
if ($name === '') {
    $name = 'Dynamic MCP client';
}

$clientId = 'fs_dcr_' . bin2hex(random_bytes(12));
$plainSecret = $public ? '' : bin2hex(random_bytes(24));
$res = OAuthClientsRepository::upsertExternal(
    $clientId,
    $name,
    $uris,
    OAuthServer::SCOPE_READ . ' ' . OAuthServer::SCOPE_WRITE,
    $public,
    $plainSecret
);
if (!$res['ok']) {
    OAuthServer::emitJson(['error' => 'server_error', 'error_description' => $res['error']], 500);
}

$out = [
    'client_id' => $res['client_id'],
    'client_id_issued_at' => time(),
    'redirect_uris' => $uris,
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => $public ? 'none' : $authMethod,
    'client_name' => $name,
    'scope' => OAuthServer::SCOPE_READ . ' ' . OAuthServer::SCOPE_WRITE,
];
if (!$public) {
    $out['client_secret'] = $res['client_secret'];
    $out['client_secret_expires_at'] = 0;
}

OAuthServer::emitJson($out, 201);
