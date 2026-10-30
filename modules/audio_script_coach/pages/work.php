<?php
declare(strict_types=1);

/**
 * Batch work endpoint: one phase per tick (JSON) or streamed drain in an iframe.
 * Avoids sync drain of all phases in a single request (OOM / gateway timeout).
 */
if (!Auth::capability('SUPERADMIN_ACCESS') || !Auth::can('audio_script_coach.edit')) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_script_coach.err.forbidden'), 403);
    }
    http_response_code(403);
    echo t('audio_script_coach.err.forbidden');
    return;
}

$wantsJson = class_exists('AgentHttp', false) && AgentHttp::wantsJson();
$stream = !$wantsJson && !empty($_POST['stream']);
$action = trim((string)($_POST['action'] ?? 'tick'));
$runId = max(0, (int)($_POST['run_id'] ?? 0));

@set_time_limit(300);
@ini_set('memory_limit', '256M');

if ($stream) {
    CoachProgress::start(t('audio_script_coach.progress.title'));
} elseif ($wantsJson) {
    CoachProgress::beginCollect();
}

$emit = static function (array $payload) use ($wantsJson, $stream): void {
    $payload['stages'] = CoachProgress::collectStages();
    if ($stream) {
        CoachProgress::done($payload);
        return;
    }
    if ($wantsJson) {
        CoachProgress::reset();
        AgentHttp::emit($payload);
        return;
    }
    // Non-stream HTML fallback: redirect to run.
    $rid = max(1, (int)($payload['run_id'] ?? 0));
    if (!empty($payload['ok'])) {
        flash_set('ok', t('audio_script_coach.flash.advanced', [
            'phase' => (string)($payload['phase'] ?? ''),
        ]));
    } else {
        flash_set('error', (string)($payload['error'] ?? t('audio_script_coach.err.work_failed')));
    }
    redirect('/audio/script-coach/run/' . $rid);
};

if ($action === 'start') {
    $adminId = max(0, (int)($_POST['admin'] ?? 0));
    $date = trim((string)($_POST['date'] ?? ''));
    $depth = CoachRunsRepository::normalizeDepth((string)($_POST['depth'] ?? 'normal'));
    $res = CoachRunner::start($adminId, $date, Auth::userId(), $depth);
    if (!$res['ok']) {
        CoachProgress::note((string)$res['error'], '#fca5a5');
        $emit([
            'ok' => false,
            'action' => 'start',
            'run_id' => 0,
            'error' => (string)$res['error'],
            'done' => false,
            'phase' => '',
            'status' => 0,
            'proposals' => 0,
            'phases_done' => 0,
            'phases_total' => count(CoachRunner::phases()),
        ]);
        return;
    }
    $runId = (int)$res['run_id'];
    CoachProgress::mark(t('audio_script_coach.progress.created', ['id' => (string)$runId]));
    // One tick only when not streaming a full drain; stream mode continues below.
    if (!$stream) {
        $emit(array_merge(
            CoachRunner::tickMeta($runId, [
                'ok' => true,
                'phase' => 'catalog',
                'status' => CoachRunsRepository::STATUS_PENDING,
                'done' => false,
                'error' => '',
                'proposals' => 0,
            ]),
            ['action' => 'start']
        ));
        return;
    }
}

if ($action === 'rebuild') {
    if ($runId < 1) {
        CoachProgress::note(t('audio_script_coach.err.bad_args'), '#fca5a5');
        $emit([
            'ok' => false,
            'action' => 'rebuild',
            'run_id' => 0,
            'error' => t('audio_script_coach.err.bad_args'),
            'done' => false,
            'phase' => '',
            'status' => 0,
            'proposals' => 0,
            'phases_done' => 0,
            'phases_total' => count(CoachRunner::phases()),
        ]);
        return;
    }
    $depthRaw = trim((string)($_POST['depth'] ?? ''));
    $depth = $depthRaw !== '' ? CoachRunsRepository::normalizeDepth($depthRaw) : null;
    $res = CoachRunner::rebuild($runId, $depth);
    CoachProgress::mark(t('audio_script_coach.progress.rebuild', ['id' => (string)$runId]));
    if (!$stream) {
        $emit(array_merge($res, ['action' => 'rebuild']));
        return;
    }
}

if ($runId < 1) {
    CoachProgress::note(t('audio_script_coach.err.bad_args'), '#fca5a5');
    $emit([
        'ok' => false,
        'action' => $action,
        'run_id' => 0,
        'error' => t('audio_script_coach.err.bad_args'),
        'done' => false,
        'phase' => '',
        'status' => 0,
        'proposals' => 0,
        'phases_done' => 0,
        'phases_total' => count(CoachRunner::phases()),
    ]);
    return;
}

// Tick: one phase. Stream drain: loop remaining phases with marks between.
$phaseTotal = count(CoachRunner::phases());
$max = $stream ? $phaseTotal : 1;
$last = null;
try {
    for ($i = 0; $i < $max; $i++) {
        $last = CoachRunner::processNext($runId, false);
        $phase = (string)($last['phase'] ?? '');
        if (!empty($last['ok'])) {
            CoachProgress::mark(t('audio_script_coach.progress.phase', [
                'phase' => $phase !== '' ? $phase : '—',
                'done' => (string)(int)($last['phases_done'] ?? 0),
                'total' => (string)(int)($last['phases_total'] ?? $phaseTotal),
            ]));
        } else {
            CoachProgress::note(
                t('audio_script_coach.progress.phase_fail', [
                    'phase' => $phase !== '' ? $phase : '—',
                    'msg' => (string)($last['error'] ?? ''),
                ]),
                '#fca5a5'
            );
            break;
        }
        if (!empty($last['done'])) {
            CoachProgress::mark(t('audio_script_coach.progress.done', [
                'n' => (string)(int)($last['proposals'] ?? 0),
            ]));
            break;
        }
        if (!$stream) {
            break;
        }
    }
} catch (Throwable $e) {
    CoachProgress::note($e->getMessage(), '#fca5a5');
    $last = CoachRunner::tickMeta($runId, [
        'ok' => false,
        'error' => $e->getMessage(),
        'status' => CoachRunsRepository::STATUS_ERROR,
        'phase' => (string)(($last['phase'] ?? '') ?: ''),
        'done' => false,
        'proposals' => 0,
    ]);
    try {
        CoachRunsRepository::setError($runId, $e->getMessage());
    } catch (Throwable $ignored) {
        // keep JSON error path
    }
}

$emit(array_merge(is_array($last) ? $last : [], ['action' => $action === 'start' || $action === 'rebuild' ? $action : 'tick']));
