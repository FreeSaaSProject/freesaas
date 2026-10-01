<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = CategoriesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('catalog.cat.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $parentPrefill = (int)($_GET['parent'] ?? ($_POST['parent_id'] ?? 0));
    if ($parentPrefill > 0) {
        $row['parent_id'] = $parentPrefill;
    }
    if (CategoriesRepository::countProducts($parentPrefill) > 0) {
        $back = $parentPrefill > 0 ? '/catalog/' . $parentPrefill : '/catalog';
        redirect($back);
        return;
    }
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = CategoriesRepository::dataFromPost($_POST);
        if ($isNew) {
            if (CategoriesRepository::countProducts((int)$data['parent_id']) > 0) {
                throw new InvalidArgumentException(t('catalog.browse.err.products_block_category'));
            }
            $newId = CategoriesRepository::create($data);
            $back = (int)$data['parent_id'] > 0
                ? '/catalog/' . (int)$data['parent_id']
                : '/catalog';
            redirect($back . '?saved=1');
            return;
        }
        CategoriesRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = CategoriesRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$parentOptions = CategoriesRepository::names();
if (!$isNew) {
    unset($parentOptions[$id]);
}

$backFolderId = (int)($row['parent_id'] ?? 0);
$backHref = $backFolderId > 0 ? '/catalog/' . $backFolderId : '/catalog';
if (!$isNew) {
    $backHref = '/catalog/' . $id;
}

$breadcrumbs = [
    ['label' => t('catalog.menu.browse'), 'href' => '/catalog'],
];
$chainId = $isNew ? $backFolderId : (int)($row['parent_id'] ?? 0);
if ($chainId > 0) {
    foreach (CategoriesRepository::breadcrumbs($chainId) as $c) {
        $cid = (int)$c['id'];
        $breadcrumbs[] = [
            'label' => (string)$c['name'],
            'href' => '/catalog/' . $cid,
        ];
    }
}
$breadcrumbs[] = [
    'label' => $isNew
        ? t('catalog.cat.title_new')
        : (string)($row['name'] ?? ('#' . $id)),
    'current' => true,
];
$breadcrumbs = Breadcrumbs::withHome($breadcrumbs);

View::render('category_edit', [
    'title' => $isNew
        ? t('catalog.cat.title_new')
        : t('catalog.cat.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'parentOptions' => $parentOptions,
    'backHref' => $backHref,
    'breadcrumbs' => $breadcrumbs,
    'breadcrumbs_aria' => t('catalog.browse.folder_aria'),
], 'layout', 'catalog');
