<?php
declare(strict_types=1);

$adminId = max(0, (int)($_GET['admin'] ?? $_POST['admin'] ?? 0));
$date = trim((string)($_GET['date'] ?? $_POST['date'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_script_coach.err.bad_args'), 400);
    }
    flash_set('error', t('audio_script_coach.err.bad_args'));
    redirect('/audio/script-coach');
}
if ($adminId < 1) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_script_coach.err.bad_args'), 400);
    }
    flash_set('error', t('audio_script_coach.err.bad_args'));
    redirect('/audio/script-coach');
}

$canStart = Auth::can('audio_script_coach.edit') && Auth::capability('SUPERADMIN_ACCESS');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'start'));
    if ($action === 'start') {
        if (!$canStart) {
            if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
                AgentHttp::error(t('audio_script_coach.err.forbidden'), 403);
            }
            flash_set('error', t('audio_script_coach.err.forbidden'));
            redirect('/audio/script-coach/day?' . http_build_query(['admin' => $adminId, 'date' => $date]));
        }
        $depth = CoachRunsRepository::normalizeDepth((string)($_POST['depth'] ?? 'normal'));
        $res = CoachRunner::start($adminId, $date, Auth::userId(), $depth);
        if (!$res['ok']) {
            if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
                AgentHttp::error((string)$res['error'], 400);
            }
            flash_set('error', (string)$res['error']);
            redirect('/audio/script-coach/day?' . http_build_query(['admin' => $adminId, 'date' => $date]));
        }
        $runId = (int)$res['run_id'];
        // Drain phases synchronously for small days; work endpoint available for resume.
        $proc = CoachRunner::processNext($runId, true);
        if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
            AgentHttp::emit([
                'ok' => true,
                'run_id' => $runId,
                'process' => $proc,
            ]);
        }
        if (!$proc['ok'] && ($proc['error'] ?? '') !== '') {
            flash_set('error', (string)$proc['error']);
        } else {
            flash_set('ok', t('audio_script_coach.flash.started'));
        }
        redirect('/audio/script-coach/run/' . $runId);
    }
}

$latest = CoachRunsRepository::findLatestForDay($adminId, $date);
$adminName = '';
if (class_exists('UsersRepository', false)) {
    $u = UsersRepository::find($adminId);
    $adminName = is_array($u) ? (string)($u['name'] ?? '') : '';
}

$dayReportHref = '/audio/calendar/day-report?' . http_build_query([
    'admin' => $adminId,
    'date' => $date,
]);

if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'admin_id' => $adminId,
        'date' => $date,
        'admin_name' => $adminName,
        'can_start' => $canStart,
        'latest' => $latest,
    ]);
}

$title = t('audio_script_coach.day.title', [
    'name' => $adminName !== '' ? $adminName : ('#' . $adminId),
    'date' => $date,
]);
View::render('day', [
    'title' => $title,
    'adminId' => $adminId,
    'date' => $date,
    'adminName' => $adminName,
    'canStart' => $canStart,
    'latest' => $latest,
    'dayReportHref' => $dayReportHref,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio_script_coach.menu.list'), 'href' => '/audio/script-coach'],
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'audio_script_coach');
