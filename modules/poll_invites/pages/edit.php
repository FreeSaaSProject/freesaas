<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = PollInvitesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('poll_invites.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $presetPoll = (int)($_GET['id_poll'] ?? 0);
    $row = [
        'id_poll' => $presetPoll,
        'id_user' => 0,
        'bonus_auto' => 1,
        'bonus_value' => 0,
        'is_special' => 0,
        'is_special_friend' => 0,
        'dt_avail' => time(),
        'dt_expire' => time() + 72 * 3600,
    ];
    if ($presetPoll > 0) {
        $p = PollsRepository::find($presetPoll);
        if ($p && (int)$p['el_type'] === PollsRepository::EL_POLL) {
            $row['bonus_auto'] = (int)$p['bonus_auto'];
            $row['bonus_value'] = (int)$p['bonus_default'];
            $row['is_special'] = (int)$p['is_special'];
            $row['is_special_friend'] = (int)$p['is_special_friend'];
        }
    }
}

$message = '';
$error = '';


if (!$isNew && EntityDelete::tryHandle('poll_invites', $id, '/poll-invites', [
    'stay_path' => '/poll-invites/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = PollInvitesRepository::dataFromPost($_POST, $isNew ? null : $row);
        if ($isNew) {
            $newId = PollInvitesRepository::create($data);
            if (AgentHttp::wantsJson()) {
                AgentHttp::emit(['ok' => true, 'id' => $newId]);
                return;
            }
            redirect('/poll-invites/' . $newId . '/edit?saved=1');
            return;
        }
        PollInvitesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = PollInvitesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$pollNames = PollsRepository::rootNames(false);
$extraStaffIds = [
    (int)($row['id_operator_linked'] ?? 0),
    (int)($row['id_proc_operator'] ?? 0),
    (int)($row['id_proc_admin'] ?? 0),
];
$respRaw = (string)($row['resp_users'] ?? '');
if ($respRaw !== '' && preg_match_all('/#(\d+)#/', $respRaw, $m)) {
    foreach ($m[1] as $rid) {
        $extraStaffIds[] = (int)$rid;
    }
}
$staff = class_exists('UsersRepository', false)
    ? UsersRepository::staffNamesIncluding($extraStaffIds)
    : [];
$staffMeta = $isNew ? [
    'id_proc_operator' => 0,
    'id_proc_admin' => 0,
    'executor_name' => '',
    'admin_name' => '',
    'score_staff' => null,
    'score_service' => null,
    'has_scores' => false,
    'has_staff' => false,
] : PollInvitesRepository::staffScoreMeta($row, $staff);

View::render('edit', [
    'title' => $isNew
        ? t('poll_invites.edit.title_new')
        : t('poll_invites.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'pollNames' => $pollNames,
    'staff' => $staff,
    'staffMeta' => $staffMeta,
    'showAudioLinks' => true,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_invites.menu.list'), 'href' => '/poll-invites'],
        [
            'label' => $isNew ? t('poll_invites.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'poll_invites');
