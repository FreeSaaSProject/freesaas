<?php
declare(strict_types=1);

$responseType = (string)($_GET['response_type'] ?? $_POST['response_type'] ?? '');
$clientId = trim((string)($_GET['client_id'] ?? $_POST['client_id'] ?? ''));
$redirectUri = trim((string)($_GET['redirect_uri'] ?? $_POST['redirect_uri'] ?? ''));
$scope = trim((string)($_GET['scope'] ?? $_POST['scope'] ?? ''));
$state = (string)($_GET['state'] ?? $_POST['state'] ?? '');
$challenge = trim((string)($_GET['code_challenge'] ?? $_POST['code_challenge'] ?? ''));
$challengeMethod = trim((string)($_GET['code_challenge_method'] ?? $_POST['code_challenge_method'] ?? 'S256'));

$errorRedirect = static function (string $error, string $desc = '') use ($redirectUri, $state): void {
    if ($redirectUri === '' || !preg_match('#^https?://#i', $redirectUri)) {
        http_response_code(400);
        echo e($error . ($desc !== '' ? ': ' . $desc : ''));
        exit;
    }
    $q = http_build_query(array_filter([
        'error' => $error,
        'error_description' => $desc,
        'state' => $state !== '' ? $state : null,
    ]));
    $sep = strpos($redirectUri, '?') === false ? '?' : '&';
    redirect($redirectUri . $sep . $q);
};

if ($responseType !== 'code') {
    $errorRedirect('unsupported_response_type', 'Only response_type=code is supported');
}

$client = OAuthClientsRepository::findByClientId($clientId);
if ($client === null || empty($client['enabled'])) {
    http_response_code(400);
    echo e(t('llm.oauth.err.client'));
    exit;
}

if ($redirectUri === '' || !in_array($redirectUri, $client['redirect_uris'], true)) {
    http_response_code(400);
    echo e(t('llm.oauth.err.redirect_mismatch'));
    exit;
}

if (!Auth::check()) {
    $return = '/oauth/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => $scope,
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => $challengeMethod,
    ]);
    redirect('/login?ret=' . rawurlencode($return));
}

$grantedScope = OAuthServer::intersectScopes($scope, (string)$client['scopes']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'deny') {
        $errorRedirect('access_denied', 'User denied the request');
    }
    if ($action !== 'allow') {
        http_response_code(400);
        echo 'bad action';
        exit;
    }
    $codeRow = OAuthTokensRepository::createCode(
        $clientId,
        Auth::userId(),
        $redirectUri,
        $grantedScope,
        $challenge,
        $challengeMethod
    );
    if ($codeRow === null) {
        $errorRedirect('server_error', 'Could not create code');
    }
    $q = http_build_query(array_filter([
        'code' => $codeRow['code'],
        'state' => $state !== '' ? $state : null,
    ]));
    $sep = strpos($redirectUri, '?') === false ? '?' : '&';
    redirect($redirectUri . $sep . $q);
}

View::render('oauth_authorize', [
    'title' => t('llm.oauth.authorize.title'),
    'client' => $client,
    'scope' => $grantedScope,
    'redirect_uri' => $redirectUri,
    'state' => $state,
    'response_type' => $responseType,
    'client_id' => $clientId,
    'code_challenge' => $challenge,
    'code_challenge_method' => $challengeMethod,
    'scope_raw' => $scope,
], 'layout_auth', 'llm');
