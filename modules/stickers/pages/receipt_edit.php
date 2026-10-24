<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [];
if (!$isNew) {
    $found = Acts2InRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('stickers.err.act_not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('stickers.err.act_not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Existing acts are view-only for header fields; stickers are managed via /stickers/ins.
    if (!$isNew) {
        if ($wantsJson) {
            AgentHttp::error(t('stickers.err.act_locked'), 403);
            return;
        }
        $error = t('stickers.err.act_locked');
    } else {
        try {
            $data = Acts2InRepository::dataFromPost($_POST);
            $lines = Acts2InRepository::linesFromPost($_POST);
            $created = Acts2InRepository::createWithLines($data, $lines, Auth::userId());
            $newId = (int)$created['id'];
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => Acts2InRepository::find($newId),
                    'sticker_ids' => $created['sticker_ids'],
                    'lines_count' => count($created['sticker_ids']),
                ]);
                return;
            }
            redirect('/stickers/receipts/' . $newId . '/edit?saved=1');
            return;
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
                return;
            }
            $error = $e->getMessage();
            $row = array_merge($row, $_POST);
        }
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];
$defaultStockId = class_exists('StocksRepository', false) ? StocksRepository::defaultForStaff() : null;
if ($isNew && empty($row['id_stock']) && $defaultStockId !== null) {
    $row['id_stock'] = $defaultStockId;
}
$suppliers = [];
if (class_exists('SuppliersRepository', false)) {
    $suppliers = SuppliersRepository::names(true);
}
$stickers = !$isNew ? StickerInsRepository::listGroupedForAct($id) : [];
$actSumsPack = !$isNew
    ? StickerInsRepository::listSums(['id_act' => $id])
    : ['total' => 0, 'sums' => StickerInsRepository::emptySums()];
$sums = $actSumsPack['sums'];
$sumsTotal = (int)$actSumsPack['total'];
$dpsMoves = !$isNew ? (Acts2InDps::movesByActIds([$id])[$id] ?? []) : [];
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];
$units = class_exists('UnitsRepository', false) ? UnitsRepository::names() : [];
$productUnits = [];
foreach ($stickers as $s) {
    $gid = (int)$s['id_good'];
    if ($gid > 0 && !isset($productUnits[$gid]) && class_exists('ProductsRepository', false)) {
        $pr = ProductsRepository::find($gid);
        if ($pr) {
            $productUnits[$gid] = [
                'unit' => $units[(int)($pr['unit_id'] ?? 0)] ?? '',
                'unitu' => $units[(int)($pr['unit_use_id'] ?? 0)] ?? '',
            ];
        }
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'stickers' => $stickers,
        'sums' => $sums,
        'sums_total' => $sumsTotal,
        'dps' => $dpsMoves,
        'grouped' => true,
        'stocks' => $stocks,
        'default_stock_id' => $defaultStockId,
        'suppliers' => $suppliers,
        'products' => $products,
        'product_units' => $productUnits,
    ]);
    return;
}

View::render('receipt_edit', [
    'title' => $isNew ? t('stickers.receipt.edit_new') : t('stickers.receipt.edit', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'stocks' => $stocks,
    'suppliers' => $suppliers,
    'stickers' => $stickers,
    'sums' => $sums,
    'sumsTotal' => $sumsTotal,
    'dpsMoves' => $dpsMoves,
    'ledgerOn' => Acts2InDps::ledgerReady(),
    'products' => $products,
    'productUnits' => $productUnits,
    'canWriteoff' => Auth::can('stickers.writeoff'),
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
