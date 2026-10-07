<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['name' => '', 'pid' => 0, 'type' => 2, 'amt_out' => 0, 'inventory_req' => 0, 'real_price' => 0];
if (!$isNew) {
    $found = FundsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('funds.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('funds.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = ''; $error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = FundsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = FundsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => FundsRepository::find($newId)]);
                return;
            }
            redirect('/funds/' . $newId . '/edit?saved=1');
            return;
        }
        FundsRepository::update($id, $data);
        $row = FundsRepository::find($id) ?: $row;
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
if (isset($_GET['saved']) && $message === '') { $message = t('core.common.saved'); }
$parents = [0 => t('funds.root')] + FundsRepository::names();
if (!$isNew) { unset($parents[$id]); }
$amtTypes = class_exists('AccountMoveTypesRepository') ? AccountMoveTypesRepository::leafNames() : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'parents' => $parents,
        'amt_types' => $amtTypes,
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew ? t('funds.edit_new') : t('funds.edit', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.list'), 'href' => '/funds'],
        ['label' => $isNew ? t('funds.edit_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew, 'row' => $row, 'parents' => $parents, 'amtTypes' => $amtTypes,
    'message' => $message, 'error' => $error,
], 'layout', 'funds');
