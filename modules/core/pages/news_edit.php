<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$path = request_path();
$isNew = ($path === '/news/new');
$id = $isNew ? 0 : (int)(Router::param('id') ?? 0);

$error = '';
$row = null;
if (!$isNew) {
    $row = NewsRepository::find($id);
    if (!$row || !NewsRepository::canEdit($row, Auth::userId())) {
        if ($wantsJson) {
            AgentHttp::error(t('core.news_edit.err.no_access', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.news_edit.err.no_access', ['id' => $id])]);
        return;
    }
}

$childCount = $isNew ? 0 : NewsRepository::childCount($id);
$folderLocked = $childCount > 0;

$defaultType = (int)($_GET['type'] ?? ($row['TYPE'] ?? 2));
$defaultPid = (int)($_GET['pid'] ?? ($row['PID'] ?? 0));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $payload = [
        'NAME' => trim((string)($_POST['NAME'] ?? '')),
        'SHORT' => trim((string)($_POST['SHORT'] ?? '')),
        'BIG' => trim((string)($_POST['BIG'] ?? '')),
        'TYPE' => (int)($_POST['TYPE'] ?? $defaultType),
        'STATUS' => (int)($_POST['STATUS'] ?? 0),
        'ID_CURATOR' => (int)($_POST['ID_CURATOR'] ?? 0),
        'DT_DEADLINE' => 0,
        'ACCESS_GROUP' => NewsRepository::accessFromPost($_POST['ACCESS_GROUP'] ?? null),
        'ACCESS_GROUP2' => NewsRepository::accessFromPost($_POST['ACCESS_GROUP2'] ?? null),
        'ACCESS_USER' => NewsRepository::accessFromPost($_POST['ACCESS_USER'] ?? null),
        'PID' => (int)($_POST['PID'] ?? 0),
        'HAS_CHILDREN' => !empty($_POST['HAS_CHILDREN']) ? 1 : 0,
        'IGNORE_FOLDER' => !empty($_POST['IGNORE_FOLDER']) ? 1 : 0,
        'SIGN_REQ' => !empty($_POST['SIGN_REQ']) ? 1 : 0,
        'USERS_RESP' => '',
        'keep_signs' => !empty($_POST['keep_signs']),
    ];
    if ($folderLocked) {
        $payload['HAS_CHILDREN'] = 1;
    }
    if (!empty($payload['HAS_CHILDREN'])) {
        $payload['IGNORE_FOLDER'] = !empty($_POST['IGNORE_FOLDER']) ? 1 : 0;
        $payload['SIGN_REQ'] = 0;
        $payload['STATUS'] = 0;
        $payload['ID_CURATOR'] = 0;
        $payload['USERS_RESP'] = '';
        $payload['DT_DEADLINE'] = 0;
    } else {
        $payload['IGNORE_FOLDER'] = 0;
    }
    if ((int)$payload['TYPE'] === 3) {
        $payload['ACCESS_GROUP'] = '';
        $payload['ACCESS_GROUP2'] = '';
    }
    // Legacy free-text ACCESS_USER for TYPE=3 if multi not posted
    if ((int)$payload['TYPE'] === 3 && $payload['ACCESS_USER'] === '' && isset($_POST['ACCESS_USER']) && is_string($_POST['ACCESS_USER'])) {
        $payload['ACCESS_USER'] = NewsRepository::accessFromPost($_POST['ACCESS_USER']);
    }
    if (empty($payload['HAS_CHILDREN']) && (int)$payload['TYPE'] === 2) {
        if (isset($_POST['users_resp']) && is_array($_POST['users_resp'])) {
            $ids = array_filter(array_map('intval', $_POST['users_resp']));
            $payload['USERS_RESP'] = $ids ? '#' . implode('#,#', $ids) . '#' : '';
        } elseif (isset($_POST['users_resp']) && is_string($_POST['users_resp'])) {
            $payload['USERS_RESP'] = NewsRepository::accessFromPost($_POST['users_resp']);
        } elseif (!empty($_POST['users_resp_present'])) {
            $payload['USERS_RESP'] = '';
        }
        $dl = trim((string)($_POST['DT_DEADLINE'] ?? ''));
        if ($dl !== '') {
            $ts = strtotime($dl . ' 23:59:59');
            if ($ts) {
                $payload['DT_DEADLINE'] = $ts;
            }
        }
    }
    if ($payload['HAS_CHILDREN']) {
        $payload['COMMENTS_LOCKED'] = 1;
    }
    $ugcReject = html_ugc_reject_reason((string)$payload['BIG']);
    if ($payload['NAME'] === '') {
        $error = t('core.news_edit.err.name_required');
        $row = array_merge($row ?: [], $payload);
    } elseif ((int)$payload['TYPE'] === 3 && $payload['ACCESS_USER'] === '') {
        $error = t('core.news_edit.err.recipient_required');
        $row = array_merge($row ?: [], $payload);
    } elseif ($ugcReject !== null) {
        $error = t('core.news_edit.err.unsafe_html');
        $row = array_merge($row ?: [], $payload);
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    } else {
        try {
            if ($isNew) {
                if ($payload['TYPE'] === 2 && empty($payload['HAS_CHILDREN']) && (int)$payload['STATUS'] === 0) {
                    $payload['STATUS'] = 1;
                }
                if ($payload['TYPE'] === 2 && empty($payload['HAS_CHILDREN']) && (int)$payload['ID_CURATOR'] === 0) {
                    $payload['ID_CURATOR'] = Auth::userId();
                }
                $newId = NewsRepository::create($payload, Auth::userId());
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'id' => $newId,
                        'saved' => true,
                        'is_folder' => !empty($payload['HAS_CHILDREN']),
                        'item' => NewsRepository::forAgent(NewsRepository::find($newId), true),
                    ]);
                    return;
                }
                if (!empty($payload['HAS_CHILDREN'])) {
                    $parentPid = (int)$payload['PID'];
                    if ($parentPid > 0) {
                        redirect('/news?type=' . (int)$payload['TYPE'] . '&pid=' . $parentPid);
                    }
                    redirect('/news?type=' . (int)$payload['TYPE'] . '&pid=' . $newId);
                }
                redirect('/news/' . $newId);
            }
            NewsRepository::update($id, $payload, Auth::userId());
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'saved' => true,
                    'is_folder' => !empty($payload['HAS_CHILDREN']),
                    'item' => NewsRepository::forAgent(NewsRepository::find($id), true),
                ]);
                return;
            }
            if (!empty($payload['HAS_CHILDREN'])) {
                $parentPid = (int)$payload['PID'];
                redirect('/news?type=' . (int)$payload['TYPE'] . ($parentPid > 0 ? '&pid=' . $parentPid : ''));
            }
            redirect('/news/' . $id);
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
                return;
            }
            $error = $e->getMessage();
            $row = array_merge($row ?: [], $payload);
        }
    }
    if ($wantsJson && $error !== '') {
        AgentHttp::error($error, 400);
        return;
    }
}

if ($isNew && !$row) {
    $row = [
        'NAME' => '',
        'SHORT' => '',
        'BIG' => '',
        'TYPE' => $defaultType,
        'STATUS' => $defaultType === 2 ? 1 : 0,
        'ID_CURATOR' => $defaultType === 2 ? Auth::userId() : 0,
        'DT_DEADLINE' => 0,
        'ACCESS_GROUP' => '',
        'ACCESS_GROUP2' => '',
        'ACCESS_USER' => '',
        'PID' => $defaultPid,
        'HAS_CHILDREN' => 0,
        'IGNORE_FOLDER' => 0,
        'SIGN_REQ' => 0,
        'USERS_RESP' => '',
    ];
}

$users = UsersRepository::list(['enabled' => '1', 'limit' => 200]);
$folders = NewsRepository::folderOptions((int)$row['TYPE'], $id);
$staffGroups = UsersRepository::statusNamesFiltered(false);
$partnerGroups = UsersRepository::statusNamesFiltered(true);

if ($wantsJson) {
    $folderOpts = [];
    foreach ($folders as $f) {
        $folderOpts[] = [
            'id' => (int)$f['ID'],
            'name' => (string)$f['NAME'],
            'pid' => (int)($f['PID'] ?? 0),
        ];
    }
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => NewsRepository::forAgent($row, true),
        'folder_locked' => $folderLocked,
        'child_count' => $childCount,
        'statuses' => NewsRepository::statuses(),
        'folders' => $folderOpts,
        'staff_groups' => $staffGroups,
        'partner_groups' => $partnerGroups,
        'can_delete' => !$isNew && NewsRepository::canEdit($row, Auth::userId()),
        'fields' => [
            'access_group' => 'staff status ids (ACCESS_GROUP)',
            'access_group2' => 'partner status ids (ACCESS_GROUP2); empty on TYPE=3',
            'access_user' => 'user ids; required for TYPE=3 DM',
            'sign_req' => '1 = require acknowledgment comment (not for folders)',
            'ignore_folder' => '1 = archive folder (HAS_CHILDREN only)',
            'has_children' => '1 = folder; locks ticket fields and SIGN_REQ off',
            'keep_signs' => '1 = do not clear views/signs when body changes',
        ],
    ]);
}

View::render('news_edit', [
    'title' => $isNew ? t('core.news_edit.title_new') : t('core.news_edit.title_edit'),
    'isNew' => $isNew,
    'row' => $row,
    'error' => $error,
    'statuses' => NewsRepository::statuses(),
    'users' => $users['items'],
    'folders' => $folders,
    'staffGroups' => $staffGroups,
    'partnerGroups' => $partnerGroups,
    'canDelete' => !$isNew && NewsRepository::canEdit($row, Auth::userId()),
    'folderLocked' => $folderLocked,
    'childCount' => $childCount,
]);
