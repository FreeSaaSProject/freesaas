<?php
declare(strict_types=1);

/**
 * Public storefront JSON for cart booking wizard.
 * Modes: day (default) | masters | dates
 */
if (!SitesBooking::active()) {
    SitesCart::emitJson(['ok' => false, 'error' => t('sites.cart.err_booking')], 503);
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'day')));
if (!in_array($mode, ['day', 'masters', 'dates'], true)) {
    $mode = 'day';
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

if ($mode === 'masters') {
    try {
        $masters = SitesBooking::listMastersAhead($productIds);
    } catch (Throwable $e) {
        SitesCart::emitJson(['ok' => false, 'error' => t('sites.cart.err_availability')], 500);
    }
    SitesCart::emitJson([
        'ok' => true,
        'mode' => 'masters',
        'operators' => $masters,
    ]);
}

if ($mode === 'dates') {
    // operator_id=0 → any master (dates that have at least one free slot).
    $operatorId = (int)($_GET['operator_id'] ?? 0);
    try {
        $dates = SitesBooking::listDatesForOperator($productIds, $operatorId);
    } catch (Throwable $e) {
        SitesCart::emitJson(['ok' => false, 'error' => t('sites.cart.err_availability')], 500);
    }
    SitesCart::emitJson([
        'ok' => true,
        'mode' => 'dates',
        'operator_id' => $operatorId,
        'dates' => $dates,
    ]);
}

$date = trim((string)($_GET['date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
}
$operatorId = isset($_GET['operator_id']) ? (int)$_GET['operator_id'] : 0;

try {
    $data = BookingAvailability::forDay(
        $date,
        $productIds,
        $operatorId > 0 ? $operatorId : null,
        null,
        false,
        SitesBooking::leadSec()
    );
} catch (InvalidArgumentException $e) {
    SitesCart::emitJson(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    SitesCart::emitJson(['ok' => false, 'error' => t('sites.cart.err_availability')], 500);
}

SitesCart::emitJson([
    'ok' => true,
    'mode' => 'day',
    'date' => (string)($data['date'] ?? $date),
    'total_time' => (int)($data['total_time'] ?? 0),
    'empty_reason' => (string)($data['empty_reason'] ?? ''),
    'operators' => SitesBooking::publicOperatorsFromDay($data),
]);
