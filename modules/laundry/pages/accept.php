<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$open = LaundryShipmentsRepository::listOpenWithLines();
$types = LaundryItemTypesRepository::names(true);
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $lines = [];
        $ret = is_array($_POST['ret'] ?? null) ? $_POST['ret'] : [];
        foreach ($ret as $lineId => $qty) {
            $lines[] = ['id_shipment_line' => (int)$lineId, 'qty' => (int)$qty];
        }
        $id = LaundryShipmentsRepository::acceptReturn(
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
        $open = LaundryShipmentsRepository::listOpenWithLines();
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'open' => $open, 'types' => $types]);
    return;
}

View::render('accept', [
    'title' => t('laundry.accept.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.accept.title'), 'current' => true],
    ]),
    'open' => $open,
    'types' => $types,
    'message' => $message,
    'error' => $error,
], 'layout', 'laundry');
