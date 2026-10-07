<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [];
if (!$isNew) {
    $found = ProductsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('catalog.product.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('catalog.product.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $catPrefill = (int)($_GET['category'] ?? ($_POST['category'] ?? 0));
    if ($catPrefill > 0) {
        $row['category'] = $catPrefill;
    }
    if (CategoriesRepository::countChildren($catPrefill) > 0) {
        if ($wantsJson) {
            AgentHttp::error(t('catalog.browse.err.folders_block_product'), 400);
            return;
        }
        $back = $catPrefill > 0 ? '/catalog/' . $catPrefill : '/catalog';
        redirect($back);
        return;
    }
}

$message = '';
$error = '';
$relations = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'add_relation' && !$isNew) {
            ProductRelationsRepository::add(
                $id,
                (int)($_POST['related_id'] ?? 0),
                (string)($_POST['rel_type'] ?? 'addon'),
                trim((string)($_POST['benefit'] ?? ''))
            );
            $message = t('catalog.rel.added');
        } elseif ($action === 'delete_relation' && !$isNew) {
            ProductRelationsRepository::delete((int)($_POST['relation_id'] ?? 0), $id);
            $message = t('catalog.rel.deleted');
        } elseif ($action === 'toggle_relation' && !$isNew) {
            ProductRelationsRepository::setEnabled(
                (int)($_POST['relation_id'] ?? 0),
                $id,
                !empty($_POST['rel_enabled'])
            );
            $message = t('core.common.saved');
        } elseif ($action === 'recalc_components' && !$isNew) {
            if (!ProductsRepository::applyComponentTotals($id)) {
                throw new InvalidArgumentException(t('catalog.rel.err.no_components'));
            }
            $message = t('catalog.rel.recalculated');
            $row = ProductsRepository::find($id) ?: $row;
        } else {
            $data = ProductsRepository::dataFromPost($_POST);
            if ($isNew) {
                if (CategoriesRepository::countChildren((int)$data['category']) > 0) {
                    throw new InvalidArgumentException(t('catalog.browse.err.folders_block_product'));
                }
                $newId = ProductsRepository::create($data);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'id' => $newId,
                        'item' => ProductsRepository::find($newId),
                    ]);
                    return;
                }
                redirect('/products/' . $newId . '/edit?saved=1');
                return;
            }
            ProductsRepository::update($id, $data);
            $message = t('core.common.saved');
            $row = ProductsRepository::find($id) ?: $row;
        }
        if ($wantsJson) {
            $relations = ProductRelationsRepository::forProduct($id, true);
            $row = ProductsRepository::find($id) ?: $row;
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'action' => $action,
                'item' => $row,
                'relations' => $relations,
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        if ($action === 'save') {
            $row = array_merge($row, $_POST);
        }
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

if (!$isNew) {
    $relations = ProductRelationsRepository::forProduct($id, true);
    $row = ProductsRepository::find($id) ?: $row;
}

$unitNames = [];
if (class_exists('UnitsRepository', false) || is_file(base_path('modules/units/lib/UnitsRepository.php'))) {
    if (!class_exists('UnitsRepository', false)) {
        require_once base_path('modules/units/lib/UnitsRepository.php');
    }
    if (Database::tableExists('base_units')) {
        $unitNames = UnitsRepository::names();
    }
}
$supplierNames = [];
if (class_exists('SuppliersRepository', false) || is_file(base_path('modules/suppliers/lib/SuppliersRepository.php'))) {
    if (!class_exists('SuppliersRepository', false)) {
        require_once base_path('modules/suppliers/lib/SuppliersRepository.php');
    }
    if (Database::tableExists('base_suppliers')) {
        $supplierNames = SuppliersRepository::names(false);
    }
}
$procedureTypeNames = [];
if (class_exists('ProcedureTypesRepository', false) || is_file(base_path('modules/procedures/lib/ProcedureTypesRepository.php'))) {
    if (!class_exists('ProcedureTypesRepository', false)) {
        require_once base_path('modules/procedures/lib/ProcedureTypesRepository.php');
    }
    if (Database::tableExists('base_procedure_types')) {
        $procedureTypeNames = ProcedureTypesRepository::names();
    }
}

$lastAdminName = '';
$lastAdminId = (int)($row['last_admin_id'] ?? 0);
if ($lastAdminId > 0 && Database::tableExists('base_users')) {
    $st = Database::pdo()->prepare('SELECT name, login FROM base_users WHERE id = ? LIMIT 1');
    $st->execute([$lastAdminId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $lastAdminName = trim((string)($u['name'] ?: $u['login']));
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'relations' => $relations,
        'categories' => CategoriesRepository::names(),
        'units' => $unitNames,
        'suppliers' => $supplierNames,
        'procedure_types' => $procedureTypeNames,
        'product_options' => $isNew ? [] : ProductsRepository::names(false),
        'last_admin_name' => $lastAdminName,
    ]);
    return;
}

$backCat = (int)($row['category'] ?? 0);
$backHref = $backCat > 0 ? '/catalog/' . $backCat : '/catalog';

$breadcrumbs = [
    ['label' => t('catalog.menu.browse'), 'href' => '/catalog'],
];
if ($backCat > 0) {
    foreach (CategoriesRepository::breadcrumbs($backCat) as $c) {
        $cid = (int)$c['id'];
        $breadcrumbs[] = [
            'label' => (string)$c['name'],
            'href' => '/catalog/' . $cid,
        ];
    }
}
$breadcrumbs[] = [
    'label' => $isNew
        ? t('catalog.product.title_new')
        : (string)($row['name'] ?? ('#' . $id)),
    'current' => true,
];
$breadcrumbs = Breadcrumbs::withHome($breadcrumbs);

View::render('product_edit', [
    'title' => $isNew
        ? t('catalog.product.title_new')
        : t('catalog.product.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'categories' => CategoriesRepository::names(),
    'unitNames' => $unitNames,
    'supplierNames' => $supplierNames,
    'procedureTypeNames' => $procedureTypeNames,
    'relations' => $relations,
    'productOptions' => $isNew ? [] : ProductsRepository::names(false),
    'lastAdminName' => $lastAdminName,
    'backHref' => $backHref,
    'breadcrumbs' => $breadcrumbs,
    'breadcrumbs_aria' => t('catalog.browse.folder_aria'),
], 'layout', 'catalog');
