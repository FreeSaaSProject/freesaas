<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$date = trim((string)($_GET['date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
}
$rawIds = trim((string)($_GET['product_ids'] ?? $_GET['products'] ?? ''));
$productIds = [];
if ($rawIds !== '') {
    foreach (preg_split('/[,\s]+/', $rawIds) ?: [] as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $productIds[] = $id;
        }
    }
}
$operatorId = isset($_GET['operator_id']) ? (int)$_GET['operator_id'] : 0;

try {
    $data = BookingAvailability::forDay(
        $date,
        $productIds,
        $operatorId > 0 ? $operatorId : null
    );
} catch (InvalidArgumentException $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    http_response_code(400);
    echo e($e->getMessage());
    return;
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'availability' => $data]);
}

View::render('availability', [
    'title' => t('booking.module.name'),
    'availability' => $data,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('booking.module.name'), 'current' => true],
    ]),
], 'layout', 'booking');
