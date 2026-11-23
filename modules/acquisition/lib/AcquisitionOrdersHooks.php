<?php
declare(strict_types=1);

/** Auto stub call on successful advance order (site / mcp / api). */
final class AcquisitionOrdersHooks
{
    public static function register(): void
    {
        EventBus::on('orders.created', [self::class, 'onOrderCreated']);
    }

    /** @param mixed $payload @return mixed */
    public static function onOrderCreated($payload)
    {
        if (!is_array($payload) || !CallsRepository::tableReady()) {
            return $payload;
        }
        $orderId = (int)($payload['order_id'] ?? $payload['id'] ?? 0);
        if ($orderId < 1) {
            return $payload;
        }
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : null;
        if ($order === null && class_exists('OrdersRepository', false)) {
            $order = OrdersRepository::find($orderId);
        }
        if (!is_array($order)) {
            return $payload;
        }
        $phone = CallsRepository::normalizePhone((string)($order['phone'] ?? $order['tel'] ?? ''));
        $source = trim((string)($order['source'] ?? 'site'));
        try {
            CallsRepository::upsertIngest([
                'provider' => 'orders',
                'provider_call_id' => 'order:' . $orderId,
                'phone' => $phone,
                'client_id' => (int)($order['id_user'] ?? $order['client_id'] ?? 0),
                'admin_id' => 0,
                'direction' => CallsRepository::TYPE_IN,
                'channel' => $source !== '' ? $source : 'site_order',
                'result_id' => CallsRepository::RESULT_BOOKED,
                'dt_filled' => time(),
                'dt_call' => (int)($order['dt_event'] ?? $order['dt_create'] ?? time()),
                'request' => t('acquisition.calls.auto.order_request'),
                'result_text' => t('acquisition.calls.auto.order_result'),
                'order_id' => $orderId,
                'client_kind' => CallsRepository::KIND_UNKNOWN,
            ]);
        } catch (Throwable $e) {
            // Soft: never break order create.
        }
        return $payload;
    }
}
