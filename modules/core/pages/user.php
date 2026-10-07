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
$statuses = UsersRepository::statusNames();
$statusName = $statuses[(int)$user['ID_STATUS']] ?? ('#' . $user['ID_STATUS']);
$partnerStatusName = '';
$s2 = (int)($user['ID_STATUS2'] ?? 0);
if ($s2 > 0) {
    $partnerStatusName = $statuses[$s2] ?? ('#' . $s2);
}

$displayName = (string)($user['name'] ?: $user['login']);

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
    'profileChannelsHtml' => is_array($profile) ? (string)($profile['html_channels'] ?? '') : '',
    'profileFlagsHtml' => is_array($profile) ? (string)($profile['html_flags'] ?? '') : '',
    'profileSiteHtml' => is_array($profile) ? (string)($profile['html_site'] ?? '') : '',
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.users'), 'href' => '/users'],
        ['label' => $displayName, 'current' => true],
    ]),
]);
