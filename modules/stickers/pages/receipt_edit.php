<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [];
if (!$isNew) {
    $found = Acts2InRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('stickers.err.act_not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = Acts2InRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = Acts2InRepository::create($data, Auth::userId());
            redirect('/stickers/receipts/' . $newId . '/edit?saved=1');
            return;
        }
        Acts2InRepository::update($id, $data, Auth::userId());
        $message = t('core.common.saved');
        $row = Acts2InRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];
$suppliers = [];
if (class_exists('SuppliersRepository', false)) {
    $suppliers = SuppliersRepository::names(true);
}
$stickers = !$isNew ? StickerInsRepository::list(['id_act' => $id, 'limit' => 200, 'order' => 'ID ASC'])['items'] : [];
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];

View::render('receipt_edit', [
    'title' => $isNew ? t('stickers.receipt.edit_new') : t('stickers.receipt.edit', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'stocks' => $stocks,
    'suppliers' => $suppliers,
    'stickers' => $stickers,
    'products' => $products,
    'canWriteoff' => Auth::can('stickers.writeoff'),
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
