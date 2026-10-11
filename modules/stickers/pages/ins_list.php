<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('stickers.edit');
$isSuper = Auth::capability('SUPERADMIN_ACCESS');

$filterKeys = [
    'q', 'good_q', 'id_act', 'id_good', 'id_stock', 'id_supplier',
    'year', 'month', 'non_act', 'revi', 'qqr', 'weightable', 'is_used',
];
$f = [];
foreach ($filterKeys as $k) {
    $f[$k] = trim((string)($_GET[$k] ?? ''));
}
$idAct = (int)$f['id_act'];
$idGood = (int)$f['id_good'];
$idStock = (int)$f['id_stock'];
$idSupplier = (int)$f['id_supplier'];
$nonAct = (int)$f['non_act'];
$revi = (int)$f['revi'];
$qqr = (int)$f['qqr'];
$weightable = (int)$f['weightable'];
$isUsed = (int)$f['is_used'];
$year = $f['year'] !== '' ? $f['year'] : 'all';
$month = $f['month'] !== '' ? $f['month'] : 'all';

// Oper GOODSEARCH: unique name → pin id_good + show canonical name in good_q.
if ($idGood > 0 && $f['good_q'] !== '' && class_exists('ProductsRepository', false)) {
    $pinned = ProductsRepository::find($idGood);
    $pname = $pinned ? (string)$pinned['name'] : '';
    if ($pname !== '' && strcasecmp($pname, $f['good_q']) !== 0
        && mb_stripos($pname, $f['good_q']) === false) {
        // Typed query no longer matches pinned product — re-resolve.
        $idGood = 0;
        $f['id_good'] = '';
    }
}
if ($idGood < 1 && $f['good_q'] !== '') {
    $resolved = StickerInsRepository::resolveGoodQuery($f['good_q']);
    if ($resolved !== null) {
        $idGood = (int)$resolved['id'];
        $f['good_q'] = (string)$resolved['name'];
        $f['id_good'] = (string)$idGood;
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
            && (int)($_GET['id_good'] ?? 0) !== $idGood) {
            $qs = $_GET;
            $qs['id_good'] = $idGood;
            $qs['good_q'] = $resolved['name'];
            redirect('/stickers/ins?' . http_build_query($qs));
        }
    }
} elseif ($idGood > 0 && $f['good_q'] === '' && class_exists('ProductsRepository', false)) {
    $p = ProductsRepository::find($idGood);
    if ($p) {
        $f['good_q'] = (string)$p['name'];
    }
}

// Stock-wide ops (oper do=4/6/7/8) — need id_stock in filter; not selection-based.
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST' && $canEdit && trim((string)($_POST['stock_op'] ?? '')) !== '') {
    $op = trim((string)$_POST['stock_op']);
    $stockForOp = (int)($_POST['id_stock'] ?? $idStock);
    $uid = Auth::userId();
    $result = ['done' => 0, 'skipped' => [], 'action' => $op];
    if ($stockForOp < 1) {
        $result['error'] = t('stickers.bulk.err.stock_required');
    } elseif (in_array($op, ['revi_on', 'move_on'], true)
        && (StickerInsOps::stockHasRevision() || StickerInsOps::stockHasMovement())) {
        $result['error'] = t('stickers.bulk.err.mode_busy');
    } else {
        switch ($op) {
            case 'revi_on':
                $result = array_merge($result, StickerInsOps::enableRevisionForStock($stockForOp, $uid));
                break;
            case 'revi_off':
                if (!$isSuper) {
                    $result['error'] = t('stickers.bulk.err.superadmin');
                } else {
                    $result = array_merge($result, StickerInsOps::disableRevisionForStock($stockForOp, $uid));
                }
                break;
            case 'move_on':
                $result = array_merge($result, StickerInsOps::enableMovementForStock($stockForOp, $uid));
                break;
            case 'move_off':
                $result = array_merge($result, StickerInsOps::disableMovementForStock($stockForOp, $uid));
                break;
            default:
                $result['error'] = t('stickers.bulk.err.unknown');
        }
    }
    BulkActions::redirectWithResult('/stickers/ins?' . http_build_query(array_filter([
        'id_stock' => $stockForOp > 0 ? $stockForOp : null,
        'year' => $year !== 'all' ? $year : null,
        'month' => $month !== 'all' ? $month : null,
        'q' => $f['q'] !== '' ? $f['q'] : null,
        'good_q' => $f['good_q'] !== '' ? $f['good_q'] : null,
        'id_act' => $idAct > 0 ? $idAct : null,
        'id_good' => $idGood > 0 ? $idGood : null,
        'id_supplier' => $idSupplier > 0 ? $idSupplier : null,
        'non_act' => $nonAct > 0 ? $nonAct : null,
        'revi' => $revi > 0 ? $revi : null,
        'qqr' => $qqr > 0 ? $qqr : null,
        'weightable' => $weightable > 0 ? $weightable : null,
        'is_used' => $isUsed > 0 ? $isUsed : null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    })), $result);
}

$lq = ListQuery::resolve([
    'list_id' => 'stickers_ins',
    'allowed' => StickerInsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge([
    'q' => $f['q'],
    'good_q' => $f['good_q'],
    'id_act' => $idAct ?: '',
    'id_good' => $idGood ?: '',
    'id_stock' => $idStock ?: '',
    'id_supplier' => $idSupplier ?: '',
    'year' => $year !== 'all' ? $year : '',
    'month' => $month !== 'all' ? $month : '',
    'non_act' => $nonAct ?: '',
    'revi' => $revi ?: '',
    'qqr' => $qqr ?: '',
    'weightable' => $weightable ?: '',
    'is_used' => $isUsed ?: '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('stickers_ins', '/stickers/ins', $filters);

$listOpts = [
    'q' => $f['q'],
    'good_q' => $f['good_q'],
    'id_act' => $idAct > 0 ? $idAct : null,
    'id_good' => $idGood > 0 ? $idGood : null,
    'id_stock' => $idStock > 0 ? $idStock : null,
    'id_supplier' => $idSupplier > 0 ? $idSupplier : null,
    'year' => $year,
    'month' => $month,
    'non_act' => $nonAct,
    'revi' => $revi,
    'qqr' => $qqr,
    'weightable' => $weightable,
    'is_used' => $isUsed,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
];
$result = StickerInsRepository::list($listOpts);
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];
$materials = [];
if (class_exists('ProductsRepository', false)) {
    $matList = ProductsRepository::list([
        'is_material' => 1,
        'limit' => 400,
        'offset' => 0,
        'order' => 'p.name ASC',
    ]);
    foreach ($matList['items'] ?? [] as $mp) {
        $materials[(int)$mp['id']] = (string)$mp['name'];
    }
    if ($materials === []) {
        // Legacy: many consumables lack is_material — suggest from any product names.
        foreach (array_slice($products, 0, 400, true) as $pid => $pname) {
            $materials[(int)$pid] = (string)$pname;
        }
    }
}
$stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];
$suppliers = class_exists('SuppliersRepository', false) ? SuppliersRepository::names(false) : [];
$units = class_exists('UnitsRepository', false) ? UnitsRepository::names() : [];
$productUnits = [];
if (class_exists('ProductsRepository', false) && $result['items'] !== []) {
    foreach ($result['items'] as $it) {
        $gid = (int)$it['id_good'];
        if ($gid > 0 && !isset($productUnits[$gid])) {
            $pr = ProductsRepository::find($gid);
            if ($pr) {
                $productUnits[$gid] = [
                    'unit' => $units[(int)($pr['unit_id'] ?? 0)] ?? '',
                    'unitu' => $units[(int)($pr['unit_use_id'] ?? 0)] ?? '',
                ];
            }
        }
    }
}
$years = StickerInsRepository::listYears();

$stockOps = null;
if ($canEdit && $idStock > 0) {
    $reviOn = StickerInsOps::stockHasRevision();
    $moveOn = StickerInsOps::stockHasMovement();
    $avail = StickerInsOps::hasActivatedAvailable();
    $stockOps = [
        'id_stock' => $idStock,
        'stock_name' => $stocks[$idStock] ?? ('#' . $idStock),
        'avail' => $avail,
        'revi_on' => $reviOn,
        'move_on' => $moveOn,
        'can_revi_on' => $avail && !$reviOn && !$moveOn,
        'can_revi_off' => $isSuper && $reviOn,
        'can_move_on' => $avail && !$reviOn && !$moveOn,
        'can_move_off' => $moveOn,
        'is_super' => $isSuper,
    ];
}

$sums = $result['sums'] ?? StickerInsRepository::emptySums();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'sums' => $sums,
        'filters' => $listOpts,
    'products' => $products,
    'materials' => $materials,
    'stocks' => $stocks,
    'suppliers' => $suppliers,
    'product_units' => $productUnits,
    'years' => $years,
    'page' => $lq['page'],
    'per_page' => $lq['per_page'],
    'show_all' => $lq['show_all'],
    'stock_ops' => $stockOps,
    ]);
    return;
}

View::render('ins_list', [
    'title' => t('stickers.menu.ins'),
    'items' => $result['items'],
    'total' => $result['total'],
    'sums' => $sums,
    'f' => $f,
    'year' => $year,
    'month' => $month,
    'idAct' => $idAct,
    'idGood' => $idGood,
    'idStock' => $idStock,
    'idSupplier' => $idSupplier,
    'nonAct' => $nonAct,
    'revi' => $revi,
    'qqr' => $qqr,
    'weightable' => $weightable,
    'isUsed' => $isUsed,
    'years' => $years,
    'products' => $products,
    'materials' => $materials,
    'stocks' => $stocks,
    'suppliers' => $suppliers,
    'productUnits' => $productUnits,
    'stockOps' => $stockOps,
    'canEdit' => $canEdit,
    'canWriteoff' => Auth::can('stickers.writeoff'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'stickers');
