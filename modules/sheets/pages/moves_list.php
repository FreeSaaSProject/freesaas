<?php
declare(strict_types=1);

$idAccount = (int)($_GET['id_account'] ?? 0);
$lq = ListQuery::resolve(['list_id' => 'sheet_moves', 'allowed' => SheetMovesRepository::listSortMap(), 'default' => 'id', 'default_dir' => 'desc', 'per_page' => 50]);
$filters = array_merge(['id_account' => $idAccount ?: ''], $lq['query']);
$result = SheetMovesRepository::list([
    'id_account' => $idAccount > 0 ? $idAccount : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
View::render('moves_list', [
    'title' => t('sheets.menu.moves'),
    'items' => $result['items'],
    'total' => $result['total'],
    'idAccount' => $idAccount,
    'accounts' => SheetAccountsRepository::names(false),
    'canEdit' => Auth::can('sheets.edit'),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'], 'filters' => $filters,
], 'layout', 'sheets');
