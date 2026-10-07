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
    try {
        $data = Acts2InRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = Acts2InRepository::create($data, Auth::userId());
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => Acts2InRepository::find($newId)]);
                return;
            }
            redirect('/stickers/receipts/' . $newId . '/edit?saved=1');
            return;
        }
        Acts2InRepository::update($id, $data, Auth::userId());
        $row = Acts2InRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
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

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'stickers' => $stickers,
        'stocks' => $stocks,
        'suppliers' => $suppliers,
        'products' => $products,
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
    'products' => $products,
    'canWriteoff' => Auth::can('stickers.writeoff'),
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
