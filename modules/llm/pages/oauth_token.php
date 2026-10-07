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

// HTTP Basic client auth
$basic = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Basic\s+(\S+)/i', $basic, $bm)) {
    $decodedBasic = base64_decode($bm[1], true);
    if (is_string($decodedBasic) && strpos($decodedBasic, ':') !== false) {
        [$cid, $csec] = explode(':', $decodedBasic, 2);
        if ($clientId === '') {
            $clientId = $cid;
        }
        if ($clientSecret === '') {
            $clientSecret = $csec;
        }
    }
}

$client = OAuthClientsRepository::findByClientId($clientId);
if ($client === null || empty($client['enabled'])) {
    OAuthServer::tokenError('invalid_client', 'Unknown client', 401);
}

$secretOk = OAuthServer::verifySecret($clientSecret, (string)$client['client_secret_hash']);

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
    } elseif (!$secretOk) {
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
    if (!$secretOk) {
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
