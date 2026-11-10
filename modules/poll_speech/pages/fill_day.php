<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('poll_speech.err.method'), 405);
        return;
    }
    flash_set('error', t('poll_speech.err.method'));
    redirect('/poll-invites');
}

if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
    Csrf::deny('csrf');
    return;
}

$recreate = !empty($_POST['recreate']) || !empty($_GET['recreate']);
$day = trim((string)($_POST['day'] ?? $_GET['day'] ?? ''));
if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}

$ret = trim((string)($_POST['ret'] ?? $_GET['ret'] ?? ''));
$retOk = $ret !== '' && str_starts_with($ret, '/poll-invites');

$listRedirect = static function (string $day, string $progress = '') use ($ret, $retOk): void {
    if ($retOk) {
        redirect($ret);
        return;
    }
    $qs = ['day' => $day];
    if ($progress !== '') {
        $qs['progress'] = $progress;
    }
    redirect(ListQuery::url('/poll-invites', $qs));
};

if ($recreate) {
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        if (AgentHttp::wantsJson()) {
            AgentHttp::error(t('poll_speech.err.forbidden'), 403);
            return;
        }
        flash_set('error', t('poll_speech.err.forbidden'));
        $listRedirect($day);
        return;
    }
    $result = PollSpeechFiller::recreateAllFromSpeech(['date' => $day]);
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(array_merge(['ok' => !empty($result['ok']), 'mode' => 'recreate'], $result));
        return;
    }
    $msg = t('poll_speech.recreate.done', [
        'date' => $day,
        'deleted' => (string)(int)$result['deleted'],
        'created' => (string)(int)$result['created'],
        'filled' => (string)(int)$result['filled'],
        'skipped' => (string)(int)$result['skipped'],
    ]);
    if (!empty($result['errors'])) {
        flash_set('error', t('poll_speech.fill.err', [
            'errors' => implode('; ', $result['errors']),
        ]));
    } else {
        flash_set('ok', $msg);
    }
    $listRedirect($day);
    return;
}

$result = PollSpeechFiller::fillDay(['date' => $day]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(array_merge(['ok' => !empty($result['ok'])], $result));
    return;
}

$msg = t('poll_speech.fill.done', [
    'date' => $result['date'],
    'filled' => (string)(int)$result['filled'],
    'skipped' => (string)(int)$result['skipped'],
]);
if (!empty($result['errors'])) {
    flash_set('error', t('poll_speech.fill.err', [
        'errors' => implode('; ', $result['errors']),
    ]));
} else {
    flash_set('ok', $msg);
}
$listRedirect($day);
