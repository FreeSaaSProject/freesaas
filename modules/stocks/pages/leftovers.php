<?php
declare(strict_types=1);

$idGood = max(0, (int)($_GET['id_good'] ?? 0));
$showAll = !empty($_GET['show_all']);

$items = StockLeftovers::summarize($idGood, $showAll);
$stickersReady = Database::tableExists('base_good_sticker_ins');

View::render('leftovers', [
    'title' => t('stocks.leftovers.title'),
    'items' => $items,
    'idGood' => $idGood,
    'showAll' => $showAll,
    'stickersReady' => $stickersReady,
], 'layout', 'stocks');
