<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$from = LaundryConfig::termsAccountId();
$to = LaundryConfig::laundryAccountId();
$types = LaundryItemTypesRepository::listEnabled();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $lines = [];
        $qtys = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
        foreach ($qtys as $typeId => $qty) {
            $lines[] = ['id_item_type' => (int)$typeId, 'qty' => (int)$qty];
        }
        $id = LaundryShipmentsRepository::createShipment(
            $from,
            $to,
            $lines,
            trim((string)($_POST['descr'] ?? '')),
            (float)str_replace(',', '.', (string)($_POST['weight'] ?? '0')),
            time(),
            Auth::userId(),
            LaundryConfig::ticketNewsId()
        );
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id]);
            return;
        }
        redirect('/laundry/shipments?saved=1');
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$balances = [];
foreach ($types as $t) {
    $balances[(int)$t['id']] = LaundryMovesRepository::balance($from, (int)$t['id']);
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'types' => $types, 'balances' => $balances, 'from' => $from, 'to' => $to]);
    return;
}

View::render('ship', [
    'title' => t('laundry.ship.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.ship.title'), 'current' => true],
    ]),
    'types' => $types,
    'balances' => $balances,
    'accounts' => LaundryAccountsRepository::names(true),
    'from' => $from,
    'to' => $to,
    'message' => $message,
    'error' => $error,
], 'layout', 'laundry');
