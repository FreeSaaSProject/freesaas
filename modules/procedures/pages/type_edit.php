<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = ProcedureTypesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('procedures.type.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('procedures.type.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';


if (!$isNew && EntityDelete::tryHandle('procedure_types', $id, '/procedure-types', [
    'stay_path' => '/procedure-types/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = ProcedureTypesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = ProcedureTypesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => ProcedureTypesRepository::find($newId),
                ]);
                return;
            }
            redirect('/procedure-types/' . $newId . '/edit?saved=1');
            return;
        }
        ProcedureTypesRepository::update($id, $data);
        $row = ProcedureTypesRepository::find($id) ?: $row;
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
        'item' => $row,
        'is_new' => $isNew,
    ]);
    return;
}

View::render('type_edit', [
    'title' => $isNew
        ? t('procedures.type.title_new')
        : t('procedures.type.title', ['name' => (string)($row['type'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('procedures.menu.types'), 'href' => '/procedure-types'],
        [
            'label' => $isNew
                ? t('procedures.type.heading_new')
                : (string)($row['type'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
], 'layout', 'procedures');
