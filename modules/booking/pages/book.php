<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$body = [];
if ($wantsJson) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
}
if ($body === []) {
    $body = $_POST;
}

$productIds = [];
if (isset($body['product_ids']) && is_array($body['product_ids'])) {
    foreach ($body['product_ids'] as $v) {
        $id = (int)$v;
        if ($id > 0) {
            $productIds[] = $id;
        }
    }
} elseif (!empty($body['product_ids'])) {
    foreach (preg_split('/[,\s]+/', (string)$body['product_ids']) ?: [] as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $productIds[] = $id;
        }
    }
}

try {
    $result = BookingBook::createOrder([
        'phone' => (string)($body['phone'] ?? ''),
        'client_id' => (int)($body['client_id'] ?? 0),
        'client_name' => (string)($body['client_name'] ?? ''),
        'product_ids' => $productIds,
        'operator_id' => (int)($body['operator_id'] ?? 0),
        'dt_start' => (int)($body['dt_start'] ?? 0),
        'comment' => (string)($body['comment'] ?? ''),
        'mail' => (string)($body['mail'] ?? ''),
        'free_ticket' => !empty($body['free_ticket']) ? 1 : 0,
    ]);
} catch (InvalidArgumentException $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    http_response_code(400);
    echo e($e->getMessage());
    return;
} catch (RuntimeException $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 500);
    }
    http_response_code(500);
    echo e($e->getMessage());
    return;
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'booking' => $result]);
}

redirect('/orders/' . (int)$result['order_id']);
