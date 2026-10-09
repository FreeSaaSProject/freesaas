<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$now = time();
$todayEnd = (int)mktime(23, 59, 59, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
$row = [
    'id' => 0,
    'operator_id' => (int)($_GET['operator_id'] ?? 0),
    'badge' => '',
    'dt_start' => (int)mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now)),
    'dt_end' => $todayEnd,
    'dt_create' => 0,
    'creator_id' => 0,
];

if (!$isNew) {
    $found = BadgeAssignmentsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('audio.assignments.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('audio.assignments.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
$listUrl = '/audio/assignments';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'delete') {
            if ($isNew) {
                throw new RuntimeException(t('audio.assignments.err.not_found', ['id' => $id]));
            }
            BadgeAssignmentsRepository::delete($id);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => 'delete', 'id' => $id]);
                return;
            }
            redirect($listUrl . '?deleted=1');
            return;
        }
        $data = BadgeAssignmentsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = BadgeAssignmentsRepository::create($data, Auth::userId());
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => BadgeAssignmentsRepository::find($newId),
                ]);
                return;
            }
            redirect('/audio/assignments/' . $newId . '/edit?saved=1');
            return;
        }
        BadgeAssignmentsRepository::update($id, $data);
        $row = BadgeAssignmentsRepository::find($id) ?: array_merge($row, $data);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, [
            'operator_id' => (int)($_POST['operator_id'] ?? $row['operator_id']),
            'badge' => (string)($_POST['badge'] ?? $row['badge']),
            'dt_start' => BadgeAssignmentsRepository::parseDateTimeLocal((string)($_POST['dt_start'] ?? ''))
                ?: (int)$row['dt_start'],
            'dt_end' => BadgeAssignmentsRepository::parseDateTimeLocal((string)($_POST['dt_end'] ?? ''))
                ?: (int)$row['dt_end'],
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staff = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}

$badgeOptions = [
    '0' => t('audio.badge.opt.not_on_shift'),
];
foreach (BadgesRepository::listAll(false) as $b) {
    $code = trim((string)($b['CODE'] ?? ''));
    if ($code === '' || $code === '0') {
        continue;
    }
    $name = trim((string)($b['NAME'] ?? ''));
    $badgeOptions[$code] = $name !== '' && $name !== $code ? ($code . ' — ' . $name) : $code;
}
$currentBadge = (string)$row['badge'];
if ($currentBadge !== '' && !isset($badgeOptions[$currentBadge])) {
    $badgeOptions[$currentBadge] = $currentBadge;
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'staff' => $staff,
        'badge_options' => $badgeOptions,
    ]);
    return;
}

View::render('assignments_edit', [
    'title' => $isNew
        ? t('audio.assignments.edit.title_new')
        : t('audio.assignments.edit.title', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio.assignments.title'), 'href' => $listUrl],
        [
            'label' => $isNew
                ? t('audio.assignments.edit.heading_new')
                : ('#' . $id),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'staff' => $staff,
    'badgeOptions' => $badgeOptions,
    'dtStartValue' => BadgeAssignmentsRepository::formatDateTimeLocal((int)$row['dt_start']),
    'dtEndValue' => BadgeAssignmentsRepository::formatDateTimeLocal((int)$row['dt_end']),
    'message' => $message,
    'error' => $error,
    'listUrl' => $listUrl,
], 'layout', 'audio');
