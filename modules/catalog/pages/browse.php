<?php
declare(strict_types=1);

$folderId = (int)(Router::param('id') ?? 0);
$path = $folderId > 0 ? '/catalog/' . $folderId : '/catalog';

$canEdit = Auth::can('catalog.edit');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'toggle_product') {
            ProductsRepository::setEnabled(
                (int)($_POST['id'] ?? 0),
                !empty($_POST['enabled'])
            );
        } elseif ($action === 'toggle_category') {
            CategoriesRepository::setHidden(
                (int)($_POST['id'] ?? 0),
                empty($_POST['visible'])
            );
        }
    } catch (Throwable $e) {
        // fall through — flash via redirect query not needed
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect($path . ($qs !== '' ? '?' . $qs : ''));
    return;
}

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');
if (!in_array($enabled, ['', '0', '1'], true)) {
    $enabled = '';
}
$isMaterial = (string)($_GET['is_material'] ?? '');
if (!in_array($isMaterial, ['', '0', '1'], true)) {
    $isMaterial = '';
}

$folder = null;
$breadcrumbs = [
    [
        'label' => t('catalog.menu.browse'),
        'href' => '/catalog',
        'current' => $folderId === 0,
    ],
];
if ($folderId > 0) {
    $folder = CategoriesRepository::find($folderId);
    if (!$folder) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('catalog.cat.err.not_found', ['id' => $folderId]),
        ]);
        return;
    }
    foreach (CategoriesRepository::breadcrumbs($folderId) as $c) {
        $cid = (int)$c['id'];
        $isCurrent = $cid === $folderId;
        $breadcrumbs[] = [
            'label' => (string)$c['name'],
            'href' => $isCurrent ? null : '/catalog/' . $cid,
            'current' => $isCurrent,
        ];
    }
}
$breadcrumbs = Breadcrumbs::withHome($breadcrumbs);

$folders = CategoriesRepository::childrenOf($folderId, $q);

// Global search from root: show matching products across catalog.
$searchGlobal = ($q !== '' && $folderId === 0);

$lq = ListQuery::resolve([
    'list_id' => 'catalog_browse',
    'allowed' => ProductsRepository::listSortMap(),
    'default' => 'num',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$orderSql = $lq['order_sql'];
if (($lq['sort'] ?? '') === 'num') {
    $dir = strtoupper((string)($lq['dir'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';
    $orderSql = 'p.num ' . $dir . ', p.name ASC';
}

$listOpts = [
    'q' => $q,
    'enabled' => $enabled === '' ? null : $enabled,
    'is_material' => $isMaterial === '' ? null : $isMaterial,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $orderSql,
];
if ($searchGlobal) {
    // no folder filter — search all products
} else {
    $listOpts['folder'] = $folderId;
}

$result = ProductsRepository::list($listOpts);

$productCountInFolder = $searchGlobal
    ? $result['total']
    : CategoriesRepository::countProducts($folderId);
$folderCount = count($folders);
// Ignoring filters for add-button rules: use unfiltered folder content.
if (!$searchGlobal && ($q !== '' || $enabled !== '' || $isMaterial !== '')) {
    $folderCount = CategoriesRepository::countChildren($folderId);
    $productCountInFolder = CategoriesRepository::countProducts($folderId);
}

$canAddCategory = $canEdit && $productCountInFolder < 1;
$canAddProduct = $canEdit && $folderCount < 1;

$priceCols = ProductsRepository::varyingPriceColumns(
    $searchGlobal ? null : $folderId,
    $searchGlobal
);

$filters = array_merge([
    'q' => $q,
    'enabled' => $enabled,
    'is_material' => $isMaterial,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$title = $folder
    ? (string)$folder['name']
    : t('catalog.menu.browse');

$message = '';
if (isset($_GET['saved'])) {
    $message = t('core.common.saved');
}

View::render('browse', [
    'title' => $title,
    'folderId' => $folderId,
    'folder' => $folder,
    'breadcrumbs' => $breadcrumbs,
    'breadcrumbs_aria' => t('catalog.browse.folder_aria'),
    'folders' => $folders,
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'isMaterial' => $isMaterial,
    'searchGlobal' => $searchGlobal,
    'canEdit' => $canEdit,
    'canAddCategory' => $canAddCategory,
    'canAddProduct' => $canAddProduct,
    'priceCols' => $priceCols,
    'path' => $path,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'message' => $message,
], 'layout', 'catalog');
