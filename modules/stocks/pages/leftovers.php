<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$idGood = max(0, (int)($_GET['id_good'] ?? 0));
$showAll = !empty($_GET['show_all']);
$months = max(0, min(12, (int)($_GET['months'] ?? 6)));
$showCat = max(0, (int)($_GET['show_cat'] ?? 0));

$items = StockLeftovers::summarize([
    'id_good' => $idGood,
    'show_all' => $showAll,
    'months' => $months,
    'show_cat' => $showCat,
]);
$categories = StockLeftovers::categoryOptions();
$stickersReady = Database::tableExists('base_good_sticker_ins');
$monthLabels = [];
if ($items !== [] && isset($items[0]['months']) && is_array($items[0]['months'])) {
    foreach ($items[0]['months'] as $m) {
        $monthLabels[] = (string)($m['label'] ?? '');
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $items,
        'id_good' => $idGood > 0 ? $idGood : null,
        'show_all' => $showAll,
        'months' => $months,
        'show_cat' => $showCat > 0 ? $showCat : null,
        'categories' => $categories,
        'stickers_ready' => $stickersReady,
    ]);
    return;
}

View::render('leftovers', [
    'title' => t('stocks.leftovers.title'),
    'items' => $items,
    'idGood' => $idGood,
    'showAll' => $showAll,
    'months' => $months,
    'showCat' => $showCat,
    'categories' => $categories,
    'monthLabels' => $monthLabels,
    'stickersReady' => $stickersReady,
], 'layout', 'stocks');
