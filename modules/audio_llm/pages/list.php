<?php
declare(strict_types=1);

$kind = trim((string)($_GET['kind'] ?? $_POST['kind'] ?? 'regular'));
if (!in_array($kind, ['regular', 'cyclic', 'spawned'], true)) {
    $kind = 'regular';
}

$path = '/audio/llm-studies';
$listId = 'audio_llm_studies';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Auth::can('audio_llm.edit')) {
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $ids = array_values(array_filter($ids, static function ($i) {
        return $i > 0;
    }));
    if (!empty($_POST['delete']) && $ids !== []) {
        foreach ($ids as $id) {
            StudiesRepository::delete($id);
        }
        redirect('/audio/llm-studies?kind=' . rawurlencode($kind) . '&saved=1');
    }
}

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => StudiesRepository::listSortMap(),
    'default' => 'created',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge(['kind' => $kind], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$list = StudiesRepository::list($kind, ListQuery::sqlLimit($lq), (int)$lq['offset']);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'kind' => $kind,
        'total' => $list['total'],
        'items' => $list['items'],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
}

View::render('list', [
    'title' => t('audio_llm.list.title'),
    'kind' => $kind,
    'path' => $path,
    'items' => $list['items'],
    'total' => $list['total'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'message' => !empty($_GET['saved']) ? t('core.common.saved') : '',
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio_llm.menu.studies'), 'current' => true],
    ]),
], 'layout', 'audio_llm');
