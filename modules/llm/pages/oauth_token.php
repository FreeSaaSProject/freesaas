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

$grant = (string)($in['grant_type'] ?? '');
$clientId = trim((string)($in['client_id'] ?? ''));
$clientSecret = (string)($in['client_secret'] ?? '');
// ChatGPT / Codex may send resource= — ignore for opaque tokens.
unset($in['resource']);

// Also accept application/x-www-form-urlencoded raw body when $_POST is empty
if ($in === [] || ($grant === '' && $clientId === '')) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '' && stripos($ct, 'json') === false) {
        $parsed = [];
        parse_str($raw, $parsed);
        if (is_array($parsed) && $parsed !== []) {
            $in = $parsed;
            $grant = (string)($in['grant_type'] ?? '');
            $clientId = trim((string)($in['client_id'] ?? $clientId));
            $clientSecret = (string)($in['client_secret'] ?? $clientSecret);
        }
    }
}

// HTTP Basic client auth (CIMD URL client_ids contain ':')
$basic = OAuthServer::parseBasicClientAuth(OAuthServer::requestAuthorizationHeader());
if ($clientId === '' && $basic['client_id'] !== '') {
    $clientId = $basic['client_id'];
}
if ($clientSecret === '' && $basic['client_secret'] !== '') {
    $clientSecret = $basic['client_secret'];
}

$client = OAuthServer::resolveClient($clientId);
if ($client === null || empty($client['enabled'])) {
    OAuthServer::tokenError('invalid_client', 'Unknown client', 401);
}

$public = OAuthServer::isPublicClient($client);
$secretOk = !$public && OAuthServer::verifySecret($clientSecret, (string)$client['client_secret_hash']);
if (!$public && !$secretOk && $clientSecret !== '') {
    OAuthServer::tokenError('invalid_client', 'Client authentication failed', 401);
}

if ($grant === 'authorization_code') {
    $code = (string)($in['code'] ?? '');
    $redirectUri = trim((string)($in['redirect_uri'] ?? ''));
    $verifier = (string)($in['code_verifier'] ?? '');
    $row = OAuthTokensRepository::consumeCode($code);
    if ($row === null) {
        OAuthServer::tokenError('invalid_grant', 'Invalid or expired code');
    }
    if ((string)$row['client_id'] !== $clientId) {
        OAuthServer::tokenError('invalid_grant', 'Code client mismatch');
    }
    if ($redirectUri === '' || !hash_equals((string)$row['redirect_uri'], $redirectUri)) {
        OAuthServer::tokenError('invalid_grant', 'redirect_uri mismatch');
    }
    $challenge = (string)$row['code_challenge'];
    if ($challenge !== '') {
        if ($verifier === '' || !OAuthServer::verifyPkce($verifier, $challenge, (string)$row['code_challenge_method'])) {
            OAuthServer::tokenError('invalid_grant', 'PKCE verification failed');
        }
    } elseif ($public || !$secretOk) {
        OAuthServer::tokenError('invalid_grant', 'PKCE required for public clients');
    }

    // Confidential clients without PKCE still need a valid secret.
    if (!$public && $challenge === '' && !$secretOk) {
        OAuthServer::tokenError('invalid_client', 'Client authentication failed', 401);
    }

    $tokens = OAuthTokensRepository::issueTokens($clientId, (int)$row['user_id'], (string)$row['scope']);
    if ($tokens === null) {
        OAuthServer::tokenError('server_error', 'Could not issue tokens', 500);
    }
    OAuthServer::tokenSuccess([
        'access_token' => $tokens['access_token'],
        'token_type' => 'Bearer',
        'expires_in' => $tokens['expires_in'],
        'refresh_token' => $tokens['refresh_token'],
        'scope' => $tokens['scope'],
    ]);
}

if ($grant === 'refresh_token') {
    if (!$public && !$secretOk) {
        OAuthServer::tokenError('invalid_client', 'Client authentication failed', 401);
    }
    $refresh = (string)($in['refresh_token'] ?? '');
    $tokens = OAuthTokensRepository::refresh($refresh);
    if ($tokens === null) {
        OAuthServer::tokenError('invalid_grant', 'Invalid refresh token');
    }
    if ((string)($tokens['client_id'] ?? '') !== $clientId) {
        OAuthServer::tokenError('invalid_grant', 'Refresh token client mismatch');
    }
    OAuthServer::tokenSuccess([
        'access_token' => $tokens['access_token'],
        'token_type' => 'Bearer',
        'expires_in' => $tokens['expires_in'],
        'refresh_token' => $tokens['refresh_token'],
        'scope' => $tokens['scope'],
    ]);
}

OAuthServer::tokenError('unsupported_grant_type', 'Use authorization_code or refresh_token');
