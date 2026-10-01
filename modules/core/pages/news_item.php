<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/LikesRepository.php');

$id = (int)(Router::param('id') ?? 0);
$row = NewsRepository::find($id);
if (!$row || !NewsRepository::canView($row, Auth::userId())) {
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.news.err.unavailable', ['id' => $id])]);
    return;
}

$uid = Auth::userId();
$message = '';
$error = '';
$isFolder = (int)$row['HAS_CHILDREN'] === 1;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        $postResult = EventBus::emit('core.news_item.handle_post', [
            'handled' => false,
            'action' => $action,
            'row' => $row,
            'id' => $id,
            'uid' => $uid,
            'is_folder' => $isFolder,
            'post' => $_POST,
            'error' => '',
            'redirect' => null,
        ]);
        if (is_array($postResult)) {
            if (!empty($postResult['row']) && is_array($postResult['row'])) {
                $row = $postResult['row'];
            }
            if (!empty($postResult['redirect'])) {
                redirect((string)$postResult['redirect']);
            }
            if (!empty($postResult['error'])) {
                $error = (string)$postResult['error'];
            }
            if (!empty($postResult['handled'])) {
                // Module claimed the action (error already captured if any).
            } elseif ($action === 'like') {
                $likeType = (string)($_POST['like_type'] ?? 'base_news');
                $likeId = (int)($_POST['like_id'] ?? 0);
                if ($likeType === 'base_news' && $likeId === $id) {
                    LikesRepository::toggle('base_news', $id, $uid, (int)$row['ID_USER']);
                }
                redirect('/news/' . $id);
            } elseif ($action === 'favorite') {
                UsersRepository::toggleFavorite($uid, $id);
                redirect('/news/' . $id);
            } elseif ($action === 'delete' && NewsRepository::canEdit($row, $uid)) {
                NewsRepository::softDelete($id, $uid);
                redirect('/news?type=' . (int)$row['TYPE']);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $row = NewsRepository::find($id) ?: $row;
}

if (!empty($_GET['ok'])) {
    $message = t('core.common.saved');
}
if (!empty($_GET['err'])) {
    $error = (string)$_GET['err'];
}

// Folders: redirect to list view inside folder
if ($isFolder && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_GET['view'])) {
    redirect('/news?type=' . (int)$row['TYPE'] . '&pid=' . $id);
}

$statuses = NewsRepository::statuses();
$status = $statuses[(int)$row['STATUS']] ?? null;
$respIds = NewsRepository::parseUserList((string)($row['USERS_RESP'] ?? ''));
UsersRepository::preload($respIds);
$favIds = UsersRepository::favoriteIds($uid);
$isFav = in_array($id, $favIds, true);
$canManageTicket = NewsRepository::canManageTicket($row, $uid);
$staff = ['items' => []];
if ($canManageTicket && (int)$row['TYPE'] === 2) {
    $staff = UsersRepository::list(['enabled' => '1', 'limit' => 200]);
}
$crumbsRaw = NewsRepository::breadcrumbs((int)$row['PID']);
$typeLabel = NewsRepository::typeLabel((int)$row['TYPE']);
$typeHref = '/news?type=' . (int)$row['TYPE'];
$breadcrumbs = [
    [
        'label' => t('core.menu.news'),
        'href' => $typeHref,
    ],
    [
        'label' => $typeLabel,
        'href' => $typeHref,
    ],
];
foreach ($crumbsRaw as $c) {
    $breadcrumbs[] = [
        'label' => (string)$c['NAME'],
        'href' => '/news?type=' . (int)$c['TYPE'] . '&pid=' . (int)$c['ID'],
    ];
}
$breadcrumbs[] = [
    'label' => (string)$row['NAME'],
    'current' => true,
];
$breadcrumbs = Breadcrumbs::withHome($breadcrumbs);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$isFolder) {
    NewsRepository::markViewed($id, $uid);
}
$viewers = NewsRepository::viewerIds($id);
UsersRepository::preload(array_merge(
    [(int)$row['ID_USER'], (int)$row['ID_CURATOR']],
    $viewers
));

$newsLikes = LikesRepository::counts('base_news', [$id]);
$newsLiked = LikesRepository::likedByUser('base_news', [$id], $uid);

$contractLocked = (int)$row['TYPE'] === 2
    && class_exists('ContractsRepository', false)
    && ModuleManager::isEnabled('contracts')
    && ContractsRepository::ticketHasActiveContract($id);

$meta = EventBus::emit('core.news_item.meta', [
    'html' => '',
    'actions_html' => '',
    'row' => $row,
    'uid' => $uid,
    'is_folder' => $isFolder,
    'comment_count' => 0,
]);
if (!is_array($meta)) {
    $meta = ['html' => '', 'actions_html' => '', 'comment_count' => 0];
}

View::render('news_item', [
    'title' => (string)$row['NAME'],
    'row' => $row,
    'status' => $status,
    'statuses' => $statuses,
    'canEdit' => NewsRepository::canEdit($row, $uid),
    'canManageTicket' => $canManageTicket,
    'message' => $message,
    'error' => $error,
    'newsLikeCount' => $newsLikes[$id] ?? 0,
    'newsLiked' => !empty($newsLiked[$id]),
    'respIds' => $respIds,
    'isFavorite' => $isFav,
    'staff' => $staff['items'],
    'breadcrumbs' => $breadcrumbs,
    'breadcrumbs_aria' => t('core.news.folder_aria'),
    'uid' => $uid,
    'isFolder' => $isFolder,
    'viewers' => $viewers,
    'contractLocked' => $contractLocked,
    'metaHtml' => (string)($meta['html'] ?? ''),
    'metaActionsHtml' => (string)($meta['actions_html'] ?? ''),
    'commentsMeta' => $meta,
]);
