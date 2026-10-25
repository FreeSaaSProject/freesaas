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
        // Single phase only (legacy form). Full drain uses JS ticks → /work.
        $proc = CoachRunner::processNext($runId, false);
        if (!$proc['ok'] && ($proc['error'] ?? '') !== '') {
            flash_set('error', (string)$proc['error']);
        } else {
            flash_set('ok', t('audio_script_coach.flash.advanced', ['phase' => (string)$proc['phase']]));
        }
        redirect('/audio/script-coach/run/' . $runId);
    }
    if ($action === 'rebuild') {
        $depthRaw = trim((string)($_POST['depth'] ?? ''));
        $depth = $depthRaw !== '' ? CoachRunsRepository::normalizeDepth($depthRaw) : null;
        $proc = CoachRunner::rebuild($runId, $depth);
        if (!$proc['ok'] && ($proc['error'] ?? '') !== '') {
            flash_set('error', (string)$proc['error']);
            redirect('/audio/script-coach/run/' . $runId);
        }
        flash_set('ok', t('audio_script_coach.flash.rebuild_queued'));
        redirect('/audio/script-coach/run/' . $runId . '?auto=1');
    }
}

$proposals = CoachRunsRepository::proposalsForRun($runId);
$closedIdle = false;
foreach ($proposals as $prop) {
    if (CoachApply::closeIfNothingLeft($prop, Auth::userId())) {
        $closedIdle = true;
    }
}
if ($closedIdle) {
    $proposals = CoachRunsRepository::proposalsForRun($runId);
}
$artifacts = CoachRunsRepository::artifactPhases($runId);
$catalog = CoachRunsRepository::artifact($runId, 'catalog');
$facts = CoachRunsRepository::artifact($runId, 'facts');
$windows = CoachRunsRepository::artifact($runId, 'windows');
$llmMeta = CoachRunsRepository::artifact($runId, 'llm');
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

$artifactPhaseIds = [];
foreach ($artifacts as $a) {
    $artifactPhaseIds[] = (string)($a['phase'] ?? '');
}
$hasLlmPhase = in_array('llm', $artifactPhaseIds, true);
$llmErrors = [];
if (is_array($llmMeta) && is_array($llmMeta['errors'] ?? null)) {
    foreach ($llmMeta['errors'] as $err) {
        $msg = trim((string)$err);
        if ($msg !== '') {
            $llmErrors[] = $msg;
        }
    }
}
$llmFilter = null;
if (is_array($llmMeta) && is_array($llmMeta['filter'] ?? null)) {
    $f = $llmMeta['filter'];
    $phraseDrop = is_array($f['phrases_dropped'] ?? null) ? $f['phrases_dropped'] : [];
    $propDrop = is_array($f['proposals_dropped'] ?? null) ? $f['proposals_dropped'] : [];
    $phraseDropRows = [];
    foreach ($phraseDrop as $reason => $n) {
        $n = (int)$n;
        if ($n < 1) {
            continue;
        }
        $phraseDropRows[] = [
            'reason' => (string)$reason,
            'n' => $n,
            'label' => t('audio_script_coach.filter.phrase.' . (string)$reason),
        ];
    }
    $propDropRows = [];
    foreach ($propDrop as $reason => $n) {
        $n = (int)$n;
        if ($n < 1) {
            continue;
        }
        $propDropRows[] = [
            'reason' => (string)$reason,
            'n' => $n,
            'label' => t('audio_script_coach.filter.proposal.' . (string)$reason),
        ];
    }
    $llmFilter = [
        'phrases_raw' => (int)($f['phrases_raw'] ?? 0),
        'phrases_kept' => (int)($f['phrases_kept'] ?? 0),
        'phrases_dropped_total' => array_sum(array_map('intval', $phraseDrop)),
        'phrases_dropped' => $phraseDropRows,
        'proposals_raw' => (int)($f['proposals_raw'] ?? 0),
        'proposals_kept' => (int)($f['proposals_kept'] ?? 0),
        'proposals_dropped_total' => array_sum(array_map('intval', $propDrop)),
        'proposals_dropped' => $propDropRows,
        'skip_reason' => trim((string)($f['skip_reason'] ?? '')),
        'scripts_needing_work' => (int)($f['scripts_needing_work'] ?? 0),
        'scripts_missing_windows' => (int)($f['scripts_missing_windows'] ?? 0),
        'scripts_sent_to_llm' => (int)($f['scripts_sent_to_llm'] ?? 0),
    ];
}
$needsWork = false;
if (is_array($facts) && is_array($facts['scripts'] ?? null)) {
    foreach ($facts['scripts'] as $sf) {
        if (!is_array($sf)) {
            continue;
        }
        if (!empty($sf['silent']) || !empty($sf['trigger_gap']) || !empty($sf['weak_start'])
            || (is_array($sf['missed_starts'] ?? null) && $sf['missed_starts'] !== [])
            || (is_array($sf['incomplete'] ?? null) && $sf['incomplete'] !== [])
            || (is_array($sf['stage_gaps'] ?? null) && $sf['stage_gaps'] !== [])
            || !empty($sf['force_enrich'])
        ) {
            $needsWork = true;
            break;
        }
    }
}
$windowCount = (is_array($windows) && is_array($windows['windows'] ?? null))
    ? count($windows['windows'])
    : 0;

$proposalsEmptyReason = null;
if ($proposals === []) {
    if (!$hasLlmPhase) {
        $proposalsEmptyReason = 'need_llm';
    } elseif ($llmErrors !== []) {
        $proposalsEmptyReason = 'llm_errors';
    } elseif (!$needsWork) {
        $proposalsEmptyReason = 'nothing_to_enrich';
    } elseif ($windowCount < 1) {
        $proposalsEmptyReason = 'no_windows';
    } else {
        $proposalsEmptyReason = 'empty';
    }
}

$showReparseHint = false;
foreach ($proposals as $prop) {
    $pst = (int)($prop['status'] ?? -1);
    if ($pst === 1) {
        $showReparseHint = true;
        break;
    }
    if ($pst === 0) {
        $meta = is_array($prop['apply_meta'] ?? null) ? $prop['apply_meta'] : [];
        foreach (is_array($meta['keys'] ?? null) ? $meta['keys'] : [] as $k) {
            if ((int)($k['id'] ?? 0) > 0 && empty($k['skipped'])) {
                $showReparseHint = true;
                break 2;
            }
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
        'llm_errors' => $llmErrors,
        'llm_filter' => $llmFilter,
        'proposals_empty_reason' => $proposalsEmptyReason,
    ]);
}

$autoDrain = $canEdit
    && !empty($_GET['auto'])
    && in_array((int)$run['status'], [
        CoachRunsRepository::STATUS_PENDING,
        CoachRunsRepository::STATUS_RUNNING,
    ], true);

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
    'llmErrors' => $llmErrors,
    'llmFilter' => $llmFilter,
    'proposalsEmptyReason' => $proposalsEmptyReason,
    'showReparseHint' => $showReparseHint,
    'autoDrain' => $autoDrain,
    'phasesTotal' => count(CoachRunner::phases()),
    'phasesDone' => count($artifactPhaseIds),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio_script_coach.menu.list'), 'href' => '/audio/script-coach'],
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'audio_script_coach');
