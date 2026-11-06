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

$canSeeSensitive = Auth::capability('SUPERADMIN_ACCESS');
$profile = EventBus::emit('core.user_edit.profile', [
    'html_channels' => '',
    'html_flags' => '',
    'html_site' => '',
    'user' => $user,
    'id' => $id,
    'can_see_sensitive' => $canSeeSensitive,
]);

$menuPreview = null;
if ($canSeeSensitive) {
    $menuPreview = [
        'available' => false,
        'groups' => [],
    ];
    if ((string)($user['enabled'] ?? '') === '1') {
        $labelById = [];
        foreach (UsersRepository::availablePermissionKeys() as $group) {
            foreach ($group['keys'] as $key) {
                $labelById[(string)$key['id']] = (string)$key['label'];
            }
        }
        try {
            $menuPreview = Auth::runAs($id, static function () use ($labelById): array {
                $perms = Auth::permissions();
                $groups = [];
                foreach (ModuleManager::menuGrouped() as $groupTitle => $items) {
                    $rows = [];
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $permission = isset($item['permission']) ? (string)$item['permission'] : '';
                        $extras = [];
                        if ($permission !== '' && preg_match('/^([a-z][a-z0-9_]*)\./', $permission, $m) === 1) {
                            $prefix = $m[1] . '.';
                            foreach ($perms as $permId => $on) {
                                if ((int)$on !== 1) {
                                    continue;
                                }
                                $permId = (string)$permId;
                                if ($permId === $permission || strpos($permId, $prefix) !== 0) {
                                    continue;
                                }
                                $labelKey = $labelById[$permId] ?? $permId;
                                $extras[] = [
                                    'id' => $permId,
                                    'label' => t($labelKey),
                                ];
                            }
                        }
                        $rows[] = [
                            'title' => (string)($item['title'] ?? ''),
                            'path' => (string)($item['path'] ?? ''),
                            'permission' => $permission,
                            'extras' => $extras,
                        ];
                    }
                    if ($rows !== []) {
                        $groups[(string)$groupTitle] = $rows;
                    }
                }
                return [
                    'available' => true,
                    'groups' => $groups,
                ];
            });
        } catch (Throwable $e) {
            $menuPreview = [
                'available' => false,
                'groups' => [],
            ];
        }
    }
}

View::render('user', [
    'title' => $displayName,
    'user' => $user,
    'statusName' => $statusName,
    'partnerStatusName' => $partnerStatusName,
    'favoritesCount' => count(UsersRepository::favoriteIds($id)),
    'canManageAuth' => $canSeeSensitive,
    'canEdit' => $canSeeSensitive,
    'canSeeSensitive' => $canSeeSensitive,
    'canBotLinkCode' => $canSeeSensitive && ModuleManager::isEnabled('bots'),
    'flash' => flash_take(),
    'profileChannelsHtml' => is_array($profile) ? (string)($profile['html_channels'] ?? '') : '',
    'profileFlagsHtml' => is_array($profile) ? (string)($profile['html_flags'] ?? '') : '',
    'profileSiteHtml' => is_array($profile) ? (string)($profile['html_site'] ?? '') : '',
    'menuPreview' => $menuPreview,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.users'), 'href' => '/users'],
        ['label' => $displayName, 'current' => true],
    ]),
]);
