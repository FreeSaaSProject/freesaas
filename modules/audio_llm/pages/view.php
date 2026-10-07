<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$study = StudiesRepository::find($id);
if (!$study) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('audio_llm.err.not_found'), 404);
    }
    redirect('/audio/llm-studies');
}

$message = !empty($_GET['saved']) ? t('core.common.saved') : '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Auth::can('audio_llm.edit')) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        StudiesRepository::delete($id);
        redirect('/audio/llm-studies?saved=1');
    }
    if (!empty($study['IS_CYCLIC']) && $action === 'save_cyclic') {
        $res = StudiesRepository::updateCyclic(
            $id,
            (string)($_POST['title'] ?? ''),
            (string)($_POST['prompt'] ?? ''),
            (int)($_POST['trigger_good_id'] ?? 0),
            (int)($_POST['amount_positive'] ?? 0),
            (int)($_POST['amount_negative'] ?? 0),
            !empty($_POST['cyclic_enabled'])
        );
        if ($res['ok']) {
            redirect('/audio/llm-studies/' . $id . '?saved=1');
        }
        $error = $res['error'];
        $study = StudiesRepository::find($id) ?: $study;
    } elseif ($action === 'process') {
        StudyRunner::processNext($id, max(1, min(20, (int)($_POST['limit'] ?? 1))));
        StudyRunner::maybeGenerateSummary($id);
        redirect('/audio/llm-studies/' . $id . '?saved=1');
    } elseif ($action === 'process_all') {
        StudyRunner::processUntilDone($id, 200);
        StudyRunner::maybeGenerateSummary($id);
        redirect('/audio/llm-studies/' . $id . '?saved=1');
    } elseif ($action === 'reset') {
        StudyRunner::reset($id);
        redirect('/audio/llm-studies/' . $id . '?saved=1');
    } elseif ($action === 'summary') {
        StudyRunner::maybeGenerateSummary($id);
        redirect('/audio/llm-studies/' . $id . '?saved=1');
    } elseif ($action === 'retry') {
        StudyRunner::retryResult($id, (int)($_POST['record_id'] ?? 0));
        redirect('/audio/llm-studies/' . $id . '?saved=1');
    }
}

$progress = StudyRunner::progress($id);
$results = empty($study['IS_CYCLIC']) ? StudiesRepository::results($id) : [];

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'study' => $study,
        'progress' => $progress,
        'results' => $results,
    ]);
}

View::render('view', [
    'title' => t('audio_llm.edit.title') . ' #' . $id,
    'study' => $study,
    'progress' => $progress,
    'results' => $results,
    'message' => $message,
    'error' => $error,
    'canEdit' => Auth::can('audio_llm.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio_llm.menu.studies'), 'href' => '/audio/llm-studies'],
        ['label' => '#' . $id, 'current' => true],
    ]),
], 'layout', 'audio_llm');
