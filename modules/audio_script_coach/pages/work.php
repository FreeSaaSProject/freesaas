<?php
declare(strict_types=1);

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_script_coach.err.forbidden'), 403);
    }
    http_response_code(403);
    echo t('audio_script_coach.err.forbidden');
    return;
}

$runId = max(0, (int)($_POST['run_id'] ?? 0));
$untilDone = !empty($_POST['until_done']);
$res = CoachRunner::processNext($runId, $untilDone);

if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
    AgentHttp::emit($res);
}

if (!$res['ok']) {
    flash_set('error', (string)($res['error'] ?: t('audio_script_coach.err.work_failed')));
} else {
    flash_set('ok', t('audio_script_coach.flash.advanced', ['phase' => (string)$res['phase']]));
}
redirect('/audio/script-coach/run/' . max(1, $runId));
