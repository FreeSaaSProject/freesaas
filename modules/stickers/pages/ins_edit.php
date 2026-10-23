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
            if ($preAct > 0) {
                redirect('/stickers/receipts/' . (int)$data['id_act'] . '/edit?saved=1');
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

$currentActId = (int)($row['id_act'] ?? 0);
$currentAct = $currentActId > 0 ? Acts2InRepository::find($currentActId) : null;
$actFixed = $currentAct !== null && (!$isNew || $preAct > 0);
$acts = [];
if (!$actFixed) {
    $canSigned = Auth::capability('SUPERADMIN_ACCESS');
    foreach (Acts2InRepository::list(['limit' => 200, 'order' => 'a.ID DESC'])['items'] as $a) {
        if ($canSigned || empty($a['is_signed'])) {
            $acts[] = $a;
        }
    }
}
$stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];

$products = [];
if (class_exists('ProductsRepository', false)) {
    $products = ProductsRepository::materialNames();
    if ($products === []) {
        // Legacy: many consumables lack is_material — offer all enabled products.
        $products = ProductsRepository::names(true);
    }
    $curGood = (int)($row['id_good'] ?? 0);
    if ($curGood > 0 && !isset($products[$curGood])) {
        $p = ProductsRepository::find($curGood);
        if ($p) {
            $products[$curGood] = (string)$p['name'];
        }
    }
}
$goodQ = trim((string)($row['good_q'] ?? ''));
if ($goodQ === '' && (int)($row['id_good'] ?? 0) > 0) {
    $goodQ = $products[(int)$row['id_good']] ?? '';
}
$manualActivation = StickerActivation::manualAllowed();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'act' => $currentAct,
        'receipt_acts' => $actFixed ? [] : $acts,
        'products' => $products,
        'activation_mode' => StickerActivation::mode(),
    ]);
    return;
}

$backUrl = $actFixed ? '/stickers/receipts/' . $currentActId . '/edit' : '/stickers/ins';
$title = $isNew ? t('stickers.ins.edit_new') : t('stickers.ins.edit', ['id' => $id]);
$crumbs = $actFixed
    ? [
        ['label' => t('stickers.menu.receipts'), 'href' => '/stickers/receipts'],
        ['label' => 'П-' . $currentActId, 'href' => $backUrl],
        ['label' => $title, 'current' => true],
    ]
    : [
        ['label' => t('stickers.menu.ins'), 'href' => '/stickers/ins'],
        ['label' => $title, 'current' => true],
    ];

View::render('ins_edit', [
    'title' => $title,
    'breadcrumbs' => Breadcrumbs::withHome($crumbs),
    'isNew' => $isNew,
    'row' => $row,
    'acts' => $acts,
    'act' => $actFixed ? $currentAct : null,
    'stocks' => $stocks,
    'products' => $products,
    'goodQ' => $goodQ,
    'manualActivation' => $manualActivation,
    'backUrl' => $backUrl,
    'canWriteoff' => Auth::can('stickers.writeoff') && !$isNew,
    'message' => $message,
    'error' => $error,
], 'layout', 'stickers');
