<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

if (!WorkweeksRepository::canAssign()) {
    if ($wantsJson) {
        AgentHttp::error(t('workweeks.err.no_assign'), 403);
        return;
    }
    http_response_code(403);
    View::render('error', [
        'title' => t('workweeks.err.forbidden'),
        'message' => t('workweeks.err.no_assign'),
    ]);
    return;
}

$row = [];
if (!$isNew) {
    $found = WorkweeksRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('workweeks.err.not_found'), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('workweeks.err.not_found'),
        ]);
        return;
    }
    if (!WorkweeksRepository::canAssignOperator((int)$found['id_operator'])) {
        if ($wantsJson) {
            AgentHttp::error(t('workweeks.err.no_assign'), 403);
            return;
        }
        http_response_code(403);
        View::render('error', [
            'title' => t('workweeks.err.forbidden'),
            'message' => t('workweeks.err.no_assign'),
        ]);
        return;
    }
    $row = $found;
    $row['date'] = date('Y-m-d', (int)$found['dt']);
}

$message = '';
$error = '';


if (!$isNew && EntityDelete::tryHandle('workweeks', $id, '/workweeks', [
    'stay_path' => '/workweeks/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $defaults = null;
        if (!$isNew && $row !== []) {
            $defaults = [
                'date' => (string)($row['date'] ?? date('Y-m-d', (int)($row['dt'] ?? 0))),
                'id_operator' => (int)($row['id_operator'] ?? 0),
                'price_col' => (int)($row['price_col'] ?? 1),
                'mas_max' => (int)($row['mas_max'] ?? 1),
                'id_table' => (int)($row['id_table'] ?? 0),
            ];
        }
        $data = WorkweeksRepository::dataFromPost($_POST, $defaults);
        if ($isNew) {
            $newId = WorkweeksRepository::createFromForm($data);
            if ($wantsJson) {
                $saved = WorkweeksRepository::find($newId);
                if ($saved) {
                    $saved['date'] = date('Y-m-d', (int)$saved['dt']);
                }
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => WorkweeksRepository::forAgent($saved),
                ]);
                return;
            }
            $url = '/workweeks/' . $newId . '/edit?saved=1';
            $ret = NavContext::returnUrl();
            if ($ret !== '') {
                $url .= '&ret=' . rawurlencode($ret);
            }
            redirect($url);
            return;
        }
        WorkweeksRepository::updateFromForm($id, $data);
        $found = WorkweeksRepository::find($id);
        if ($found) {
            $row = $found;
            $row['date'] = date('Y-m-d', (int)$found['dt']);
        }
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => WorkweeksRepository::forAgent($row),
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
            'date' => (string)($_POST['date'] ?? ($row['date'] ?? '')),
            'id_operator' => (int)($_POST['id_operator'] ?? ($row['id_operator'] ?? 0)),
            'price_col' => (int)($_POST['price_col'] ?? ($row['price_col'] ?? 1)),
            'mas_max' => (int)($_POST['mas_max'] ?? ($row['mas_max'] ?? 1)),
            'id_table' => (int)($_POST['id_table'] ?? ($row['id_table'] ?? 1)),
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$assignable = WorkweeksRepository::listAssignableStaff();
$tableOptions = WorkweeksRepository::tablesActive()
    ? TablesRepository::names(true)
    : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => WorkweeksRepository::forAgent($row ?: null),
        'assignable' => $assignable,
        'table_options' => $tableOptions,
        'tables_on' => WorkweeksRepository::tablesActive(),
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew
        ? t('workweeks.edit.title_new')
        : t('workweeks.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'assignable' => $assignable,
    'tableOptions' => $tableOptions,
    'tablesOn' => WorkweeksRepository::tablesActive(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('workweeks.menu.list'), 'href' => '/workweeks'],
        [
            'label' => $isNew ? t('workweeks.edit.title_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'workweeks');
