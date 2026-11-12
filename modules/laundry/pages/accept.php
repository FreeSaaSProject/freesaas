<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        LaundryPanel::handlePost(LaundryPanel::ACTION_ACCEPT, $_POST, Auth::userId(), LaundryConfig::ticketNewsId());
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
        View::render('error', ['title' => t('laundry.accept.title'), 'message' => $e->getMessage()]);
        return;
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'open' => LaundryShipmentsRepository::listOpenWithLines(),
        'types' => LaundryItemTypesRepository::names(true),
    ]);
    return;
}

redirect('/laundry#laundry');
