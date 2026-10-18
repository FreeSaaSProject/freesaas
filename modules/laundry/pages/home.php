<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$accounts = LaundryAccountsRepository::names(true);
$types = LaundryItemTypesRepository::listEnabled();
$matrix = LaundryMovesRepository::balanceMatrix();
$started = LaundryMovesRepository::hasAnyMoves();
$open = LaundryShipmentsRepository::listOpenWithLines();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'started' => $started,
        'balances' => $matrix,
        'accounts' => $accounts,
        'types' => $types,
        'open_shipments' => $open,
    ]);
    return;
}

View::render('home', [
    'title' => t('laundry.menu.home'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'current' => true],
    ]),
    'accounts' => $accounts,
    'types' => $types,
    'matrix' => $matrix,
    'started' => $started,
    'open' => $open,
    'canShip' => Auth::can('laundry.ship'),
    'canEdit' => Auth::can('laundry.edit'),
], 'layout', 'laundry');
