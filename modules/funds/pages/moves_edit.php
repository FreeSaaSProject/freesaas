<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['fund_id' => (int)($_GET['fund_id'] ?? 0), 'type_id' => 1, 'summ' => 0, 'name' => '', 'descr' => ''];
if (!$isNew) {
    $found = FundMovementsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('funds.moves.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('funds.moves.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = ''; $error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = FundMovementsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = FundMovementsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => FundMovementsRepository::find($newId)]);
                return;
            }
            redirect('/fund-movements/' . $newId . '/edit?saved=1');
            return;
        }
        FundMovementsRepository::update($id, $data);
        $row = FundMovementsRepository::find($id) ?: $row;
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

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'funds' => FundsRepository::names(),
    ]);
    return;
}

View::render('moves_edit', [
    'title' => $isNew ? t('funds.moves.edit_new') : t('funds.moves.edit', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('funds.menu.movements'), 'href' => '/fund-movements'],
        ['label' => $isNew ? t('funds.moves.edit_new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew, 'row' => $row, 'funds' => FundsRepository::names(),
    'message' => $message, 'error' => $error,
], 'layout', 'funds');
