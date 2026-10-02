<?php
declare(strict_types=1);
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['name' => '', 'pid' => 0, 'type' => 2, 'amt_out' => 0, 'inventory_req' => 0, 'real_price' => 0];
if (!$isNew) {
    $found = FundsRepository::find($id);
    if (!$found) {
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
        if ($isNew) { redirect('/funds/' . FundsRepository::create($data) . '/edit?saved=1'); return; }
        FundsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = FundsRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') { $message = t('core.common.saved'); }
$parents = [0 => t('funds.root')] + FundsRepository::names();
if (!$isNew) { unset($parents[$id]); }
$amtTypes = class_exists('AccountMoveTypesRepository') ? AccountMoveTypesRepository::leafNames() : [];
View::render('edit', [
    'title' => $isNew ? t('funds.edit_new') : t('funds.edit', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.list'), 'href' => '/funds'],
        ['label' => $isNew ? t('funds.edit_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew, 'row' => $row, 'parents' => $parents, 'amtTypes' => $amtTypes,
    'message' => $message, 'error' => $error,
], 'layout', 'funds');
