<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$operatorId = (int)($_GET['operator_id'] ?? 0);
$badge = trim((string)($_GET['badge'] ?? ''));
$active = !empty($_GET['active']);

$lq = ListQuery::resolve([
    'list_id' => 'audio_assignments',
    'allowed' => BadgeAssignmentsRepository::listSortMap(),
    'default' => 'start',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'operator_id' => $operatorId > 0 ? (string)$operatorId : '',
    'badge' => $badge,
    'active' => $active ? '1' : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$result = BadgeAssignmentsRepository::list([
    'q' => $q,
    'operator_id' => $operatorId,
    'badge' => $badge,
    'active' => $active,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$staff = [];
foreach (UsersRepository::listForCopy() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}

$badgeOptions = [];
if (Database::tableExists('base_records_badges')) {
    foreach (BadgesRepository::listAll(false) as $b) {
        $code = (string)$b['CODE'];
        $badgeOptions[$code] = (string)($b['NAME'] !== '' ? $b['NAME'] : $b['CODE']);
    }
}

View::render('assignments_list', [
    'title' => t('audio.assignments.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio.assignments.title'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'operatorId' => $operatorId,
    'badge' => $badge,
    'active' => $active,
    'staff' => $staff,
    'badgeOptions' => $badgeOptions,
    'canEdit' => Auth::can('audio.badges'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
], 'layout', 'audio');
