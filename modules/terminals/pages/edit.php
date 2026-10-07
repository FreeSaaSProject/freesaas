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
    $found = TerminalsRepository::find($id, true);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('terminals.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('terminals.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canEdit) {
        if ($wantsJson) {
            AgentHttp::error(t('terminals.err.edit_forbidden'), 403);
            return;
        }
        $error = t('terminals.err.edit_forbidden');
    } else {
        try {
            $data = TerminalsRepository::dataFromPost($_POST, $isNew);
            if ($isNew) {
                $newId = TerminalsRepository::create($data);
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'id' => $newId, 'item' => TerminalsRepository::find($newId, true)]);
                    return;
                }
                redirect('/terminals/' . $newId . '/edit?saved=1');
                return;
            }
            TerminalsRepository::update($id, $data);
            $row = TerminalsRepository::find($id, true) ?: $row;
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

View::render('edit', [
    'title' => $isNew
        ? t('terminals.edit.title_new')
        : t('terminals.edit.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'href' => '/terminals'],
        ['label' => $isNew ? t('terminals.edit.heading_new') : (string)($row['name'] ?? '#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'canEdit' => $canEdit,
], 'layout', 'terminals');
