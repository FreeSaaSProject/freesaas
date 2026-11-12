<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        LaundryPanel::handlePost(LaundryPanel::ACTION_SHIP, $_POST, Auth::userId(), LaundryConfig::ticketNewsId());
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true]);
            return;
        }
        redirect('/laundry?saved=1#laundry');
        return;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        View::render('error', ['title' => t('laundry.ship.title'), 'message' => $e->getMessage()]);
        return;
    }
}

if ($wantsJson) {
    $from = LaundryConfig::termsAccountId();
    $types = LaundryItemTypesRepository::listEnabled();
    $balances = [];
    foreach ($types as $t) {
        $balances[(int)$t['id']] = LaundryMovesRepository::balance($from, (int)$t['id']);
    }
    AgentHttp::emit([
        'ok' => true,
        'types' => $types,
        'balances' => $balances,
        'from' => $from,
        'to' => LaundryConfig::laundryAccountId(),
    ]);
    return;
}

redirect('/laundry#laundry');
