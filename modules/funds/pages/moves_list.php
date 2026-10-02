<?php
declare(strict_types=1);
$q = trim((string)($_GET['q'] ?? ''));
$fundId = (int)($_GET['fund_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? ($_GET['id_fund_move_type'] ?? 0));
$fundType = (int)($_GET['fund_type'] ?? ($_GET['id_fund_type'] ?? 0));
$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$yearE = (int)($_GET['yearE'] ?? $year);
$monthE = (int)($_GET['monthE'] ?? $month);
$lq = ListQuery::resolve(['list_id' => 'fund_movements', 'allowed' => FundMovementsRepository::listSortMap(), 'default' => 'id', 'default_dir' => 'desc', 'per_page' => 50]);
$filters = array_merge([
    'q' => $q,
    'fund_id' => $fundId > 0 ? (string)$fundId : '',
    'type_id' => $typeId > 0 ? (string)$typeId : '',
    'fund_type' => $fundType > 0 ? (string)$fundType : '',
    'year' => $year > 0 ? (string)$year : '',
    'month' => $month > 0 ? (string)$month : '',
    'yearE' => $yearE > 0 ? (string)$yearE : '',
    'monthE' => $monthE > 0 ? (string)$monthE : '',
], $lq['query']);
if (!empty($lq['show_all'])) { $filters['page'] = 'all'; }
BulkActions::tryHandle('fund_movements', '/fund-movements', $filters);
$result = FundMovementsRepository::list([
    'q' => $q, 'fund_id' => $fundId, 'type_id' => $typeId, 'fund_type' => $fundType,
    'year' => $year, 'month' => $month, 'year_e' => $yearE, 'month_e' => $monthE,
    'limit' => ListQuery::sqlLimit($lq), 'offset' => $lq['offset'], 'order' => $lq['order_sql'],
]);
View::render('moves_list', [
    'title' => t('funds.menu.movements'),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('funds.menu.movements'), 'current' => true]]),
    'items' => $result['items'], 'total' => $result['total'], 'q' => $q,
    'fundId' => $fundId, 'typeId' => $typeId, 'fundType' => $fundType,
    'year' => $year, 'month' => $month,
    'funds' => FundsRepository::names(),
    'canEdit' => Auth::can('funds.edit'),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'], 'filters' => $filters, 'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'funds');
