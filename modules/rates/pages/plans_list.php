<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$operatorId = (int)($_GET['operator_id'] ?? 0);
$periodYm = trim((string)($_GET['period_ym'] ?? ''));
if ($periodYm === '') {
    $periodYm = date('Y-m');
}

$lq = ListQuery::resolve([
    'list_id' => 'rates_plans',
    'allowed' => ['ym' => 'PERIOD_YM', 'op' => 'ID_OPERATOR'],
    'default' => 'ym',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$data = StaffPlansRepository::list([
    'operator_id' => $operatorId,
    'period_ym' => $periodYm,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
]);

$staff = [];
if (class_exists('UsersRepository', false)) {
    foreach (UsersRepository::listEnabledStaff() as $u) {
        $staff[(int)$u['id']] = (string)$u['name'];
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $data['total'],
        'items' => $data['items'],
        'filters' => ['operator_id' => $operatorId, 'period_ym' => $periodYm],
        'staff' => $staff,
    ]);
    return;
}

View::render('plans_list', [
    'title' => t('rates.plans.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('rates.menu.list'), 'href' => '/rates'],
        ['label' => t('rates.plans.title'), 'current' => true],
    ]),
    'items' => $data['items'],
    'total' => $data['total'],
    'operatorId' => $operatorId,
    'periodYm' => $periodYm,
    'staff' => $staff,
    'canEdit' => Auth::can('rates.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => ['operator_id' => $operatorId, 'period_ym' => $periodYm],
], 'layout', 'rates');
