<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/LikesRepository.php');

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$row = NewsRepository::find($id);
if (!$row || !NewsRepository::canView($row, Auth::userId())) {
    if ($wantsJson) {
        AgentHttp::error(t('core.news.err.unavailable', ['id' => $id]), 404);
        return;
    }
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
            if (!empty($postResult['error'])) {
                $error = (string)$postResult['error'];
            }
            $handled = !empty($postResult['handled']);
            $redir = !empty($postResult['redirect']) ? (string)$postResult['redirect'] : null;

            if ($wantsJson) {
                if ($error !== '') {
                    AgentHttp::error($error, 400);
                    return;
                }
                if ($handled || $redir !== null) {
                    $fresh = NewsRepository::find($id) ?: $row;
                    $out = [
                        'ok' => true,
                        'action' => $action !== '' ? $action : 'handled',
                        'id' => $id,
                        'signed' => NewsRepository::hasSigned($id, $uid),
                        'needs_sign' => !empty($fresh['SIGN_REQ'])
                            && (int)($fresh['HAS_CHILDREN'] ?? 0) !== 1
                            && !NewsRepository::hasSigned($id, $uid),
                        'item' => NewsRepository::forAgent($fresh, true),
                        'redirect' => $redir,
                    ];
                    if (array_key_exists('comment_id', $postResult)) {
                        $out['comment_id'] = (int)$postResult['comment_id'];
                    }
                    if (array_key_exists('comment', $postResult)) {
                        $out['comment'] = $postResult['comment'];
                    }
                    AgentHttp::emit($out);
                    return;
                }
            }

            if ($redir !== null) {
                redirect($redir);
            }
            if ($handled) {
                // Module claimed the action (error already captured if any).
            } elseif ($action === 'like') {
                $likeType = (string)($_POST['like_type'] ?? 'base_news');
                $likeId = (int)($_POST['like_id'] ?? 0);
                if ($likeType === 'base_news' && $likeId === $id) {
                    LikesRepository::toggle('base_news', $id, $uid, (int)$row['ID_USER']);
                }
                if ($wantsJson) {
                    $newsLikes = LikesRepository::counts('base_news', [$id]);
                    $newsLiked = LikesRepository::likedByUser('base_news', [$id], $uid);
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'like',
                        'id' => $id,
                        'liked' => !empty($newsLiked[$id]),
                        'likes' => (int)($newsLikes[$id] ?? 0),
                    ]);
                    return;
                }
                redirect('/news/' . $id);
            } elseif ($action === 'favorite') {
                UsersRepository::toggleFavorite($uid, $id);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'favorite',
                        'id' => $id,
                        'favorite' => in_array($id, UsersRepository::favoriteIds($uid), true),
                    ]);
                    return;
                }
                redirect('/news/' . $id);
            } elseif ($action === 'delete' && NewsRepository::canEdit($row, $uid)) {
                NewsRepository::softDelete($id, $uid);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'delete',
                        'id' => $id,
                        'type' => (int)$row['TYPE'],
                    ]);
                    return;
                }
                redirect('/news?type=' . (int)$row['TYPE']);
            } elseif ($action === 'restore_version' && NewsRepository::canEdit($row, $uid)) {
                $logId = (int)($_POST['log_id'] ?? 0);
                NewsLogsRepository::restore($id, $logId, $uid);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'restore_version',
                        'id' => $id,
                        'log_id' => $logId,
                        'item' => NewsRepository::forAgent(NewsRepository::find($id), true),
                    ]);
                    return;
                }
                redirect('/news/' . $id . '?restored=1');
            } elseif (!$isFolder && strpos($action, 'checklist_') === 0) {
                $canCheck = NewsRepository::canEdit($row, $uid) || NewsRepository::canManageTicket($row, $uid);
                if (!$canCheck && !Auth::isSuperAdmin()) {
                    throw new RuntimeException(t('core.checklist.err.denied'));
                }
                $itemId = (int)($_POST['item_id'] ?? 0);
                $checklistOut = null;
                $newIds = [];
                if ($action === 'checklist_add') {
                    $newIds = NewsChecklistRepository::addMany($id, (string)($_POST['title'] ?? ''), $uid);
                    $checklistOut = $newIds !== []
                        ? NewsChecklistRepository::find((int)$newIds[count($newIds) - 1])
                        : null;
                } elseif ($action === 'checklist_toggle') {
                    $checklistOut = NewsChecklistRepository::toggle($itemId, $id);
                    if ($checklistOut === null) {
                        throw new RuntimeException(t('core.checklist.err.not_found'));
                    }
                } elseif ($action === 'checklist_rename') {
                    $checklistOut = NewsChecklistRepository::rename($itemId, $id, (string)($_POST['title'] ?? ''));
                    if ($checklistOut === null) {
                        throw new RuntimeException(t('core.checklist.err.not_found'));
                    }
                } elseif ($action === 'checklist_delete') {
                    if (!NewsChecklistRepository::delete($itemId, $id)) {
                        throw new RuntimeException(t('core.checklist.err.not_found'));
                    }
                } else {
                    throw new RuntimeException('unknown checklist action');
                }
                $progress = NewsChecklistRepository::progress($id);
                if ($wantsJson) {
                    $emit = [
                        'ok' => true,
                        'action' => $action,
                        'id' => $id,
                        'item' => $checklistOut ? NewsChecklistRepository::listForAgent([$checklistOut])[0] : null,
                        'checklist' => NewsChecklistRepository::listForAgent(NewsChecklistRepository::listFor($id)),
                        'checklist_progress' => $progress,
                    ];
                    if ($action === 'checklist_add') {
                        $emit['added'] = count($newIds);
                        $emit['added_ids'] = $newIds;
                    }
                    AgentHttp::emit($emit);
                    return;
                }
                redirect('/news/' . $id . '#checklist');
            } elseif ($wantsJson && $action !== '') {
                AgentHttp::error('unknown action', 400);
                return;
            }
        }
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
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
if (!empty($_GET['restored'])) {
    $message = t('core.news_item.versions.restored');
}

// Folders: redirect to list view inside folder (JSON returns folder meta + list hint)
if ($isFolder && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_GET['view'])) {
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'is_folder' => true,
            'item' => NewsRepository::forAgent($row, true),
            'list_path' => '/news?type=' . (int)$row['TYPE'] . '&pid=' . $id,
        ]);
        return;
    }
    redirect('/news?type=' . (int)$row['TYPE'] . '&pid=' . $id);
}

$statuses = NewsRepository::statuses();
$status = $statuses[(int)$row['STATUS']] ?? null;
$respIds = NewsRepository::parseUserList((string)($row['USERS_RESP'] ?? ''));
UsersRepository::preload($respIds);
$favIds = UsersRepository::favoriteIds($uid);
$isFav = in_array($id, $favIds, true);
$canManageTicket = NewsRepository::canManageTicket($row, $uid);
$canEdit = NewsRepository::canEdit($row, $uid);
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

$versionLogs = NewsLogsRepository::listFor($id);
$did = (int)($_GET['did'] ?? 0);
$activeLog = null;
$bodyHtml = (string)$row['BIG'];
$showingVersion = false;
if ($did > 0) {
    foreach ($versionLogs as $log) {
        if ((int)$log['ID'] === $did) {
            $activeLog = $log;
            $bodyHtml = (string)$log['CONTENT'];
            $showingVersion = true;
            break;
        }
    }
    if (!$activeLog) {
        $did = 0;
    }
}
$versionUserIds = [(int)$row['ID_USER_UPDATE'], (int)$row['ID_USER']];
foreach ($versionLogs as $log) {
    $versionUserIds[] = (int)$log['ID_USER'];
}
$inArchiveFolder = !$isFolder && NewsRepository::isArchiveFolderId((int)$row['PID']);
$needsSign = !$isFolder
    && !empty($row['SIGN_REQ'])
    && !NewsRepository::hasSigned($id, $uid);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$isFolder) {
    NewsRepository::markViewed($id, $uid);
}
$viewers = NewsRepository::viewerIds($id);
UsersRepository::preload(array_merge(
    [(int)$row['ID_USER'], (int)$row['ID_CURATOR']],
    $viewers,
    $versionUserIds
));

$newsLikes = LikesRepository::counts('base_news', [$id]);
$newsLiked = LikesRepository::likedByUser('base_news', [$id], $uid);

$contractLocked = (int)$row['TYPE'] === 2
    && class_exists('ContractsRepository', false)
    && ModuleManager::isEnabled('contracts')
    && ContractsRepository::ticketHasActiveContract($id);

// JSON agents use paged CommentsRepository::listForAgent — skip full-tree meta load.
if ($wantsJson) {
    $commentCount = 0;
    if (!$isFolder && class_exists('CommentsRepository', false)) {
        $counts = CommentsRepository::countsForNews([$id]);
        $commentCount = (int)($counts[$id] ?? 0);
    }
    $meta = ['html' => '', 'actions_html' => '', 'comment_count' => $commentCount];
} else {
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
}

if ($wantsJson) {
    $versions = [];
    foreach ($versionLogs as $log) {
        $versions[] = [
            'id' => (int)$log['ID'],
            'id_user' => (int)$log['ID_USER'],
            'dt_create' => (int)$log['DT_CREATE'],
            'dt_update' => (int)$log['DT_UPDATE'],
        ];
    }

    // Agent comment paging: default roots only, limited + excerpt (avoid context flood).
    // comments=0 → omit list; comments_pid → drill into replies; comments_id → one row.
    $wantComments = !isset($_GET['comments']) || (string)$_GET['comments'] !== '0';
    $includeHidden = Auth::isSuperAdmin();
    $commentsPage = [
        'items' => [],
        'total' => 0,
        'returned' => 0,
        'limit' => 0,
        'offset' => 0,
        'pid' => 0,
        'order' => 'desc',
        'excerpt' => 500,
        'has_more' => false,
        'next_offset' => null,
    ];
    if ($wantComments && class_exists('CommentsRepository', false) && !$isFolder) {
        $cLimit = isset($_GET['comments_limit']) ? (int)$_GET['comments_limit'] : 20;
        $cOffset = isset($_GET['comments_offset']) ? (int)$_GET['comments_offset'] : 0;
        $cPid = isset($_GET['comments_pid']) ? (int)$_GET['comments_pid'] : 0;
        $cOrder = (string)($_GET['comments_order'] ?? ($cPid > 0 ? 'asc' : 'desc'));
        $cExcerpt = isset($_GET['comments_excerpt']) ? (int)$_GET['comments_excerpt'] : 500;
        $cId = isset($_GET['comments_id']) ? (int)$_GET['comments_id'] : 0;
        $commentsPage = CommentsRepository::listForAgent($id, [
            'pid' => $cPid,
            'limit' => $cLimit,
            'offset' => $cOffset,
            'order' => $cOrder,
            'excerpt' => $cExcerpt,
            'include_hidden' => $includeHidden,
            'id' => $cId,
        ]);
    }

    $withBody = !isset($_GET['body']) || (string)$_GET['body'] !== '0';
    $itemPayload = NewsRepository::forAgent($row, $withBody);
    $checklistItems = !$isFolder ? NewsChecklistRepository::listFor($id) : [];
    $checklistProgress = !$isFolder ? NewsChecklistRepository::progress($id) : ['done' => 0, 'total' => 0, 'pct' => 0];

    AgentHttp::emit([
        'ok' => true,
        'item' => $itemPayload,
        'needs_sign' => $needsSign,
        'signed' => NewsRepository::hasSigned($id, $uid),
        'in_archive_folder' => $inArchiveFolder,
        'can_edit' => $canEdit,
        'can_manage_ticket' => $canManageTicket,
        'is_favorite' => $isFav,
        'likes' => (int)($newsLikes[$id] ?? 0),
        'liked' => !empty($newsLiked[$id]),
        'viewers' => $viewers,
        'comment_count' => (int)($meta['comment_count'] ?? 0),
        'comments' => $commentsPage['items'],
        'comments_meta' => [
            'included' => $wantComments,
            'total' => $commentsPage['total'],
            'returned' => $commentsPage['returned'],
            'limit' => $commentsPage['limit'],
            'offset' => $commentsPage['offset'],
            'pid' => $commentsPage['pid'],
            'order' => $commentsPage['order'],
            'excerpt' => $commentsPage['excerpt'],
            'has_more' => $commentsPage['has_more'],
            'next_offset' => $commentsPage['next_offset'],
            'hint' => 'Default: top-level only. Drill: comments_pid={id}. Page: comments_offset. Skip: comments=0. Full text: comments_excerpt=0. Omit body: body=0.',
        ],
        'checklist' => NewsChecklistRepository::listForAgent($checklistItems),
        'checklist_progress' => $checklistProgress,
        'versions' => $versions,
        'active_log_id' => $did > 0 ? $did : null,
        'contract_locked' => $contractLocked,
        'status' => $status,
        'users_resp' => $respIds,
    ]);
}

$checklistItems = !$isFolder ? NewsChecklistRepository::listFor($id) : [];
$checklistProgress = !$isFolder ? NewsChecklistRepository::progress($id) : ['done' => 0, 'total' => 0, 'pct' => 0];
$canChecklist = !$isFolder && (
    $canEdit || $canManageTicket || Auth::isSuperAdmin()
);

View::render('news_item', [
    'title' => (string)$row['NAME'],
    'row' => $row,
    'status' => $status,
    'statuses' => $statuses,
    'canEdit' => $canEdit,
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
    'versionLogs' => $versionLogs,
    'activeLogId' => $did,
    'activeLog' => $activeLog,
    'bodyHtml' => $bodyHtml,
    'showingVersion' => $showingVersion,
    'inArchiveFolder' => $inArchiveFolder,
    'needsSign' => $needsSign,
    'checklistItems' => $checklistItems,
    'checklistProgress' => $checklistProgress,
    'canChecklist' => $canChecklist,
]);
