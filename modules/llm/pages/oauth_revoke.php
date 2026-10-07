<?php
declare(strict_types=1);

OAuthServer::corsHeaders();
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    OAuthServer::tokenError('invalid_request', 'POST required', 405);
}

$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$in = $_POST;
if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $in = $decoded;
    }
}

$token = (string)($in['token'] ?? $in['access_token'] ?? '');
OAuthTokensRepository::revokeAccess($token);
// RFC 7009: always 200
OAuthServer::corsHeaders();
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo '{"revoked":true}';
exit;
