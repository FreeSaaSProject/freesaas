<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$defaultHost = SiteHostsRepository::findDefault();
$row = [
    'enabled' => 1,
    'num' => 1,
    'parent_to' => isset($_GET['pid']) ? (int)$_GET['pid'] : PageCategoriesRepository::PARENT_ROOT,
    'site_host_id' => (int)($defaultHost['id'] ?? 0),
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
            'message' => t('sites.pages.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';
$hasChildren = !$isNew && PageCategoriesRepository::childCount($id) > 0;


if (!$isNew && EntityDelete::tryHandle('sites_pages', $id, '/sites/pages', [
    'stay_path' => '/sites/pages/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = PageCategoriesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = PageCategoriesRepository::create($data);
            redirect('/sites/pages/' . $newId . '/edit?saved=1');
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
            'site_host_id' => (int)($_POST['site_host_id'] ?? 0),
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
        ? t('sites.pages.edit.title_new')
        : t('sites.pages.edit.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'hasChildren' => $hasChildren,
    'parentOptions' => PageCategoriesRepository::parentOptions($isNew ? null : $id),
    'hostOptions' => SiteHostsRepository::options(true),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.pages'), 'href' => '/sites/pages'],
        [
            'label' => $isNew
                ? t('sites.pages.edit.title_new')
                : (string)($row['name'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
], 'layout', 'sites');
