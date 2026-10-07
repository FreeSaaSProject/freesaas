<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$uid = Auth::userId();
$status = (int)($_GET['status'] ?? 0);
$executor = (int)($_GET['executor'] ?? 0);
$acceptor = (int)($_GET['acceptor'] ?? 0);
$party = (string)($_GET['party'] ?? '');
$super = Auth::capability('CONTROL_ACCESS');

if (!$super) {
    // Prefer explicit party= from chips / filter; fall back to legacy executor/acceptor query.
    if ($party !== 'executor' && $party !== 'acceptor') {
        if ($executor === $uid && $acceptor < 1) {
            $party = 'executor';
        } elseif ($acceptor === $uid && $executor < 1) {
            $party = 'acceptor';
        } else {
            $party = '';
        }
    }
    if ($party === 'executor') {
        $executor = $uid;
        $acceptor = 0;
    } elseif ($party === 'acceptor') {
        $acceptor = $uid;
        $executor = 0;
    } else {
        $executor = 0;
        $acceptor = 0;
    }
}

$lq = ListQuery::resolve([
    'list_id' => 'contracts',
    'allowed' => ContractsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 40,
]);

$result = ContractsRepository::listForUser($uid, [
    'status' => $status,
    'executor' => $executor,
    'acceptor' => $acceptor,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$staff = [];
if ($super) {
    $staff = UsersRepository::list(['enabled' => '1', 'limit' => 200])['items'];
}

$filters = array_merge([
    'status' => $status,
    'executor' => $super ? ($executor > 0 ? $executor : '') : '',
    'acceptor' => $super ? ($acceptor > 0 ? $acceptor : '') : '',
    'party' => !$super ? $party : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'scoped' => $result['scoped'],
        'filters' => [
            'status' => $status,
            'executor' => $super && $executor > 0 ? $executor : null,
            'acceptor' => $super && $acceptor > 0 ? $acceptor : null,
            'party' => !$super && $party !== '' ? $party : null,
        ],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

View::render('list', [
    'title' => t('contracts.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'scoped' => $result['scoped'],
    'status' => $status,
    'executor' => $executor,
    'acceptor' => $acceptor,
    'party' => $party,
    'staff' => $staff,
    'super' => $super,
    'uid' => $uid,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
], 'layout', 'contracts');
