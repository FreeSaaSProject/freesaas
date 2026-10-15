<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$canSeeOthers = VacationsRepository::canSeeOthers();

$row = [
    'user_id' => $canSeeOthers ? (int)($_GET['user_id'] ?? Auth::userId()) : Auth::userId(),
    'id_type' => (int)($_GET['id_type'] ?? 3),
    'dt_start' => time(),
    'dt_end' => time() + 14 * 86400,
    'descr' => '',
    'direction' => 1,
    'days_paid' => 14,
    'days_unpaid' => 0,
    'show_user' => 1,
    'sign_req' => 1,
    'user_sign' => 0,
    'operator_id' => Auth::userId(),
    'dt_create' => time(),
];

if (!$isNew) {
    $found = VacationsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('vacations.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('vacations.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    if (!$canSeeOthers && (int)$found['user_id'] !== Auth::userId()) {
        if ($wantsJson) {
            AgentHttp::error(t('vacations.err.forbidden'), 403);
            return;
        }
        http_response_code(403);
        View::render('error', [
            'title' => t('vacations.err.forbidden'),
            'message' => t('vacations.err.forbidden'),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';


if (!$isNew && EntityDelete::tryHandle('vacations', $id, '/vacations', [
    'stay_path' => '/vacations/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canSeeOthers) {
            $_POST['user_id'] = (string)Auth::userId();
        }
        $data = VacationsRepository::dataFromPost($_POST, $isNew ? null : $row);
        if ($isNew) {
            $newId = VacationsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'saved' => true]);
                return;
            }
            redirect('/vacations/' . $newId . '/edit?saved=1');
            return;
        }
        VacationsRepository::update($id, $data);
        $row = VacationsRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'saved' => true]);
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
            'user_id' => (int)($_POST['user_id'] ?? $row['user_id']),
            'id_type' => (int)($_POST['id_type'] ?? $row['id_type']),
            'dt_start' => (string)($_POST['dt_start'] ?? $row['dt_start']),
            'dt_end' => (string)($_POST['dt_end'] ?? $row['dt_end']),
            'descr' => (string)($_POST['descr'] ?? $row['descr']),
            'direction' => (int)($_POST['direction'] ?? $row['direction']),
            'days_paid' => (int)($_POST['days_paid'] ?? $row['days_paid']),
            'days_unpaid' => (int)($_POST['days_unpaid'] ?? $row['days_unpaid']),
            'show_user' => !empty($_POST['show_user']) ? 1 : 0,
            'sign_req' => !empty($_POST['sign_req']) ? 1 : 0,
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

$dtStart = is_numeric($row['dt_start'] ?? null)
    ? date('Y-m-d', (int)$row['dt_start'])
    : (string)($row['dt_start'] ?? '');
$dtEnd = is_numeric($row['dt_end'] ?? null)
    ? date('Y-m-d', (int)$row['dt_end'])
    : (string)($row['dt_end'] ?? '');

$canSelfSign = !$isNew
    && (int)$row['user_id'] === Auth::userId()
    && (int)$row['sign_req'] === 1
    && (int)$row['user_sign'] < 1;

$periodTypes = [3, 1];
if ($canSeeOthers) {
    $periodTypes[] = 2;
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'is_new' => $isNew,
        'staff' => $staff,
        'can_see_others' => $canSeeOthers,
        'period_types' => $periodTypes,
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew
        ? t('vacations.edit.title_new')
        : t('vacations.edit.title', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('vacations.menu.list'), 'href' => '/vacations'],
        [
            'label' => $isNew ? t('vacations.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'staff' => $staff,
    'canSeeOthers' => $canSeeOthers,
    'canSelfSign' => $canSelfSign,
    'dtStart' => $dtStart,
    'dtEnd' => $dtEnd,
    'message' => $message,
    'error' => $error,
], 'layout', 'vacations');
