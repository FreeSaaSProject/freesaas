<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['name' => '', 'pid' => max(0, (int)($_GET['pid'] ?? 0)), 'type' => 2, 'amt_out' => 0, 'inventory_req' => 0, 'real_price' => 0];
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

if (!$isNew && EntityDelete::tryHandle('funds', $id, '/funds', [
    'stay_path' => '/funds/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = FundsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = FundsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => FundsRepository::find($newId)]);
                return;
            }
            flash_set('ok', t('core.common.saved'));
            redirect('/funds/' . $newId);
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
$parents = [0 => t('funds.root')];
foreach (FundsRepository::allRows() as $r) {
    if ($r['pid'] === 0 && $r['id'] !== $id) {
        $parents[$r['id']] = $r['name'];
    }
}
$currentPid = (int)($row['pid'] ?? 0);
if ($currentPid > 0 && !isset($parents[$currentPid])) {
    $parents[$currentPid] = (string)(FundsRepository::allRows()[$currentPid]['name'] ?? ('#' . $currentPid));
}
$parentRow = $currentPid > 0 ? (FundsRepository::allRows()[$currentPid] ?? null) : null;
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

$crumbs = [['label' => t('funds.menu.list'), 'href' => '/funds']];
if ($parentRow !== null) {
    $crumbs[] = ['label' => $parentRow['name'], 'href' => '/funds/' . $parentRow['id']];
}
if (!$isNew) {
    $crumbs[] = ['label' => (string)($row['name'] ?? ''), 'href' => '/funds/' . $id];
}
$crumbs[] = ['label' => $isNew ? t('funds.edit_new') : t('core.common.edit'), 'current' => true];

View::render('edit', [
    'title' => $isNew ? t('funds.edit_new') : t('funds.edit', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome($crumbs),
    'isNew' => $isNew, 'row' => $row, 'parents' => $parents, 'amtTypes' => $amtTypes,
    'message' => $message, 'error' => $error,
], 'layout', 'funds');
