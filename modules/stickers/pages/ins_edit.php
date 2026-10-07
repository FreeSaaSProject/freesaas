<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$preAct = (int)($_GET['id_act'] ?? 0);
$row = ['id_act' => $preAct, 'qty' => 1, 'qty_qtyu_rate' => 1];
if (!$isNew) {
    $found = StickerInsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('stickers.err.sticker_not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('stickers.err.sticker_not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = StickerInsRepository::dataFromPost($_POST);
        if ($isNew) {
            $ids = StickerInsRepository::createBatch($data, Auth::userId());
            $first = $ids[0] ?? 0;
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'ids' => $ids,
                    'id' => $first,
                    'item' => $first > 0 ? StickerInsRepository::find($first) : null,
                ]);
                return;
            }
            redirect($first > 0
                ? '/stickers/ins/' . $first . '/edit?saved=1'
                : '/stickers/ins?id_act=' . (int)$data['id_act']);
            return;
        }
        StickerInsRepository::update($id, $data);
        $row = StickerInsRepository::find($id) ?: $row;
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

$acts = Acts2InRepository::list(['limit' => 200, 'order' => 'ID DESC'])['items'];
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(true) : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'receipt_acts' => $acts,
        'products' => $products,
    ]);
    return;
}

View::render('ins_edit', [
    'title' => $isNew ? t('stickers.ins.edit_new') : t('stickers.ins.edit', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'acts' => $acts,
    'products' => $products,
    'canWriteoff' => Auth::can('stickers.writeoff') && !$isNew,
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
