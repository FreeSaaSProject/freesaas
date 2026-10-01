<?php
declare(strict_types=1);

$lq = ListQuery::resolve([
    'list_id' => 'procedure_types',
    'allowed' => ProcedureTypesRepository::listSortMap(),
    'default' => 'type',
    'default_dir' => 'asc',
    'per_page' => 100,
]);

$filters = $lq['query'];
BulkActions::tryHandle('procedure_types', '/procedure-types', $filters);

$items = ProcedureTypesRepository::listAll(['order' => $lq['order_sql']]);
$bulkFlash = BulkActions::consumeFlash();

View::render('types', [
    'title' => t('procedures.menu.types'),
    'items' => $items,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('procedures.menu.types'), 'current' => true],
    ]),
], 'layout', 'procedures');
