<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class Acquiring
{
    /**
     * @return array{ok:bool,payment_id?:int,pay_url?:string,provider?:string,error?:string}
     */
    public static function createForOrder(int $orderId, ?string $providerId = null, string $returnUrl = '/cabinet'): array
    {
        if ($orderId < 1 || !class_exists('OrdersRepository', false)) {
            return ['ok' => false, 'error' => t('acquiring.err.order')];
        }
        $order = OrdersRepository::find($orderId);
        if ($order === null) {
            return ['ok' => false, 'error' => t('acquiring.err.order')];
        }
        if (AcquiringPaymentsRepository::isOrderPaid($orderId)) {
            return ['ok' => true, 'pay_url' => $returnUrl, 'provider' => '', 'payment_id' => 0];
        }
        $provider = AcquiringRegistry::forCheckout($providerId);
        if ($provider === null) {
            return ['ok' => false, 'error' => t('acquiring.err.no_provider')];
        }
        $amount = self::orderAmount($order);
        $created = $provider->createPayment([
            'order_id' => $orderId,
            'client_id' => (int)($order['id_user'] ?? 0),
            'amount' => $amount,
            'currency' => AcquiringConfig::currency(),
            'description' => 'Order #' . $orderId,
            'return_url' => $returnUrl,
        ]);
        if (empty($created['ok'])) {
            return ['ok' => false, 'error' => (string)($created['error'] ?? t('acquiring.err.create'))];
        }
        $id = AcquiringPaymentsRepository::create([
            'order_id' => $orderId,
            'client_id' => (int)($order['id_user'] ?? 0),
            'provider' => $provider->id(),
            'provider_ref' => (string)($created['provider_ref'] ?? ''),
            'amount' => $amount,
            'currency' => AcquiringConfig::currency(),
            'status' => 'pending',
            'pay_url' => (string)($created['pay_url'] ?? ''),
            'meta' => $created['meta'] ?? [],
        ]);
        return [
            'ok' => true,
            'payment_id' => $id,
            'pay_url' => (string)($created['pay_url'] ?? ''),
            'provider' => $provider->id(),
        ];
    }

    public static function markSucceeded(int $paymentId, ?array $meta = null): void
    {
        $row = AcquiringPaymentsRepository::find($paymentId);
        if ($row === null || ($row['status'] ?? '') === 'succeeded') {
            return;
        }
        AcquiringPaymentsRepository::updateStatus($paymentId, 'succeeded', $meta ?? ($row['meta'] ?? []));
        EventBus::emit('acquiring.payment.succeeded', [
            'payment_id' => $paymentId,
            'order_id' => (int)$row['order_id'],
            'client_id' => (int)$row['client_id'],
            'amount' => (float)$row['amount'],
            'provider' => (string)$row['provider'],
            'provider_ref' => (string)$row['provider_ref'],
        ]);
    }

    public static function markFailed(int $paymentId, ?array $meta = null): void
    {
        $row = AcquiringPaymentsRepository::find($paymentId);
        if ($row === null) {
            return;
        }
        AcquiringPaymentsRepository::updateStatus($paymentId, 'failed', $meta ?? ($row['meta'] ?? []));
        EventBus::emit('acquiring.payment.failed', [
            'payment_id' => $paymentId,
            'order_id' => (int)$row['order_id'],
            'client_id' => (int)$row['client_id'],
            'provider' => (string)$row['provider'],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function handleWebhook(string $providerId, array $payload, string $raw): array
    {
        $provider = AcquiringRegistry::get($providerId);
        if ($provider === null) {
            return ['ok' => false, 'error' => 'unknown provider'];
        }
        $parsed = $provider->parseWebhook($payload);
        if (empty($parsed['ok'])) {
            AcquiringPaymentsRepository::logWebhook($providerId, 0, $raw);
            return $parsed;
        }
        $row = AcquiringPaymentsRepository::findByProviderRef($providerId, (string)$parsed['provider_ref']);
        $paymentId = $row ? (int)$row['id'] : 0;
        AcquiringPaymentsRepository::logWebhook($providerId, $paymentId, $raw);
        if ($row === null) {
            return ['ok' => false, 'error' => 'payment not found'];
        }
        $status = (string)($parsed['status'] ?? 'pending');
        if ($status === 'succeeded') {
            self::markSucceeded($paymentId);
        } elseif ($status === 'failed') {
            self::markFailed($paymentId);
        }
        return ['ok' => true, 'payment_id' => $paymentId, 'status' => $status];
    }

    /** @param array<string,mixed> $order */
    private static function orderAmount(array $order): float
    {
        foreach (['summ', 'sum', 'amount', 'price'] as $k) {
            if (isset($order[$k]) && (float)$order[$k] > 0) {
                return round((float)$order[$k], 2);
            }
        }
        $content = $order['content'] ?? null;
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = is_array($decoded) ? $decoded : null;
        }
        if (is_array($content)) {
            $sum = 0.0;
            foreach ($content as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $sum += (float)($line['price'] ?? $line['summ'] ?? 0) * max(1, (int)($line['qty'] ?? 1));
            }
            if ($sum > 0) {
                return round($sum, 2);
            }
        }
        return 0.0;
    }
}
