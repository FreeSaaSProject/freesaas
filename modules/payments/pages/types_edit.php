<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['name' => '', 'descr' => '', 'color' => ''];

if (!$isNew) {
    $found = PaymentTypesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('payments.types.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('payments.types.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = PaymentTypesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = PaymentTypesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => PaymentTypesRepository::find($newId),
                ]);
                return;
            }
            redirect('/payment-types/' . $newId . '/edit?saved=1');
            return;
        }
        PaymentTypesRepository::update($id, $data);
        $row = PaymentTypesRepository::find($id) ?: $row;
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
        $row = array_merge($row, $_POST);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
    ]);
    return;
}

View::render('types_edit', [
    'title' => $isNew
        ? t('payments.types.edit_new')
        : t('payments.types.edit', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('payments.menu.types'), 'href' => '/payment-types'],
        [
            'label' => $isNew ? t('payments.types.edit_new') : (string)($row['name'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'payments');
