<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = BonusMovesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('bonuses.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('bonuses.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $hintUser = (int)($_GET['client_id'] ?? $_GET['id_user'] ?? 0);
    $row = [
        'client_id' => $hintUser > 0 ? $hintUser : 0,
        'direction' => 1,
        'amount' => 0,
        'kind' => BonusMovesRepository::KIND_MANUAL,
        'ref_type' => null,
        'ref_id' => 0,
        'created_at' => time(),
        'expires_at' => time() + BonusProcessor::ACCRUE_TTL_SEC,
        'note' => '',
        'dismissed' => 0,
    ];
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = BonusMovesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = BonusMovesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'create',
                    'id' => $newId,
                    'row' => BonusMovesRepository::find($newId),
                ]);
                return;
            }
            redirect('/bonuses/' . $newId . '/edit?saved=1');
            return;
        }
        BonusMovesRepository::update($id, $data);
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'action' => 'update',
                'id' => $id,
                'row' => BonusMovesRepository::find($id),
            ]);
            return;
        }
        $message = t('core.common.saved');
        $row = BonusMovesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

if ($wantsJson && $isNew) {
    AgentHttp::error(t('bonuses.err.forbidden'), 405);
    return;
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('edit', [
    'title' => $isNew
        ? t('bonuses.edit.title_new')
        : t('bonuses.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bonuses.menu.list'), 'href' => '/bonuses'],
        [
            'label' => $isNew ? t('bonuses.edit.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'bonuses');
