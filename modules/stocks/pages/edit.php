<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = StocksRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('stocks.err.not_found', ['id' => $id]), 404);
            return;
        }
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


if (!$isNew && EntityDelete::tryHandle('stocks', $id, '/stocks', [
    'stay_path' => '/stocks/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = StocksRepository::dataFromPost($_POST, $id);
        if ($isNew) {
            $newId = StocksRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => StocksRepository::find($newId),
                ]);
                return;
            }
            redirect('/stocks/' . $newId . '/edit?saved=1');
            return;
        }
        StocksRepository::update($id, $data);
        $row = StocksRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => $row,
            ]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, [
            'name' => (string)($_POST['name'] ?? ''),
            'descr' => (string)($_POST['descr'] ?? ''),
            'operative' => !empty($_POST['operative']) ? 1 : 0,
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

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'cabinets' => $cabinets,
        'default_stock_id' => StocksRepository::defaultForStaff(),
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew
        ? t('stocks.edit.title_new')
        : t('stocks.edit.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'cabinets' => $cabinets,
    'message' => $message,
    'error' => $error,
], 'layout', 'stocks');
