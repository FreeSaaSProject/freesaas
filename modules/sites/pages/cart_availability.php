<?php
declare(strict_types=1);

/**
 * Public storefront JSON: day availability for cart product ids.
 * Slot engine lives in booking — this page only wires the request.
 */
if (!SitesBooking::active()) {
    SitesCart::emitJson(['ok' => false, 'error' => t('sites.cart.err_booking')], 503);
}

$date = trim((string)($_GET['date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
}

$rawIds = trim((string)($_GET['product_ids'] ?? ''));
$productIds = [];
if ($rawIds !== '') {
    foreach (preg_split('/[,\s]+/', $rawIds) ?: [] as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $productIds[] = $id;
        }
    }
}
if ($productIds === []) {
    $productIds = SitesBooking::productIdsFlat();
}
if ($productIds === []) {
    SitesCart::emitJson(['ok' => false, 'error' => t('sites.cart.err_empty')], 400);
}

$operatorId = isset($_GET['operator_id']) ? (int)$_GET['operator_id'] : 0;

try {
    $data = BookingAvailability::forDay(
        $date,
        $productIds,
        $operatorId > 0 ? $operatorId : null
    );
} catch (InvalidArgumentException $e) {
    SitesCart::emitJson(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    SitesCart::emitJson(['ok' => false, 'error' => t('sites.cart.err_availability')], 500);
}

$ops = [];
foreach ($data['operators'] ?? [] as $op) {
    if (empty($op['available']) || empty($op['slots'])) {
        continue;
    }
    $ops[] = [
        'id' => (int)$op['id'],
        'name' => (string)$op['name'],
        'slots' => array_values(array_map('intval', $op['slots'] ?? [])),
        'slots_hhmm' => array_values($op['slots_hhmm'] ?? []),
    ];
}

SitesCart::emitJson([
    'ok' => true,
    'date' => (string)($data['date'] ?? $date),
    'total_time' => (int)($data['total_time'] ?? 0),
    'empty_reason' => (string)($data['empty_reason'] ?? ''),
    'operators' => $ops,
]);
