<?php
declare(strict_types=1);

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_script_coach.err.forbidden'), 403);
    }
    flash_set('error', t('audio_script_coach.err.forbidden'));
    redirect('/audio/script-coach');
}

$id = (int)Router::param('id');
$proposal = CoachRunsRepository::findProposal($id);
if ($proposal === null) {
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_script_coach.err.proposal_not_found', ['id' => (string)$id]), 404);
    }
    flash_set('error', t('audio_script_coach.err.proposal_not_found', ['id' => (string)$id]));
    redirect('/audio/script-coach');
}

$run = CoachRunsRepository::find((int)$proposal['id_run']);
$adminId = $run !== null ? (int)$run['id_admin'] : 0;
$action = trim((string)($_POST['action'] ?? ''));
$linkPart = !empty($_POST['link_part']) || !empty($proposal['link_part']);
$force = !empty($_POST['force']);

if ($action === 'accept') {
    $res = CoachApply::accept($proposal, $adminId, Auth::userId(), $linkPart, $force);
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::emit($res);
    }
    if (!$res['ok']) {
        $err = (string)$res['error'];
        $skipped = (int)($res['meta']['skipped'] ?? 0);
        if ($skipped < 1) {
            foreach (is_array($res['meta']['keys'] ?? null) ? $res['meta']['keys'] : [] as $k) {
                if (!empty($k['skipped'])) {
                    $skipped++;
                }
            }
        }
        if ($skipped > 0) {
            flash_set('error', t('audio_script_coach.flash.accept_partial_overlap', [
                'n' => (string)$skipped,
            ]));
        } else {
            flash_set('error', $err);
        }
    } else {
        $created = (int)($res['meta']['created'] ?? 0);
        $skipped = (int)($res['meta']['skipped'] ?? 0);
        if ($created < 1) {
            foreach (is_array($res['meta']['keys'] ?? null) ? $res['meta']['keys'] : [] as $k) {
                if ((int)($k['id'] ?? 0) > 0 && empty($k['skipped'])) {
                    $created++;
                } elseif (!empty($k['skipped'])) {
                    $skipped++;
                }
            }
        }
        if ($skipped > 0) {
            flash_set('ok', t('audio_script_coach.flash.accepted_partial', [
                'created' => (string)$created,
                'skipped' => (string)$skipped,
            ]));
        } else {
            flash_set('ok', t('audio_script_coach.flash.accepted', ['created' => (string)$created]));
        }
    }
} elseif ($action === 'reject') {
    $res = CoachApply::reject($proposal, Auth::userId());
    if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
        AgentHttp::emit($res);
    }
    if (!$res['ok']) {
        flash_set('error', (string)$res['error']);
    } else {
        flash_set('ok', t('audio_script_coach.flash.rejected'));
    }
} else {
    flash_set('error', t('audio_script_coach.err.unknown_action'));
}

redirect('/audio/script-coach/run/' . (int)$proposal['id_run']);
