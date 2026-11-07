<?php
declare(strict_types=1);

$uid = Auth::userId();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $wantsJson = AgentHttp::wantsJson();
    if (class_exists('Csrf', false) && !$wantsJson) {
        Csrf::requireValid();
    }
    $ids = [];
    if (!empty($_POST['mark_all'])) {
        $all = PollInvitesRepository::listUnviewedForUser($uid, ['limit' => 500, 'offset' => 0]);
        foreach ($all['items'] as $row) {
            $ids[] = (int)($row['id'] ?? 0);
        }
    } else {
        $raw = $_POST['ids'] ?? $_POST['id'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
    try {
        $done = PollInvitesRepository::markViewedByUser($ids, $uid);
        $message = t('poll_invites.unviewed.marked', ['n' => $done]);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'marked' => $done]);
            return;
        }
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$result = PollInvitesRepository::listUnviewedForUser($uid, ['limit' => 100, 'offset' => 0]);
$extraStaffIds = [];
foreach ($result['items'] as $row) {
    foreach (['id_proc_operator', 'id_proc_admin'] as $k) {
        $sid = (int)($row[$k] ?? 0);
        if ($sid > 0) {
            $extraStaffIds[] = $sid;
        }
    }
}
$staffNames = class_exists('UsersRepository', false)
    ? UsersRepository::staffNamesIncluding($extraStaffIds)
    : [];

$items = [];
foreach ($result['items'] as $row) {
    $meta = PollInvitesRepository::staffScoreMeta($row, $staffNames);
    $row['meta'] = $meta;
    $row['transcript'] = PollInvitesRepository::transcriptSnippet($row['data_text'] ?? '', 0);
    $row['speech_record_ids'] = $meta['speech_record_ids'] ?? PollInvitesRepository::speechRecordIdsFromLog((string)($row['log'] ?? ''));
    $answerLines = [];
    if (class_exists('PollsRepository', false) && class_exists('PollWalkEngine', false)) {
        $tree = PollsRepository::buildWalkTree((int)($row['id_poll'] ?? 0));
        $opts = class_exists('PollAnswersCodec', false)
            ? PollAnswersCodec::decode((string)($row['data_opts'] ?? ''))
            : [];
        if (!is_array($opts)) {
            $opts = [];
        }
        $state = PollWalkEngine::walk($tree['questions'] ?? [], $opts, (int)($tree['first_qid'] ?? 0));
        foreach ($state['path'] as $step) {
            $answerLines[] = [
                'q' => (string)($step['question']['name'] ?? ''),
                'a' => PollWalkEngine::answerLabel($step['question'], $step['answer']),
            ];
        }
    }
    $row['answer_lines'] = $answerLines;
    $items[] = $row;
}

if (AgentHttp::wantsJson() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'total' => $result['total'],
    ]);
    return;
}

View::render('unviewed', [
    'title' => t('poll_invites.menu.unviewed'),
    'items' => $items,
    'total' => $result['total'],
    'message' => $message,
    'error' => $error,
    'canOpenCards' => Auth::capability('SUPERADMIN_ACCESS') && Auth::can('poll_invites.edit'),
    'showAudioLinks' => Auth::capability('SUPERADMIN_ACCESS') && ModuleManager::isEnabled('audio'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_invites.menu.unviewed'), 'current' => true],
    ]),
], 'layout', 'poll_invites');
