<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/NewsStatusesRepository.php');

$wantsJson = AgentHttp::wantsJson();

$lq = ListQuery::resolve([
    'list_id' => 'news_statuses',
    'allowed' => NewsStatusesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'asc',
    'per_page' => 200,
]);

$items = NewsStatusesRepository::listAll(['order' => $lq['order_sql']]);

if ($wantsJson) {
    $out = [];
    foreach ($items as $row) {
        $out[] = NewsStatusesRepository::forAgent($row);
    }
    AgentHttp::emit([
        'ok' => true,
        'items' => $out,
        'total' => count($out),
        'sort' => $lq['sort'],
        'dir' => $lq['dir'],
        'deadline_policies' => NewsStatusesRepository::deadlinePolicies(),
    ]);
}

View::render('news_statuses', [
    'title' => t('core.news_statuses.title'),
    'items' => $items,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'filters' => $lq['query'],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.news'), 'href' => '/news?type=2'],
        ['label' => t('core.menu.news_statuses'), 'current' => true],
    ]),
], 'layout', 'core');
