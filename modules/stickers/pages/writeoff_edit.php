<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [];
if (!$isNew) {
    $found = Acts2OutRepository::find($id);
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
        $data = Acts2OutRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = Acts2OutRepository::create($data, Auth::userId());
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => Acts2OutRepository::find($newId)]);
                return;
            }
            redirect('/stickers/writeoffs/' . $newId . '/edit?saved=1');
            return;
        }
        Acts2OutRepository::update($id, $data);
        $row = Acts2OutRepository::find($id) ?: $row;
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
$outs = !$isNew ? StickerOutsRepository::list(['id_act' => $id, 'limit' => 200])['items'] : [];
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'outs' => $outs,
        'stocks' => $stocks,
        'products' => $products,
    ]);
    return;
}

View::render('writeoff_edit', [
    'title' => $isNew ? t('stickers.writeoff_act.edit_new') : t('stickers.writeoff_act.edit', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'stocks' => $stocks,
    'outs' => $outs,
    'products' => $products,
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
