<?php
declare(strict_types=1);

$runId = (int)Router::param('id');
$run = CoachRunsRepository::find($runId);
if ($run === null) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_script_coach.err.run_not_found', ['id' => (string)$runId]), 404);
    }
    flash_set('error', t('audio_script_coach.err.run_not_found', ['id' => (string)$runId]));
    redirect('/audio/script-coach');
}

$canEdit = Auth::can('audio_script_coach.edit') && Auth::capability('SUPERADMIN_ACCESS');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        flash_set('error', t('audio_script_coach.err.forbidden'));
        redirect('/audio/script-coach/run/' . $runId);
    }
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'advance') {
        $untilDone = !empty($_POST['until_done']);
        $proc = CoachRunner::processNext($runId, $untilDone);
        if (!$proc['ok'] && ($proc['error'] ?? '') !== '') {
            flash_set('error', (string)$proc['error']);
        } else {
            flash_set('ok', t('audio_script_coach.flash.advanced', ['phase' => (string)$proc['phase']]));
        }
        redirect('/audio/script-coach/run/' . $runId);
    }
}

$proposals = CoachRunsRepository::proposalsForRun($runId);
$artifacts = CoachRunsRepository::artifactPhases($runId);
$catalog = CoachRunsRepository::artifact($runId, 'catalog');
$facts = CoachRunsRepository::artifact($runId, 'facts');
$scriptNames = [];
if (is_array($catalog['scripts'] ?? null)) {
    foreach ($catalog['scripts'] as $s) {
        $scriptNames[(int)$s['id']] = (string)$s['name'];
    }
}
$dictNames = [];
if (is_array($catalog['dicts'] ?? null)) {
    foreach ($catalog['dicts'] as $d) {
        if (is_array($d)) {
            $dictNames[(int)$d['id']] = (string)$d['name'];
        }
    }
}
$partNames = [];
if (is_array($catalog['scripts'] ?? null)) {
    foreach ($catalog['scripts'] as $s) {
        foreach (is_array($s['parts'] ?? null) ? $s['parts'] : [] as $p) {
            $partNames[(int)$p['id']] = (string)$p['name'];
        }
    }
}

$y = (int)substr((string)$run['day'], 0, 4);
$m = (int)substr((string)$run['day'], 5, 2);
$reparseHint = '/audio/calendar?' . http_build_query(['year' => $y, 'month' => $m]);
$dayHref = '/audio/script-coach/day?' . http_build_query([
    'admin' => (int)$run['id_admin'],
    'date' => (string)$run['day'],
]);

if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'run' => $run,
        'artifacts' => $artifacts,
        'facts' => $facts,
        'proposals' => $proposals,
    ]);
}

$title = t('audio_script_coach.run.title', ['id' => (string)$runId]);
View::render('run', [
    'title' => $title,
    'run' => $run,
    'proposals' => $proposals,
    'artifacts' => $artifacts,
    'facts' => $facts,
    'scriptNames' => $scriptNames,
    'dictNames' => $dictNames,
    'partNames' => $partNames,
    'canEdit' => $canEdit,
    'dayHref' => $dayHref,
    'reparseHint' => $reparseHint,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio_script_coach.menu.list'), 'href' => '/audio/script-coach'],
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'audio_script_coach');
