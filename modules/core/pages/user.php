<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/UsersRepository.php');
require_once base_path('modules/core/lib/LoginLinksRepository.php');

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$user = UsersRepository::findForEdit($id);
if (!$user) {
    if ($wantsJson) {
        AgentHttp::error(t('core.user.err.not_found', ['id' => $id]), 404);
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.user.err.not_found', ['id' => $id])]);
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Auth::capability('SUPERADMIN_ACCESS')) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login_link') {
        try {
            $link = LoginLinksRepository::create($id, Auth::userId(), 15);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'url' => $link['url'],
                    'expires_at' => $link['expires_at'],
                    'ttl_minutes' => $link['ttl_minutes'],
                ]);
            }
            flash_set('ok', t('core.user.login_link_flash', ['url' => $link['url']]));
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error(t('core.user.login_link_error'), 400);
            }
            flash_set('error', t('core.user.login_link_error'));
        }
        redirect('/users/' . $id);
    }
    if ($action === 'unlock') {
        Auth::unlockUser($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'unlocked' => true]);
        }
        flash_set('ok', t('core.user.unlock_ok'));
        redirect('/users/' . $id);
    }
    if ($action === 'logout_all') {
        $epoch = Auth::bumpAuthEpoch($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'logout_all' => true, 'auth_epoch' => $epoch]);
        }
        flash_set('ok', t('core.user.logout_all_ok'));
        redirect('/users/' . $id);
    }
    if ($action === 'bot_link_code' && ModuleManager::isEnabled('bots')) {
        require_once base_path('modules/bots/lib/BotLinksRepository.php');
        require_once base_path('modules/bots/lib/BotLinkCodesRepository.php');
        try {
            $code = BotLinkCodesRepository::create(BotLinksRepository::SOURCE_STAFF, $id);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'code' => $code['code'],
                    'expires_at' => $code['expires_at'],
                    'ttl_seconds' => $code['ttl_seconds'],
                ]);
            }
            flash_set('ok', t('core.user.bot_link_code_flash', [
                'code' => $code['code'],
                'minutes' => (string)max(1, (int)ceil($code['ttl_seconds'] / 60)),
            ]));
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error(t('core.user.bot_link_code_error'), 400);
            }
            flash_set('error', t('core.user.bot_link_code_error'));
        }
        redirect('/users/' . $id);
    }
}

$statuses = UsersRepository::statusNames();
$statusName = $statuses[(int)$user['ID_STATUS']] ?? ('#' . $user['ID_STATUS']);
$partnerStatusName = '';
$s2 = (int)($user['ID_STATUS2'] ?? 0);
if ($s2 > 0) {
    $partnerStatusName = $statuses[$s2] ?? ('#' . $s2);
}

$displayName = (string)($user['name'] ?: $user['login']);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => UsersRepository::forAgent($user, true),
        'status_name' => $statusName,
        'partner_status_name' => $partnerStatusName,
        'favorites_count' => count(UsersRepository::favoriteIds($id)),
    ]);
}

$profile = EventBus::emit('core.user_edit.profile', [
    'html_channels' => '',
    'html_flags' => '',
    'html_site' => '',
    'user' => $user,
    'id' => $id,
]);

View::render('user', [
    'title' => $displayName,
    'user' => $user,
    'statusName' => $statusName,
    'partnerStatusName' => $partnerStatusName,
    'favoritesCount' => count(UsersRepository::favoriteIds($id)),
    'canManageAuth' => Auth::capability('SUPERADMIN_ACCESS'),
    'canEdit' => Auth::capability('SUPERADMIN_ACCESS'),
    'canBotLinkCode' => Auth::capability('SUPERADMIN_ACCESS') && ModuleManager::isEnabled('bots'),
    'flash' => flash_take(),
    'profileChannelsHtml' => is_array($profile) ? (string)($profile['html_channels'] ?? '') : '',
    'profileFlagsHtml' => is_array($profile) ? (string)($profile['html_flags'] ?? '') : '',
    'profileSiteHtml' => is_array($profile) ? (string)($profile['html_site'] ?? '') : '',
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.users'), 'href' => '/users'],
        ['label' => $displayName, 'current' => true],
    ]),
]);
