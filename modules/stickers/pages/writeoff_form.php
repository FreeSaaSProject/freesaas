<?php
declare(strict_types=1);

$stickerId = (int)($_GET['id_sticker'] ?? $_POST['id_sticker'] ?? 0);
$row = $stickerId > 0 ? StickerInsRepository::find($stickerId) : null;
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $res = StickerWriteoff::execute(Auth::userId(), $stickerId, [
        'amount' => (float)str_replace(',', '.', (string)($_POST['amount'] ?? '')),
        'descr' => (string)($_POST['descr'] ?? ''),
    ]);
    if ($res['success']) {
        $message = $res['message'];
        $row = StickerInsRepository::find($stickerId);
    } else {
        $error = $res['message'];
    }
}

$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];

View::render('writeoff_form', [
    'title' => t('stickers.writeoff.form_title'),
    'row' => $row,
    'stickerId' => $stickerId,
    'products' => $products,
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
