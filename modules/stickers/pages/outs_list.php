<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$idAct = (int)($_GET['id_act'] ?? 0);
$idSticker = (int)($_GET['id_sticker'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['cancel_out_id'])) {
    $res = StickerWriteoff::cancel(Auth::userId(), (int)$_POST['cancel_out_id']);
    if ($wantsJson) {
        if ($res['success']) {
            AgentHttp::emit(['ok' => true, 'message' => $res['message']]);
        } else {
            AgentHttp::error($res['message'], 400);
        }
        return;
    }
    $params = array_filter(['q' => $q ?: null, 'id_act' => $idAct ?: null, 'id_sticker' => $idSticker ?: null]);
    $params[$res['success'] ? 'ok' : 'err'] = $res['message'];
    redirect('/stickers/outs?' . http_build_query($params));
    return;
}

$lq = ListQuery::resolve([
    'list_id' => 'stickers_outs',
    'allowed' => StickerOutsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge([
    'q' => $q,
    'id_act' => $idAct ?: '',
    'id_sticker' => $idSticker ?: '',
], $lq['query']);
$result = StickerOutsRepository::list([
    'q' => $q,
    'id_act' => $idAct > 0 ? $idAct : null,
    'id_sticker' => $idSticker > 0 ? $idSticker : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => [
            'q' => $q,
            'id_act' => $idAct > 0 ? $idAct : null,
            'id_sticker' => $idSticker > 0 ? $idSticker : null,
        ],
        'products' => $products,
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

View::render('outs_list', [
    'title' => t('stickers.menu.outs'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idAct' => $idAct,
    'idSticker' => $idSticker,
    'products' => $products,
    'canWriteoff' => Auth::can('stickers.writeoff'),
    'flashOk' => (string)($_GET['ok'] ?? ''),
    'flashErr' => (string)($_GET['err'] ?? ''),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
], 'layout', 'stickers');
