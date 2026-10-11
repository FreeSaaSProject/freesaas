<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/UsersRepository.php');

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$user = [];
if (!$isNew) {
    $found = UsersRepository::findForEdit($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('core.user.err.not_found', ['id' => $id]), 404);
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.user.err.not_found', ['id' => $id])]);
        return;
    }
    $user = $found;
}

$message = '';
$error = '';
$isSuper = Auth::isSuperAdmin();
$permGroups = UsersRepository::availablePermissionKeys();

$adminStatuses = UsersRepository::statusNamesFiltered(false);
$partnerStatuses = UsersRepository::statusNamesFiltered(true);
$allStatuses = UsersRepository::statusNames();
$curRole = (int)($user['ID_STATUS'] ?? 0);
if ($curRole > 0 && !isset($adminStatuses[$curRole]) && isset($allStatuses[$curRole])) {
    $adminStatuses[$curRole] = $allStatuses[$curRole];
}
$copyUsers = UsersRepository::listForCopy();

$copyFrom = (int)($_GET['copyFrom'] ?? $_POST['copyFrom'] ?? 0);
$userPerms = UsersRepository::decodePermissions($user['status'] ?? null);
if ($copyFrom > 0 && ($isNew || $copyFrom !== $id)) {
    $src = UsersRepository::findForEdit($copyFrom);
    if ($src) {
        $userPerms = UsersRepository::decodePermissions($src['status'] ?? null);
        if (!$isNew || empty($_POST)) {
            $message = t('core.user_edit.perms_copied', ['id' => $copyFrom]);
        }
        // Prefill role from template when creating
        if ($isNew && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')) {
            $user['ID_STATUS'] = (int)($src['ID_STATUS'] ?? 0);
            $user['ID_STATUS2'] = (int)($src['ID_STATUS2'] ?? 0);
            $user['ASSIST_TEAM'] = (int)($src['ASSIST_TEAM'] ?? 0);
            $user['NOT_IN_TEAM'] = (int)($src['NOT_IN_TEAM'] ?? 0);
            $user['IS_HIDDEN'] = (int)($src['IS_HIDDEN'] ?? 0);
            $user['color'] = (string)($src['color'] ?? '');
            $user['color_text'] = (string)($src['color_text'] ?? '');
            $user['skin'] = (string)($src['skin'] ?? '');
            $curRole = (int)$user['ID_STATUS'];
            if ($curRole > 0 && !isset($adminStatuses[$curRole]) && isset($allStatuses[$curRole])) {
                $adminStatuses[$curRole] = $allStatuses[$curRole];
            }
        }
    }
}

$turnon = $_GET['turnon'] ?? null;
if ($turnon === '1' || $turnon === '0') {
    $val = $turnon === '1' ? 1 : 0;
    foreach ($permGroups as $g) {
        foreach ($g['keys'] as $key) {
            $pid = is_array($key) ? (string)$key['id'] : (string)$key;
            $userPerms[$pid] = $val;
        }
    }
    $message = $val ? t('core.user_edit.perms_all_on') : t('core.user_edit.perms_all_off');
}

/**
 * Core-owned fields only. Product modules patch via core.user_edit.save.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed> $current
 * @return array<string,mixed>
 */
$buildData = static function (array $post, array $current, bool $super, bool $creating) use ($permGroups): array {
    $data = [
        'name' => trim((string)($post['name'] ?? '')),
        'mail' => trim((string)($post['mail'] ?? '')),
        'tel' => trim((string)($post['tel'] ?? '')),
        'enabled' => !empty($post['enabled']) ? '1' : '0',
        'ID_STATUS' => (int)($post['ID_STATUS'] ?? 0),
        'color' => trim((string)($post['color'] ?? '')),
        'color_text' => trim((string)($post['color_text'] ?? '')),
        'IS_HIDDEN' => !empty($post['IS_HIDDEN']) ? 1 : 0,
        'num' => max(0, (int)($post['num'] ?? 0)),
        'ASSIST_TEAM' => !empty($post['ASSIST_TEAM']) ? 1 : 0,
        'NOT_IN_TEAM' => !empty($post['NOT_IN_TEAM']) ? 1 : 0,
    ];

    $localeRaw = I18n::normalize((string)($post['locale'] ?? ''));
    if ($localeRaw !== null) {
        $data['locale'] = $localeRaw;
    }

    if (Database::columnExists('base_users', 'ID_STATUS2')) {
        $data['ID_STATUS2'] = (int)($post['ID_STATUS2'] ?? 0);
    }

    $newsAfterRaw = trim((string)($post['showNewsAfter'] ?? ''));
    if ($newsAfterRaw === '') {
        $data['showNewsAfter'] = 0;
    } else {
        $ts = strtotime($newsAfterRaw);
        $data['showNewsAfter'] = $ts !== false ? $ts : 0;
    }

    if (!empty($post['clear_favorites'])) {
        $data['favorites'] = '';
    }

    if ($creating || $super) {
        $pass = (string)($post['password'] ?? '');
        if ($pass !== '') {
            $data['password_plain'] = $pass;
        }
    }
    if ($super) {
        $expireRaw = trim((string)($post['expire'] ?? ''));
        if ($expireRaw === '') {
            $data['expire'] = 0;
        } else {
            $ts = strtotime($expireRaw);
            $data['expire'] = $ts !== false ? $ts : 0;
        }
        $data['retries'] = max(0, (int)($post['retries'] ?? 0));
    }

    if (isset($post['permissions']) && is_array($post['permissions'])) {
        $posted = $post['permissions'];
        $merged = [];
        foreach ($permGroups as $g) {
            foreach ($g['keys'] as $key) {
                $pid = is_array($key) ? (string)$key['id'] : (string)$key;
                $merged[$pid] = !empty($posted[$pid]) ? 1 : 0;
            }
        }
        $data['status_blob'] = UsersRepository::encodeStatusBlob($current['status'] ?? null, $merged);
    }

    return $data;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = $buildData($_POST, $user, $isSuper, $isNew);

        // Agents / MCP must never set or learn passwords.
        if ($wantsJson) {
            unset($data['password_plain'], $_POST['password']);
        }

        if ($isNew) {
            $name = trim((string)($data['name'] ?? ''));
            $tel = trim((string)($data['tel'] ?? ''));
            $loginIn = trim((string)($_POST['login'] ?? ''));
            $serverPassword = '';
            $loginGenerated = false;
            $cloneFrom = (int)($_POST['copyFrom'] ?? $_GET['copyFrom'] ?? 0);

            // Refuse cloning a superadmin template (agents + UI).
            if ($cloneFrom > 0) {
                $srcRow = UsersRepository::findForEdit($cloneFrom);
                if ($srcRow === null) {
                    throw new InvalidArgumentException(t('core.user_edit.err.copy_not_found', ['id' => $cloneFrom]));
                }
                if (UsersRepository::statusIsSuperadmin((int)($srcRow['ID_STATUS'] ?? 0))) {
                    throw new RuntimeException(t('core.user_edit.err.copy_superadmin'));
                }
                if (!isset($_POST['ID_STATUS']) || (int)$_POST['ID_STATUS'] === 0) {
                    $data['ID_STATUS'] = (int)($srcRow['ID_STATUS'] ?? 0);
                }
                if (Database::columnExists('base_users', 'ID_STATUS2')
                    && (!isset($_POST['ID_STATUS2']) || (string)$_POST['ID_STATUS2'] === '')
                ) {
                    $data['ID_STATUS2'] = (int)($srcRow['ID_STATUS2'] ?? 0);
                }
                foreach (['ASSIST_TEAM', 'NOT_IN_TEAM', 'IS_HIDDEN', 'color', 'color_text', 'skin'] as $flag) {
                    if (!array_key_exists($flag, $_POST) && array_key_exists($flag, $srcRow)) {
                        $data[$flag] = $srcRow[$flag];
                    }
                }
                if ($userPerms === []) {
                    $userPerms = UsersRepository::decodePermissions($srcRow['status'] ?? null);
                }
            }

            if ($wantsJson) {
                if ($name === '') {
                    throw new InvalidArgumentException(t('core.user_edit.err.name'));
                }
                // Agents never supply passwords; login optional (derived from tel).
                $login = UsersRepository::allocateLogin($loginIn, $tel);
                $loginGenerated = ($loginIn === '' || $login !== $loginIn);
                $serverPassword = UsersRepository::generateSecretPassword();
            } else {
                if ($loginIn === '') {
                    if ($tel === '') {
                        throw new InvalidArgumentException(t('core.user_edit.err.login'));
                    }
                    $login = UsersRepository::allocateLogin('', $tel);
                    $loginGenerated = true;
                } else {
                    $login = $loginIn;
                    if (UsersRepository::findByLogin($login) !== null) {
                        throw new RuntimeException(t('core.user_edit.err.login_taken'));
                    }
                }
                if (!empty($data['password_plain'])) {
                    $serverPassword = (string)$data['password_plain'];
                } else {
                    $serverPassword = UsersRepository::generateSecretPassword();
                }
            }

            if ($name === '') {
                $name = $login;
                $data['name'] = $name;
            }

            $createPayload = array_merge($data, [
                'login' => $login,
                'password_plain' => $serverPassword,
                'status_blob' => (string)($data['status_blob'] ?? UsersRepository::encodeStatusBlob(null, $userPerms)),
            ]);
            // If permissions not posted but copyFrom set, use preloaded perms
            if (!isset($_POST['permissions']) && $userPerms !== []) {
                $createPayload['status_blob'] = UsersRepository::encodeStatusBlob(null, $userPerms);
            }
            $newId = UsersRepository::create($createPayload);
            $showOncePassword = (!$wantsJson && empty($_POST['password'])) ? $serverPassword : '';
            $serverPassword = '';
            unset($createPayload['password_plain'], $data['password_plain']);

            $created = UsersRepository::findForEdit($newId) ?: ['id' => $newId];
            $saved = EventBus::emit('core.user_edit.save', [
                'post' => $_POST,
                'user' => $created,
                'data' => $data,
                'id' => $newId,
                'is_new' => true,
            ]);
            if (is_array($saved) && isset($saved['data']) && is_array($saved['data'])) {
                $patch = $saved['data'];
                unset($patch['password_plain']);
                UsersRepository::update($newId, $patch);
            }
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'saved' => true,
                    'login' => $login,
                    'login_generated' => $loginGenerated,
                    'password_set_by_server' => true,
                    'password_visible_to_agent' => false,
                    'next' => 'Admin sets a human password in UI (/users/' . $newId . '/edit). Agents never receive passwords.',
                    'item' => UsersRepository::forAgent(UsersRepository::findForEdit($newId), true),
                ]);
            }
            if ($showOncePassword !== '') {
                $_SESSION['core_user_created_secret'] = [
                    'id' => $newId,
                    'password' => $showOncePassword,
                    'until' => time() + 600,
                ];
            }
            redirect('/users/' . $newId . '/edit?saved=1');
            return;
        }

        $loginIn = trim((string)($_POST['login'] ?? ''));
        if ($loginIn === '') {
            throw new InvalidArgumentException(t('core.user_edit.err.login_required'));
        }
        if (UsersRepository::loginTaken($loginIn, $id)) {
            throw new RuntimeException(t('core.user_edit.err.login_taken'));
        }
        $data['login'] = $loginIn;

        $saved = EventBus::emit('core.user_edit.save', [
            'post' => $_POST,
            'user' => $user,
            'data' => $data,
            'id' => $id,
            'is_new' => false,
        ]);
        if (is_array($saved) && isset($saved['data']) && is_array($saved['data'])) {
            $data = $saved['data'];
        }
        if ($wantsJson) {
            unset($data['password_plain']);
        }
        UsersRepository::update($id, $data);
        $user = UsersRepository::findForEdit($id) ?: $user;
        $userPerms = UsersRepository::decodePermissions($user['status'] ?? null);
        if (Auth::userId() === $id) {
            $_SESSION['permissions'] = $userPerms;
            $_SESSION['user_name'] = (string)$user['name'];
            $_SESSION['login'] = (string)$user['login'];
            Auth::setStatusContext((int)$user['ID_STATUS'], (int)($user['ID_STATUS2'] ?? 0));
            if (!empty($data['locale'])) {
                I18n::applyUserChoice((string)$data['locale'], false);
            }
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'saved' => true,
                'item' => UsersRepository::forAgent($user, true),
            ]);
        }
        $message = t('core.user_edit.saved');
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
        }
        $error = $e->getMessage();
        $user = array_merge($user, $_POST);
        if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
            $userPerms = [];
            foreach ($permGroups as $g) {
                foreach ($g['keys'] as $key) {
                    $pid = is_array($key) ? (string)$key['id'] : (string)$key;
                    $userPerms[$pid] = !empty($_POST['permissions'][$pid]) ? 1 : 0;
                }
            }
        }
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.user_edit.saved');
}
if (!$isNew && !empty($_SESSION['core_user_created_secret']) && is_array($_SESSION['core_user_created_secret'])) {
    $flash = $_SESSION['core_user_created_secret'];
    unset($_SESSION['core_user_created_secret']);
    if ((int)($flash['id'] ?? 0) === $id && (int)($flash['until'] ?? 0) >= time()) {
        $once = (string)($flash['password'] ?? '');
        if ($once !== '') {
            $message = t('core.user_edit.temp_password_once', ['password' => $once]);
        }
    }
}

if ($wantsJson) {
    $permKeys = [];
    foreach ($permGroups as $g) {
        $keys = [];
        foreach ($g['keys'] as $key) {
            $keys[] = is_array($key) ? [
                'id' => (string)$key['id'],
                'label' => t((string)($key['label'] ?? $key['id'])),
            ] : ['id' => (string)$key, 'label' => (string)$key];
        }
        $permKeys[] = [
            'name' => t((string)($g['name'] ?? '')),
            'keys' => $keys,
        ];
    }
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => UsersRepository::forAgent($isNew ? ($user ?: null) : $user, true) ?? [
            'id' => 0,
            'permissions' => $userPerms,
        ],
        'permissions' => $userPerms,
        'permission_groups' => $permKeys,
        'admin_statuses' => $adminStatuses,
        'partner_statuses' => $partnerStatuses,
        'copy_users' => $copyUsers,
        'copy_from' => $copyFrom > 0 ? $copyFrom : null,
        'is_super' => $isSuper,
    ]);
}

$genPass = '';
try {
    // 12 hex chars — meets staff (8) and superadmin (10) policy.
    $genPass = bin2hex(random_bytes(6));
} catch (Throwable $e) {
    $genPass = substr(sha1((string)microtime(true)), 0, 12);
}

$sections = EventBus::emit('core.user_edit.sections', [
    'html' => '',
    'html_access' => '',
    'user' => $user ?: ['id' => 0],
    'id' => $id,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';
$extraAccessHtml = is_array($sections) ? (string)($sections['html_access'] ?? '') : '';

$displayName = $isNew
    ? t('core.user_edit.title_new')
    : (string)($user['name'] ?: $user['login']);

View::render('user_edit', [
    'title' => $isNew
        ? t('core.user_edit.title_new')
        : t('core.user_edit.title', ['name' => $displayName]),
    'user' => $user ?: [
        'id' => 0,
        'login' => '',
        'name' => '',
        'mail' => '',
        'tel' => '',
        'enabled' => '1',
        'ID_STATUS' => 0,
        'ID_STATUS2' => 0,
        'locale' => I18n::locale(),
    ],
    'isNew' => $isNew,
    'message' => $message,
    'error' => $error,
    'isSuper' => $isSuper,
    'adminStatuses' => $adminStatuses ?: UsersRepository::statusNames(),
    'partnerStatuses' => $partnerStatuses,
    'permGroups' => $permGroups,
    'userPerms' => $userPerms,
    'copyUsers' => $copyUsers,
    'genPass' => $genPass,
    'favoritesCount' => $isNew ? 0 : count(UsersRepository::favoriteIds($id)),
    'extraHtml' => $extraHtml,
    'extraAccessHtml' => $extraAccessHtml,
    'breadcrumbs' => Breadcrumbs::withHome(array_values(array_filter([
        ['label' => t('core.menu.users'), 'href' => '/users'],
        $isNew ? null : ['label' => $displayName, 'href' => '/users/' . $id],
        ['label' => $isNew ? t('core.user_edit.title_new') : t('core.common.edit'), 'current' => true],
    ]))),
]);
