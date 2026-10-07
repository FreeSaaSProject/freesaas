<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [
    'enabled' => 1,
    'num' => 1,
    'parent_to' => isset($_GET['pid']) ? (int)$_GET['pid'] : PageCategoriesRepository::PARENT_ROOT,
    'name' => '',
    'link' => '',
    'link_rw' => '',
    'bg_url' => '',
    'content' => '',
];
if (!$isNew) {
    $found = PageCategoriesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('likespa.pages.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
$hasChildren = !$isNew && PageCategoriesRepository::childCount($id) > 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = PageCategoriesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = PageCategoriesRepository::create($data);
            redirect('/likespa/pages/' . $newId . '/edit?saved=1');
            return;
        }
        PageCategoriesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = PageCategoriesRepository::find($id) ?: $row;
        $hasChildren = PageCategoriesRepository::childCount($id) > 0;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'name' => (string)($_POST['name'] ?? ''),
            'num' => (int)($_POST['num'] ?? 1),
            'parent_to' => (int)($_POST['parent_to'] ?? 0),
            'link' => (string)($_POST['link'] ?? ''),
            'link_rw' => (string)($_POST['link_rw'] ?? ''),
            'bg_url' => (string)($_POST['bg_url'] ?? ''),
            'content' => (string)($_POST['content'] ?? ''),
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('page_edit', [
    'title' => $isNew
        ? t('likespa.pages.edit.title_new')
        : t('likespa.pages.edit.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'hasChildren' => $hasChildren,
    'parentOptions' => PageCategoriesRepository::parentOptions($isNew ? null : $id),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.pages'), 'href' => '/likespa/pages'],
        [
            'label' => $isNew
                ? t('likespa.pages.edit.title_new')
                : (string)($row['name'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
], 'layout', 'likespa');
