<?php
declare(strict_types=1);

$path = request_path();
$isNew = ($path === '/news/new');
$id = $isNew ? 0 : (int)(Router::param('id') ?? 0);

$error = '';
$row = null;
if (!$isNew) {
    $row = NewsRepository::find($id);
    if (!$row || !NewsRepository::canEdit($row, Auth::userId())) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('core.news_edit.err.no_access', ['id' => $id])]);
        return;
    }
}

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
        'ACCESS_USER' => trim((string)($_POST['ACCESS_USER'] ?? '')),
        'PID' => (int)($_POST['PID'] ?? 0),
        'HAS_CHILDREN' => !empty($_POST['HAS_CHILDREN']) ? 1 : 0,
        'USERS_RESP' => '',
    ];
    if (isset($_POST['users_resp']) && is_array($_POST['users_resp'])) {
        $ids = array_filter(array_map('intval', $_POST['users_resp']));
        $payload['USERS_RESP'] = $ids ? '#' . implode('#,#', $ids) . '#' : '';
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
    if ($payload['HAS_CHILDREN']) {
        $payload['COMMENTS_LOCKED'] = 1;
    }
    if ($payload['NAME'] === '') {
        $error = t('core.news_edit.err.name_required');
        $row = array_merge($row ?: [], $payload);
    } else {
        try {
            if ($isNew) {
                if ($payload['TYPE'] === 2 && (int)$payload['STATUS'] === 0) {
                    $payload['STATUS'] = 1;
                }
                if ($payload['TYPE'] === 2 && (int)$payload['ID_CURATOR'] === 0) {
                    $payload['ID_CURATOR'] = Auth::userId();
                }
                $newId = NewsRepository::create($payload, Auth::userId());
                if (!empty($payload['HAS_CHILDREN'])) {
                    redirect('/news?type=' . (int)$payload['TYPE'] . '&pid=' . $newId);
                }
                redirect('/news/' . $newId);
            }
            NewsRepository::update($id, $payload, Auth::userId());
            redirect('/news/' . $id);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $row = array_merge($row ?: [], $payload);
        }
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
        'ACCESS_USER' => '',
        'PID' => $defaultPid,
        'HAS_CHILDREN' => 0,
        'USERS_RESP' => '',
    ];
}

$users = UsersRepository::list(['enabled' => '1', 'limit' => 200]);
$folders = NewsRepository::folderOptions((int)$row['TYPE'], $id);

View::render('news_edit', [
    'title' => $isNew ? t('core.news_edit.title_new') : t('core.news_edit.title_edit'),
    'isNew' => $isNew,
    'row' => $row,
    'error' => $error,
    'statuses' => NewsRepository::statuses(),
    'users' => $users['items'],
    'folders' => $folders,
    'canDelete' => !$isNew && NewsRepository::canEdit($row, Auth::userId()),
]);
