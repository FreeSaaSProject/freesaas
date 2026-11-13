<?php
declare(strict_types=1);

$code = (string)(Router::param('code') ?? '');
$invite = PollPublic::findByCode($code);
$personal = $invite !== null ? PollPublic::personal($invite) : ['skip_qids' => [], 'hints' => [], 'vars' => []];
$tree = $invite !== null ? PollPublic::treeFor($invite, $personal) : ['poll' => null, 'questions' => [], 'first_qid' => 0];

$wantsJson = AgentHttp::wantsJson();

if ($invite === null || $tree['poll'] === null || $tree['questions'] === []) {
    if ($wantsJson) {
        AgentHttp::error(t('poll_invites.public.not_found_title'), 404);
        return;
    }
    http_response_code(404);
    View::render('public', [
        'title' => t('poll_invites.public.not_found_title'),
        'mode' => 'not_found',
    ], 'layout_auth', 'poll_invites');
    return;
}

$id = (int)$invite['id'];
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!PollPublic::canAnswer($invite)) {
        $error = t('poll_invites.public.closed');
        if ($wantsJson) {
            AgentHttp::error($error, 409);
            return;
        }
    } else {
        // Every visible question is on the page: rebuild answers from this submit only (allows clearing).
        $applied = PollWalkEngine::applyPost($tree['questions'], [], $_POST, []);
        $walk = PollWalkEngine::walk($tree['questions'], $applied['data_opts'], $tree['first_qid'], $applied['data_text']);
        PollInvitesRepository::saveAnswersFromChannel(
            $id,
            $applied['data_opts'],
            $applied['data_text'],
            $walk['done'],
            PollPublic::SOURCE,
            'public link' . ($walk['done'] ? '' : ' (draft)')
        );
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'completed' => $walk['done'],
                'missing_qid' => $walk['done'] ? 0 : (int)$walk['next_qid'],
            ]);
            return;
        }
        flash_set($walk['done'] ? 'ok' : 'warn', $walk['done'] ? t('poll_invites.public.thanks') : t('poll_invites.public.incomplete'));
        redirect('/q/' . rawurlencode($code));
    }
}

$invite = PollInvitesRepository::find($id) ?? $invite;
$state = PollPublic::state($invite);
$vars = PollPublic::vars($invite, $personal);
$dataOpts = PollAnswersCodec::decode((string)($invite['data_opts'] ?? ''));
$dataText = PollAnswersCodec::decode((string)($invite['data_text'] ?? ''));
$walk = PollWalkEngine::walk($tree['questions'], $dataOpts, $tree['first_qid'], $dataText);
$missingQid = ((int)$invite['dt_started'] > 0 && !$walk['done']) ? (int)$walk['next_qid'] : 0;

if ($wantsJson) {
    $qs = [];
    foreach ($tree['questions'] as $qid => $q) {
        $opts = [];
        foreach ($q['_ANSWERS_'] ?? [] as $aid => $a) {
            if (!isset($a['enabled']) || (int)$a['enabled'] === 1) {
                $opts[] = ['id' => (int)$aid, 'name' => PollPublic::render((string)$a['name'], $vars)];
            }
        }
        $qs[] = [
            'qid' => (int)$qid,
            'name' => PollPublic::render((string)$q['name'], $vars),
            'hint' => PollPublic::render((string)($personal['hints'][(int)$qid] ?? $q['descr_site'] ?? ''), $vars),
            'multi' => !empty($q['multy_opts']),
            'text' => $opts === [] || (int)($q['req_text_len'] ?? 0) > 0,
            'options' => $opts,
        ];
    }
    AgentHttp::emit([
        'ok' => true,
        'state' => $state,
        'title' => PollPublic::render((string)$tree['poll']['name'], $vars),
        'intro' => PollPublic::render((string)($invite['text_admin_to_user'] ?? ''), $vars),
        'questions' => $qs,
        'answers' => ['opts' => $dataOpts, 'texts' => $dataText],
        'completed' => $walk['done'],
        'missing_qid' => $missingQid,
        'dt_expire' => (int)$invite['dt_expire'],
    ]);
    return;
}

View::render('public', [
    'title' => PollPublic::render((string)$tree['poll']['name'], $vars),
    'mode' => in_array($state, ['expired', 'not_yet'], true) ? $state : 'form',
    'state' => $state,
    'poll' => $tree['poll'],
    'questions' => $tree['questions'],
    'invite' => $invite,
    'personal' => $personal,
    'vars' => $vars,
    'dataOpts' => $dataOpts,
    'dataText' => $dataText,
    'missingQid' => $missingQid,
    'flash' => flash_take(),
    'error' => $error,
], 'layout_auth', 'poll_invites');
