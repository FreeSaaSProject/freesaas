<?php
declare(strict_types=1);

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

BulkActions::tryHandle('poll_invites', '/poll-invites', $filters);

$result = PollInvitesRepository::list([
    'q' => $q,
    'id_poll' => $idPoll,
    'progress' => $progress,
    'id_operator' => $idOperator,
    'id_resp' => $idResp,
    'user_id' => Auth::userId(),
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
    $items[] = $row;
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'total' => $result['total'],
    ]);
    return;
}

View::render('list', [
    'title' => t('poll_invites.menu.list'),
    'items' => $items,
    'total' => $result['total'],
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
