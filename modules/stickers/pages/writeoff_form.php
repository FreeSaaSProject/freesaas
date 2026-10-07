<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$stickerId = (int)($_GET['id_sticker'] ?? $_POST['id_sticker'] ?? 0);
$row = $stickerId > 0 ? StickerInsRepository::find($stickerId) : null;
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $res = StickerWriteoff::execute(Auth::userId(), $stickerId, [
        'amount' => (float)str_replace(',', '.', (string)($_POST['amount'] ?? '')),
        'descr' => (string)($_POST['descr'] ?? ''),
    ]);
    if ($wantsJson) {
        if ($res['success']) {
            AgentHttp::emit([
                'ok' => true,
                'message' => $res['message'],
                'details' => $res['details'],
                'item' => StickerInsRepository::find($stickerId),
            ]);
        } else {
            AgentHttp::error($res['message'], 400);
        }
        return;
    }
    if ($res['success']) {
        $message = $res['message'];
        $row = StickerInsRepository::find($stickerId);
    } else {
        $error = $res['message'];
    }
}

$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];

if ($wantsJson) {
    if ($stickerId > 0 && !$row) {
        AgentHttp::error(t('stickers.err.sticker_not_found', ['id' => $stickerId]), 404);
        return;
    }
    AgentHttp::emit([
        'ok' => true,
        'id_sticker' => $stickerId,
        'item' => $row,
        'products' => $products,
    ]);
    return;
}

View::render('writeoff_form', [
    'title' => t('stickers.writeoff.form_title'),
    'row' => $row,
    'stickerId' => $stickerId,
    'products' => $products,
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
