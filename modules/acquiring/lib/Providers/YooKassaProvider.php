<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Creates payments via YooKassa API when credentials set; otherwise stub URL for local demos.
 */
final class YooKassaProvider implements AcquiringProvider
{
    public function id(): string
    {
        return 'yookassa';
    }

    public function enabled(): bool
    {
        return !empty(AcquiringConfig::providerSettings('yookassa')['enabled']);
    }

    public function createPayment(array $opts): array
    {
        $cfg = AcquiringConfig::providerSettings('yookassa');
        $orderId = (int)($opts['order_id'] ?? 0);
        $amount = round((float)($opts['amount'] ?? 0), 2);
        $currency = (string)($opts['currency'] ?? 'RUB');
        $returnUrl = (string)($opts['return_url'] ?? '/cabinet');
        $idem = 'ord-' . $orderId . '-' . bin2hex(random_bytes(4));

        if ($cfg['shop_id'] === '' || $cfg['secret_key'] === '') {
            $ref = 'stub-yk-' . $idem;
            return [
                'ok' => true,
                'provider_ref' => $ref,
                'pay_url' => '/acquiring/checkout?stub=1&provider=yookassa&ref=' . rawurlencode($ref) . '&order_id=' . $orderId,
                'meta' => ['mode' => 'stub'],
            ];
        }

        $body = [
            'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => $currency],
            'confirmation' => ['type' => 'redirect', 'return_url' => $this->absolute($returnUrl)],
            'capture' => true,
            'description' => (string)($opts['description'] ?? ('Order #' . $orderId)),
            'metadata' => ['order_id' => $orderId, 'client_id' => (int)($opts['client_id'] ?? 0)],
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://api.yookassa.ru/v3/payments');
        if ($ch === false) {
            return ['ok' => false, 'error' => t('acquiring.err.create')];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Idempotence-Key: ' . $idem,
            ],
            CURLOPT_USERPWD => $cfg['shop_id'] . ':' . $cfg['secret_key'],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($code >= 200 && $code < 300 && is_array($data) && !empty($data['id'])) {
            $url = (string)($data['confirmation']['confirmation_url'] ?? '');
            return [
                'ok' => true,
                'provider_ref' => (string)$data['id'],
                'pay_url' => $url,
                'meta' => ['mode' => 'live', 'raw_status' => (string)($data['status'] ?? '')],
            ];
        }
        return ['ok' => false, 'error' => t('acquiring.err.create'), 'meta' => ['http' => $code, 'body' => $data]];
    }

    public function parseWebhook(array $payload): array
    {
        $obj = is_array($payload['object'] ?? null) ? $payload['object'] : $payload;
        $ref = (string)($obj['id'] ?? '');
        $statusRaw = (string)($obj['status'] ?? '');
        $amount = (float)($obj['amount']['value'] ?? 0);
        $status = 'pending';
        if ($statusRaw === 'succeeded') {
            $status = 'succeeded';
        } elseif (in_array($statusRaw, ['canceled', 'cancelled'], true)) {
            $status = 'failed';
        }
        if ($ref === '') {
            return ['ok' => false, 'error' => 'missing id'];
        }
        return ['ok' => true, 'provider_ref' => $ref, 'status' => $status, 'amount' => $amount];
    }

    private function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https://' : 'http://') . $host . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}
