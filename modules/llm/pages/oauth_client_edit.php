<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1 || (request_path() === '/llm/oauth/new');
$row = $isNew ? null : OAuthClientsRepository::find($id);
if (!$isNew && $row === null) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('llm.oauth.err.not_found'), 404);
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('llm.oauth.err.not_found')]);
    return;
}

$message = '';
$error = '';
$shownSecret = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'rotate' && !$isNew) {
        $res = OAuthClientsRepository::rotateSecret($id);
        if ($res['ok']) {
            $shownSecret = $res['client_secret'];
            $message = t('llm.oauth.secret_rotated');
            $row = OAuthClientsRepository::find($id);
        } else {
            $error = $res['error'];
        }
    } else {
        $name = (string)($_POST['name'] ?? '');
        $urisRaw = (string)($_POST['redirect_uris'] ?? '');
        $scopes = (string)($_POST['scopes'] ?? 'ops.read');
        $enabled = !empty($_POST['enabled']);
        $uris = OAuthClientsRepository::normalizeUris($urisRaw);

        if ($isNew) {
            $res = OAuthClientsRepository::create($name, $uris, $scopes, Auth::userId());
            if ($res['ok']) {
                if (AgentHttp::wantsJson()) {
                    AgentHttp::emit([
                        'ok' => true,
                        'id' => $res['id'],
                        'client_id' => $res['client_id'],
                        'client_secret' => $res['client_secret'],
                    ]);
                }
                $_SESSION['llm_oauth_flash_secret'] = $res['client_secret'];
                redirect('/llm/oauth/' . $res['id']);
            }
            $error = $res['error'];
            $row = [
                'name' => $name,
                'redirect_uris' => $uris,
                'scopes' => $scopes,
                'enabled' => true,
                'client_id' => '',
            ];
        } else {
            $res = OAuthClientsRepository::update($id, $name, $uris, $scopes, $enabled);
            if ($res['ok']) {
                if (AgentHttp::wantsJson()) {
                    AgentHttp::emit(['ok' => true, 'id' => $id]);
                }
                $message = t('core.common.saved');
                $row = OAuthClientsRepository::find($id);
            } else {
                $error = $res['error'];
            }
        }
    }
}

if ($shownSecret === '' && !empty($_SESSION['llm_oauth_flash_secret'])) {
    $shownSecret = (string)$_SESSION['llm_oauth_flash_secret'];
    unset($_SESSION['llm_oauth_flash_secret']);
    if ($message === '') {
        $message = t('llm.oauth.created');
    }
}

if ($row === null) {
    $row = [
        'name' => '',
        'redirect_uris' => [],
        'scopes' => 'ops.read',
        'enabled' => true,
        'client_id' => '',
    ];
}

if (AgentHttp::wantsJson() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $out = $row;
    unset($out['client_secret_hash']);
    AgentHttp::emit(['ok' => true, 'client' => $out, 'is_new' => $isNew]);
}

View::render('oauth_client_edit', [
    'title' => $isNew ? t('llm.oauth.new') : t('llm.oauth.edit'),
    'is_new' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'shown_secret' => $shownSecret,
    'endpoints' => [
        'authorize' => OAuthServer::authorizeUrl(),
        'token' => OAuthServer::tokenUrl(),
        'mcp' => OAuthServer::mcpUrl(),
        'openapi' => OAuthServer::openapiUrl(),
    ],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('llm.menu.oauth'), 'href' => '/llm/oauth'],
        ['label' => $isNew ? t('llm.oauth.new') : (string)$row['name'], 'current' => true],
    ]),
], 'layout', 'llm');
