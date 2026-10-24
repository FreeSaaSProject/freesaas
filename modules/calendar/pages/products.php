<?php
declare(strict_types=1);

/**
 * Product search for event basket picker (staff-filtered when booking gate available).
 * GET /calendar/products?q=&staff=&date=&keep[]=
 */
$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(40, (int)($_GET['limit'] ?? 15)));
$staffId = max(0, (int)($_GET['staff'] ?? 0));
$dateRaw = trim((string)($_GET['date'] ?? ''));
$dayStart = DemoMode::dayStart();
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
    $ts = strtotime($dateRaw . ' 00:00:00');
    if ($ts !== false) {
        $dayStart = (int)$ts;
    }
}
$keepRaw = $_GET['keep'] ?? [];
$keepIds = [];
if (is_array($keepRaw)) {
    foreach ($keepRaw as $kid) {
        $kid = (int)$kid;
        if ($kid > 0) {
            $keepIds[] = $kid;
        }
    }
} elseif (is_string($keepRaw) && $keepRaw !== '') {
    foreach (explode(',', $keepRaw) as $kid) {
        $kid = (int)trim($kid);
        if ($kid > 0) {
            $keepIds[] = $kid;
        }
    }
}

if (!class_exists(ProductsRepository::class, false)) {
    AgentHttp::emit(['ok' => true, 'q' => $q, 'items' => [], 'staff' => $staffId]);
    return;
}

$pack = static function (array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'price' => (float)($row['price'] ?? $row['price3'] ?? 0),
        'time_sec' => max(0, (int)($row['time_sec'] ?? 0)),
        'type_id' => max(0, (int)($row['type_id'] ?? 0)),
        'is_material' => !empty($row['is_material']) ? 1 : 0,
        'uses' => (int)($row['uses'] ?? 0),
    ];
};

$mode = trim((string)($_GET['mode'] ?? ''));
if ($mode === 'catalog' || $mode === 'all') {
    $catalogLimit = max($limit, min(500, (int)($_GET['limit'] ?? 500)));
    $items = [];
    if ($staffId > 0 && class_exists(BookingCatalog::class, false)) {
        foreach (BookingCatalog::listServicesForOperator($staffId, $dayStart, $keepIds, $catalogLimit) as $row) {
            $items[] = $pack($row);
        }
    } elseif (class_exists(BookingCatalog::class, false)) {
        foreach (BookingCatalog::listServices($catalogLimit) as $row) {
            $items[] = $pack($row);
        }
    }
    AgentHttp::emit([
        'ok' => true,
        'q' => $q,
        'items' => $items,
        'mode' => 'catalog',
        'staff' => $staffId,
        'total' => count($items),
    ]);
    return;
}

if ($q === '') {
    $freq = EventItemsRepository::frequentGoods($limit, $staffId, $dayStart, $keepIds);
    AgentHttp::emit([
        'ok' => true,
        'q' => '',
        'items' => array_map($pack, $freq),
        'frequent' => true,
        'staff' => $staffId,
    ]);
    return;
}

$items = [];
if ($staffId > 0 && class_exists(BookingCatalog::class, false)) {
    $services = BookingCatalog::listServicesForOperator($staffId, $dayStart, $keepIds, 2000);
    $ql = mb_strtolower($q);
    foreach ($services as $row) {
        $name = (string)($row['name'] ?? '');
        if ($ql !== '' && mb_stripos($name, $q) === false && strpos((string)($row['id'] ?? ''), $q) === false) {
            continue;
        }
        $items[] = $pack($row);
        if (count($items) >= $limit) {
            break;
        }
    }
} else {
    $result = ProductsRepository::list([
        'q' => $q,
        'enabled' => 1,
        'is_material' => '0',
        'limit' => $limit,
        'offset' => 0,
    ]);
    foreach ($result['items'] as $row) {
        $items[] = $pack($row);
    }
}

AgentHttp::emit([
    'ok' => true,
    'q' => $q,
    'items' => $items,
    'total' => count($items),
    'staff' => $staffId,
]);
