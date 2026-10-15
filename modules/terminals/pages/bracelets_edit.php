<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!TerminalsConfig::registryEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('terminals.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('terminals.err.disabled')]);
    return;
}

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$canEdit = Auth::can('terminals.edit');

$row = [];
if (!$isNew) {
    $found = BraceletsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('terminals.err.bracelet_not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('terminals.err.bracelet_not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';


if (!$isNew && EntityDelete::tryHandle('terminals_bracelets', $id, '/terminals/bracelets', [
    'stay_path' => '/terminals/bracelets/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canEdit) {
        if ($wantsJson) {
            AgentHttp::error(t('terminals.err.edit_forbidden'), 403);
            return;
        }
        $error = t('terminals.err.edit_forbidden');
    } else {
        try {
            $data = BraceletsRepository::dataFromPost($_POST);
            if ($isNew) {
                $newId = BraceletsRepository::create($data);
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => BraceletsRepository::find($newId)]);
                    return;
                }
                redirect('/terminals/bracelets/' . $newId . '/edit?saved=1');
                return;
            }
            BraceletsRepository::update($id, $data);
            $row = BraceletsRepository::find($id) ?: $row;
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
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'item' => $row, 'is_new' => $isNew]);
    return;
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('bracelets_edit', [
    'title' => $isNew
        ? t('terminals.bracelets.edit.title_new')
        : t('terminals.bracelets.edit.title', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.bracelets'), 'href' => '/terminals/bracelets'],
        ['label' => $isNew ? t('terminals.bracelets.edit.heading_new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'canEdit' => $canEdit,
], 'layout', 'terminals');
