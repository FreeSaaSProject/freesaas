<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/UsersRepository.php');

$id = (int)(Router::param('id') ?? 0);
$user = UsersRepository::findForEdit($id);
if (!$user) {
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.user.err.not_found', ['id' => $id])]);
    return;
}

$message = '';
$error = '';
$isSuper = Auth::isSuperAdmin();
$features = UsersRepository::editFeatures();
$permGroups = UsersRepository::availablePermissionKeys();

$adminStatuses = UsersRepository::statusNamesFiltered(false);
$partnerStatuses = UsersRepository::statusNamesFiltered(true);
$allStatuses = UsersRepository::statusNames();
$curRole = (int)$user['name_enabled'];
if ($curRole > 0 && !isset($adminStatuses[$curRole]) && isset($allStatuses[$curRole])) {
    $adminStatuses[$curRole] = $allStatuses[$curRole];
}
$copyUsers = UsersRepository::listForCopy();

$copyFrom = (int)($_GET['copyFrom'] ?? 0);
$userPerms = UsersRepository::decodePermissions($user['status'] ?? null);
if ($copyFrom > 0 && $copyFrom !== $id) {
    $src = UsersRepository::findForEdit($copyFrom);
    if ($src) {
        $userPerms = UsersRepository::decodePermissions($src['status'] ?? null);
        $message = t('core.user_edit.perms_copied', ['id' => $copyFrom]);
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
 * @param array<string,bool> $features
 * @return array<string,mixed>
 */
$buildData = static function (array $post, array $current, bool $super, array $features) use ($permGroups): array {
    $data = [
        'name' => trim((string)($post['name'] ?? '')),
        'mail' => trim((string)($post['mail'] ?? '')),
        'tel' => trim((string)($post['tel'] ?? '')),
        'enabled' => !empty($post['enabled']) ? '1' : '0',
        'name_enabled' => (int)($post['name_enabled'] ?? 0),
        'color' => trim((string)($post['color'] ?? '')),
        'color_text' => trim((string)($post['color_text'] ?? '')),
        'IS_HIDDEN' => !empty($post['IS_HIDDEN']) ? 1 : 0,
        'link_ok' => trim((string)($post['link_ok'] ?? '')),
        'ASSIST_TEAM' => !empty($post['ASSIST_TEAM']) ? 1 : 0,
        'NOT_IN_TEAM' => !empty($post['NOT_IN_TEAM']) ? 1 : 0,
    ];

    $localeRaw = I18n::normalize((string)($post['locale'] ?? ''));
    if ($localeRaw !== null) {
        $data['locale'] = $localeRaw;
    }

    if (Database::columnExists('base_users', 'STATUS2')) {
        $data['STATUS2'] = (int)($post['STATUS2'] ?? 0);
    }

    if (!empty($features['site_profile'])) {
        $data['photo'] = trim((string)($post['photo'] ?? ''));
        $data['site_descr'] = trim((string)($post['site_descr'] ?? ''));
    }

    if (!empty($features['employee_bot'])) {
        $data['telegram_chat_id'] = trim((string)($post['telegram_chat_id'] ?? ''));
        $data['max_user_id'] = trim((string)($post['max_user_id'] ?? ''));
        $data['BOT_NO_SEND'] = !empty($post['BOT_NO_SEND']) ? 1 : 0;
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

    if ($super) {
        $pass = (string)($post['password'] ?? '');
        if ($pass !== '') {
            $data['password_plain'] = $pass;
        }
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
        $data = $buildData($_POST, $user, $isSuper, $features);
        $saved = EventBus::emit('core.user_edit.save', [
            'post' => $_POST,
            'user' => $user,
            'data' => $data,
            'id' => $id,
        ]);
        if (is_array($saved) && isset($saved['data']) && is_array($saved['data'])) {
            $data = $saved['data'];
        }
        UsersRepository::update($id, $data);
        $message = t('core.user_edit.saved');
        $user = UsersRepository::findForEdit($id) ?: $user;
        $userPerms = UsersRepository::decodePermissions($user['status'] ?? null);
        if (Auth::userId() === $id) {
            $_SESSION['permissions'] = $userPerms;
            $_SESSION['user_name'] = (string)$user['name'];
            Auth::setStatusContext((int)$user['name_enabled'], (int)($user['STATUS2'] ?? 0));
            if (!empty($data['locale'])) {
                I18n::applyUserChoice((string)$data['locale'], false);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$genPass = '';
if ($isSuper) {
    try {
        $genPass = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $genPass = substr(sha1((string)microtime(true)), 0, 8);
    }
}

$sections = EventBus::emit('core.user_edit.sections', [
    'html' => '',
    'html_access' => '',
    'user' => $user,
    'id' => $id,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';
$extraAccessHtml = is_array($sections) ? (string)($sections['html_access'] ?? '') : '';

$displayName = (string)($user['name'] ?: $user['login']);

View::render('user_edit', [
    'title' => t('core.user_edit.title', ['name' => $displayName]),
    'user' => $user,
    'message' => $message,
    'error' => $error,
    'isSuper' => $isSuper,
    'features' => $features,
    'adminStatuses' => $adminStatuses ?: UsersRepository::statusNames(),
    'partnerStatuses' => $partnerStatuses,
    'permGroups' => $permGroups,
    'userPerms' => $userPerms,
    'copyUsers' => $copyUsers,
    'genPass' => $genPass,
    'favoritesCount' => count(UsersRepository::favoriteIds($id)),
    'extraHtml' => $extraHtml,
    'extraAccessHtml' => $extraAccessHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.users'), 'href' => '/users'],
        ['label' => $displayName, 'href' => '/users/' . $id],
        ['label' => t('core.common.edit'), 'current' => true],
    ]),
]);
