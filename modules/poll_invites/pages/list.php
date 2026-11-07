<?php
declare(strict_types=1);

$uid = Auth::userId();
$message = '';
$error = '';

$q = trim((string)($_GET['q'] ?? ''));
$idPoll = (int)($_GET['id_poll'] ?? 0);
$progress = (string)($_GET['progress'] ?? '');
$idOperator = (int)($_GET['id_operator'] ?? 0);
$idResp = (int)($_GET['id_resp'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'poll_invites',
    'allowed' => PollInvitesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$listFilters = [
    'q' => $q,
    'id_poll' => $idPoll,
    'progress' => $progress,
    'id_operator' => $idOperator,
    'id_resp' => $idResp,
    'user_id' => $uid,
];

$filters = array_merge([
    'q' => $q,
    'id_poll' => $idPoll > 0 ? (string)$idPoll : '',
    'progress' => $progress,
    'id_operator' => $idOperator > 0 ? (string)$idOperator : '',
    'id_resp' => $idResp > 0 ? (string)$idResp : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $wantsJson = AgentHttp::wantsJson();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'mark_viewed') {
        if (class_exists('Csrf', false) && !$wantsJson && !Csrf::verify()) {
            Csrf::deny('csrf');
            return;
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
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'marked' => $done]);
                return;
            }
            $message = t('poll_invites.unviewed.marked', ['n' => $done]);
            flash_set('ok', $message);
        } catch (Throwable $e) {
            AgentHttp::rethrowIfCapture($e);
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
                return;
            }
            flash_set('error', $e->getMessage());
        }
        $ret = trim((string)($_POST['ret'] ?? ''));
        if ($ret !== '' && str_starts_with($ret, '/poll-invites')) {
            redirect($ret);
            return;
        }
        redirect('/poll-invites?progress=unviewed');
        return;
    }
}

// UI $filters (incl. pager keys) for return URL; listWhere ignores unknown keys.
BulkActions::tryHandle('poll_invites', '/poll-invites', $filters);

$result = PollInvitesRepository::list([
    'q' => $q,
    'id_poll' => $idPoll,
    'progress' => $progress,
    'id_operator' => $idOperator,
    'id_resp' => $idResp,
    'user_id' => $uid,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$pollNames = PollsRepository::rootNames(false);

$extraStaffIds = [];
if ($idResp > 0) {
    $extraStaffIds[] = $idResp;
}
foreach ($result['items'] as $row) {
    foreach (['id_proc_operator', 'id_proc_admin'] as $k) {
        $sid = (int)($row[$k] ?? 0);
        if ($sid > 0) {
            $extraStaffIds[] = $sid;
        }
    }
    $resp = (string)($row['resp_users'] ?? '');
    if ($resp !== '' && preg_match_all('/#(\d+)#/', $resp, $m)) {
        foreach ($m[1] as $rid) {
            $extraStaffIds[] = (int)$rid;
        }
    }
}
$staffNames = class_exists('UsersRepository', false)
    ? UsersRepository::staffNamesIncluding($extraStaffIds)
    : [];
if ($staffNames === [] && class_exists('UsersRepository', false)) {
    $staffNames = UsersRepository::staffNamesAll(500);
}

$items = [];
foreach ($result['items'] as $row) {
    $meta = PollInvitesRepository::staffScoreMeta($row, $staffNames);
    $row['id_proc'] = $meta['id_proc'];
    $row['proc_dtstart'] = $meta['proc_dtstart'];
    $row['proc_dtend'] = $meta['proc_dtend'];
    $row['proc_product_name'] = $meta['proc_product_name'];
    $row['id_proc_operator'] = $meta['id_proc_operator'];
    $row['id_proc_admin'] = $meta['id_proc_admin'];
    $row['executor_name'] = $meta['executor_name'];
    $row['admin_name'] = $meta['admin_name'];
    $row['score'] = $meta['score'];
    $row['transcript'] = PollInvitesRepository::transcriptSnippet($row['data_text'] ?? '', 0);
    $row['speech_record_ids'] = PollInvitesRepository::speechRecordIdsFromLog((string)($row['log'] ?? ''));
    $doneAt = (int)($row['dt_completed'] ?? 0);
    $viewed = (string)($row['resp_users_viewed'] ?? '');
    $row['unviewed_for_me'] = $doneAt > 0
        && PollInvitesRepository::isResponsibleUser($row, $uid)
        && !PollInvitesRepository::isViewedByUser($viewed, $uid);
    $items[] = $row;
}

$unviewedTotal = PollInvitesRepository::countUnviewedForUser($uid);
$unfilledInFilter = (Auth::can('poll_invites.delete') && PollInvitesRepository::hasListScope($listFilters))
    ? PollInvitesRepository::countUnfilledMatching($listFilters)
    : 0;

$longjobPending = null;
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['poll_invites_longjob'])) {
    $pending = $_SESSION['poll_invites_longjob'];
    unset($_SESSION['poll_invites_longjob']);
    if (is_array($pending) && (time() - (int)($pending['at'] ?? 0)) < 120) {
        $longjobPending = $pending;
    }
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'total' => $result['total'],
        'unviewed_total' => $unviewedTotal,
        'unfilled_in_filter' => $unfilledInFilter,
        'progress' => $progress,
    ]);
    return;
}

View::render('list', [
    'title' => t('poll_invites.menu.list'),
    'items' => $items,
    'total' => $result['total'],
    'unviewedTotal' => $unviewedTotal,
    'unfilledInFilter' => $unfilledInFilter,
    'longjobPending' => $longjobPending,
    'canDelete' => Auth::can('poll_invites.delete'),
    'q' => $q,
    'idPoll' => $idPoll,
    'progress' => $progress,
    'idOperator' => $idOperator,
    'idResp' => $idResp,
    'pollNames' => $pollNames,
    'staffNames' => $staffNames,
    'canEdit' => Auth::capability('SUPERADMIN_ACCESS'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_invites.menu.list'), 'current' => true],
    ]),
], 'layout', 'poll_invites');
