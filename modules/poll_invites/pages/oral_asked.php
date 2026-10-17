<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if ($wantsJson) {
        AgentHttp::error(t('poll_invites.oral.err.method'), 405);
        return;
    }
    http_response_code(405);
    flash_set('error', t('poll_invites.oral.err.method'));
    redirect('/poll-invites');
    return;
}

$procId = (int)($_POST['id_proc'] ?? $_POST['idProc'] ?? 0);
$pollIdRaw = $_POST['id_poll'] ?? null;
$pollId = ($pollIdRaw !== null && $pollIdRaw !== '') ? (int)$pollIdRaw : null;
// Prefer oral_ret (calendar back-path). Ignore NavContext `ret` — it often carries
// /polls/settings from a prior visit and must not steal the post-mark redirect.
$ret = '';
if (class_exists('NavContext', false)) {
    $ret = NavContext::sanitize((string)($_POST['oral_ret'] ?? ''));
} else {
    $ret = trim((string)($_POST['oral_ret'] ?? ''));
    if ($ret === '' || ($ret[0] ?? '') !== '/') {
        $ret = '';
    }
}
if ($ret === '') {
    $ret = '/calendar';
}

$result = PollInvitesRepository::markOralAsked($procId, $pollId);

if ($wantsJson) {
    if (!$result['ok']) {
        AgentHttp::error((string)$result['error'], 400, [
            'id_proc' => $result['id_proc'],
            'id_poll' => $result['id_poll'],
        ]);
        return;
    }
    AgentHttp::emit([
        'ok' => true,
        'id_invite' => $result['id_invite'],
        'id_proc' => $result['id_proc'],
        'id_poll' => $result['id_poll'],
        'already' => !empty($result['already']),
        'message' => $result['message'],
    ]);
    return;
}

if (class_exists('NavContext', false)) {
    // Prevent redirect() from swapping target for a leftover NavContext ret=.
    NavContext::setReturnUrl('');
}

if (!$result['ok']) {
    flash_set('error', (string)$result['error']);
    redirect($ret);
    return;
}

flash_set('ok', (string)$result['message']);
redirect($ret);
