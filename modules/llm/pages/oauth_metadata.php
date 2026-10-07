<?php
declare(strict_types=1);

/**
 * OAuth discovery documents for ChatGPT Custom MCP / RFC 8414 + 9728.
 * Path is selected by the registered route.
 */
$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$path = rtrim($path, '/') ?: '/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    OAuthServer::corsHeaders();
    http_response_code(204);
    exit;
}

if (strpos($path, '/.well-known/oauth-protected-resource') === 0) {
    OAuthServer::emitJson(OAuthServer::protectedResourceMetadata());
}

// authorization-server + openid-configuration alias
OAuthServer::emitJson(OAuthServer::authorizationServerMetadata());
