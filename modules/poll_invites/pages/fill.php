<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$invite = PollInvitesRepository::find($id);
if (!$invite) {
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('poll_invites.err.not_found', ['id' => $id]),
    ]);
    return;
}

$message = '';
$error = '';

try {
    PollInvitesRepository::assertCanOpen($invite);
} catch (Throwable $e) {
    http_response_code(403);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => $e->getMessage(),
    ]);
    return;
}

$tree = PollsRepository::buildWalkTree((int)$invite['id_poll']);
$dataOpts = PollAnswersCodec::decode((string)($invite['data_opts'] ?? ''));
$dataText = PollAnswersCodec::decode((string)($invite['data_text'] ?? ''));
/** @var array<int|string,string> $dataText */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!empty($_POST['sign_supervisor'])) {
            PollInvitesRepository::signSupervisor($id);
            $invite = PollInvitesRepository::find($id) ?: $invite;
            $message = t('core.common.saved');
        } else {
            $applied = PollWalkEngine::applyPost($tree['questions'], $dataOpts, $_POST, $dataText);
            $dataOpts = $applied['data_opts'];
            $dataText = $applied['data_text'];
            $state = PollWalkEngine::walk($tree['questions'], $dataOpts, $tree['first_qid']);
            $forceComplete = !empty($_POST['complete']);
            $complete = $forceComplete || $state['done'];
            PollInvitesRepository::saveAnswers(
                $id,
                $dataOpts,
                $dataText,
                $complete,
                $applied['needs_sign'] || $state['needs_sign'],
                'staff'
            );
            $invite = PollInvitesRepository::find($id) ?: $invite;
            $dataOpts = PollAnswersCodec::decode((string)($invite['data_opts'] ?? ''));
            $dataText = PollAnswersCodec::decode((string)($invite['data_text'] ?? ''));
            $message = t('core.common.saved');

            if (AgentHttp::wantsJson()) {
                AgentHttp::emit([
                    'ok' => true,
                    'invite_id' => $id,
                    'completed' => (int)$invite['dt_completed'] > 0,
                    'data_opts' => $dataOpts,
                ]);
                return;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$state = PollWalkEngine::walk($tree['questions'], $dataOpts, $tree['first_qid']);
$currentQ = null;
if ($state['next_qid'] > 0 && isset($tree['questions'][$state['next_qid']])) {
    $currentQ = $tree['questions'][$state['next_qid']];
}
$showHistory = !empty($tree['poll']['show_history']);

$extraStaffIds = [
    (int)($invite['id_proc_operator'] ?? 0),
    (int)($invite['id_proc_admin'] ?? 0),
];
$respRaw = (string)($invite['resp_users'] ?? '');
if ($respRaw !== '' && preg_match_all('/#(\d+)#/', $respRaw, $m)) {
    foreach ($m[1] as $rid) {
        $extraStaffIds[] = (int)$rid;
    }
}
$staffNames = class_exists('UsersRepository', false)
    ? UsersRepository::staffNamesIncluding($extraStaffIds)
    : [];
$staffMeta = PollInvitesRepository::staffScoreMeta($invite, $staffNames);

View::render('fill', [
    'title' => t('poll_invites.fill.title', ['id' => $id]),
    'invite' => $invite,
    'poll' => $tree['poll'],
    'state' => $state,
    'currentQ' => $currentQ,
    'dataText' => $dataText,
    'showHistory' => $showHistory,
    'staffMeta' => $staffMeta,
    'showAudioLinks' => true,
    'canSign' => Auth::capability('SUPERADMIN_ACCESS'),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_invites.menu.list'), 'href' => '/poll-invites'],
        ['label' => '#' . $id, 'href' => '/poll-invites/' . $id . '/edit'],
        ['label' => t('poll_invites.action.fill'), 'current' => true],
    ]),
], 'layout', 'poll_invites');
