<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = StocksRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('stocks.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = StocksRepository::dataFromPost($_POST, $id);
        if ($isNew) {
            $newId = StocksRepository::create($data);
            redirect('/stocks/' . $newId . '/edit?saved=1');
            return;
        }
        StocksRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = StocksRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'name' => (string)($_POST['name'] ?? ''),
            'descr' => (string)($_POST['descr'] ?? ''),
            'type' => (int)($_POST['type'] ?? 1),
            'operative' => !empty($_POST['operative']) ? 1 : 0,
            'autocontrol_req' => !empty($_POST['autocontrol_req']) ? 1 : 0,
            'vrt_stock_id' => (int)($_POST['vrt_stock_id'] ?? 0),
            'id_cabinet' => (int)($_POST['id_cabinet'] ?? 0),
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$cabinets = [];
if (class_exists('CabinetsRepository', false) && ModuleManager::isEnabled('cabinets')) {
    foreach (CabinetsRepository::list(['limit' => 0])['items'] as $c) {
        $cabinets[(int)$c['id']] = (string)$c['name'];
    }
}
$virtualStocks = StocksRepository::names(StocksRepository::TYPE_VIRTUAL);
if (!$isNew) {
    unset($virtualStocks[$id]);
}

View::render('edit', [
    'title' => $isNew
        ? t('stocks.edit.title_new')
        : t('stocks.edit.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'cabinets' => $cabinets,
    'virtualStocks' => $virtualStocks,
    'message' => $message,
    'error' => $error,
], 'layout', 'stocks');
