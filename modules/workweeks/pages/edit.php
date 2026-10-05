<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

if (!WorkweeksRepository::canAssign()) {
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
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('workweeks.err.not_found'),
        ]);
        return;
    }
    if (!WorkweeksRepository::canAssignOperator((int)$found['id_operator'])) {
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = WorkweeksRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = WorkweeksRepository::createFromForm($data);
            $url = '/workweeks/' . $newId . '/edit?saved=1';
            $ret = NavContext::returnUrl();
            if ($ret !== '') {
                $url .= '&ret=' . rawurlencode($ret);
            }
            redirect($url);
            return;
        }
        WorkweeksRepository::updateFromForm($id, $data);
        $message = t('core.common.saved');
        $found = WorkweeksRepository::find($id);
        if ($found) {
            $row = $found;
            $row['date'] = date('Y-m-d', (int)$found['dt']);
        }
    } catch (Throwable $e) {
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
