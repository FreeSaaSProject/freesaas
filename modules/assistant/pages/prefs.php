<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$userId = Auth::userId();
if ($userId < 1) {
    if ($wantsJson) {
        AssistantAccess::denyJson(401);
    }
    redirect('/login');
}

$message = '';
$error = '';
$canSeeOauthBase = Auth::can('llm.manage') && Auth::capability('SUPERADMIN_ACCESS')
    && ModuleManager::isEnabled('llm');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $fabOff = !empty($_POST['fab_off']);
        AssistantPrefs::setFabOff($userId, $fabOff);
        $unhide = $_POST['unhide'] ?? [];
        if (is_array($unhide)) {
            $paths = [];
            foreach ($unhide as $p) {
                $paths[] = (string)$p;
            }
            AssistantPrefs::unhidePaths($userId, $paths);
        }
        $message = t('core.common.saved');
        if ($wantsJson) {
            $payload = [
                'ok' => true,
                'saved' => true,
                'fab_off' => AssistantPrefs::isFabOff($userId),
                'hidden_paths' => AssistantPrefs::listHiddenPaths($userId),
            ];
            if ($canSeeOauthBase) {
                $payload['app_base_url'] = rtrim(trim((string)Config::get('app', 'base_url', '')), '/');
                $payload['oauth_base'] = class_exists('OAuthServer', false) ? OAuthServer::publicBaseUrl() : '';
                $payload['mcp_url'] = class_exists('OAuthServer', false) ? OAuthServer::mcpUrl() : '';
            }
            AgentHttp::emit($payload);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$fabOff = AssistantPrefs::isFabOff($userId);
$hidden = AssistantPrefs::listHiddenPaths($userId);
$appBaseUrl = $canSeeOauthBase ? rtrim(trim((string)Config::get('app', 'base_url', '')), '/') : '';
$oauthBase = ($canSeeOauthBase && class_exists('OAuthServer', false)) ? OAuthServer::publicBaseUrl() : '';
$mcpUrl = ($canSeeOauthBase && class_exists('OAuthServer', false)) ? OAuthServer::mcpUrl() : '';

if ($wantsJson) {
    $payload = [
        'ok' => true,
        'fab_off' => $fabOff,
        'hidden_paths' => $hidden,
    ];
    if ($canSeeOauthBase) {
        $payload['app_base_url'] = $appBaseUrl;
        $payload['oauth_base'] = $oauthBase;
        $payload['mcp_url'] = $mcpUrl;
    }
    AgentHttp::emit($payload);
    return;
}

View::render('prefs', [
    'title' => t('assistant.prefs.title'),
    'message' => $message,
    'error' => $error,
    'fab_off' => $fabOff,
    'hidden_paths' => $hidden,
    'can_see_oauth_base' => $canSeeOauthBase,
    'app_base_url' => $appBaseUrl,
    'oauth_base' => $oauthBase,
    'mcp_url' => $mcpUrl,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => AssistantConfig::displayName(), 'href' => '/assistant'],
        ['label' => t('assistant.prefs.title'), 'current' => true],
    ]),
], 'layout', 'assistant');
