<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$idGood = max(0, (int)($_GET['id_good'] ?? 0));
$showAll = !empty($_GET['show_all']);

$items = StockLeftovers::summarize($idGood, $showAll);
$stickersReady = Database::tableExists('base_good_sticker_ins');

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'id_good' => $idGood > 0 ? $idGood : null,
        'show_all' => $showAll,
        'stickers_ready' => $stickersReady,
    ]);
    return;
}

View::render('leftovers', [
    'title' => t('stocks.leftovers.title'),
    'items' => $items,
    'idGood' => $idGood,
    'showAll' => $showAll,
    'stickersReady' => $stickersReady,
], 'layout', 'stocks');
