<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$accounts = LaundryAccountsRepository::list(['enabled' => '1', 'order' => 'ID ASC', 'limit' => 0])['items'];
$types = LaundryItemTypesRepository::listEnabled();
$matrix = LaundryMovesRepository::balanceMatrix();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $facts = [];
        $posted = is_array($_POST['fact'] ?? null) ? $_POST['fact'] : [];
        foreach ($posted as $aid => $byType) {
            if (!is_array($byType)) {
                continue;
            }
            foreach ($byType as $tid => $qty) {
                $facts[(int)$aid][(int)$tid] = (int)$qty;
            }
        }
        $n = LaundryInventory::applyAbsolute($facts, Auth::userId());
        $message = t('laundry.inventory.done', ['n' => $n]);
        $matrix = LaundryMovesRepository::balanceMatrix();
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'moves' => $n, 'balances' => $matrix]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'accounts' => $accounts, 'types' => $types, 'balances' => $matrix]);
    return;
}

View::render('inventory', [
    'title' => t('laundry.inventory.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.inventory.title'), 'current' => true],
    ]),
    'accounts' => $accounts,
    'types' => $types,
    'matrix' => $matrix,
    'message' => $message,
    'error' => $error,
], 'layout', 'laundry');
