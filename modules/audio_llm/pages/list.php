<?php
declare(strict_types=1);

$kind = trim((string)($_GET['kind'] ?? $_POST['kind'] ?? 'regular'));
if (!in_array($kind, ['regular', 'cyclic', 'spawned'], true)) {
    $kind = 'regular';
}

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

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;
$list = StudiesRepository::list($kind, $per, ($page - 1) * $per);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'kind' => $kind,
        'total' => $list['total'],
        'items' => $list['items'],
    ]);
}

View::render('list', [
    'title' => t('audio_llm.list.title'),
    'kind' => $kind,
    'items' => $list['items'],
    'total' => $list['total'],
    'page' => $page,
    'per' => $per,
    'message' => !empty($_GET['saved']) ? t('core.common.saved') : '',
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio_llm.menu.studies'), 'current' => true],
    ]),
], 'layout', 'audio_llm');
