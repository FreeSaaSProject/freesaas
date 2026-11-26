<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * T-Bank (Tinkoff) Init API when credentials set; stub otherwise.
 */
final class TBankProvider implements AcquiringProvider
{
    public function id(): string
    {
        return 'tbank';
    }

    public function enabled(): bool
    {
        return !empty(AcquiringConfig::providerSettings('tbank')['enabled']);
    }

    public function createPayment(array $opts): array
    {
        $cfg = AcquiringConfig::providerSettings('tbank');
        $orderId = (int)($opts['order_id'] ?? 0);
        $amount = (int)round((float)($opts['amount'] ?? 0) * 100);
        $returnUrl = (string)($opts['return_url'] ?? '/cabinet');

        if ($cfg['terminal_key'] === '' || $cfg['password'] === '') {
            $ref = 'stub-tb-' . $orderId . '-' . bin2hex(random_bytes(3));
            return [
                'ok' => true,
                'provider_ref' => $ref,
                'pay_url' => '/acquiring/checkout?stub=1&provider=tbank&ref=' . rawurlencode($ref) . '&order_id=' . $orderId,
                'meta' => ['mode' => 'stub'],
            ];
        }

        $payload = [
            'TerminalKey' => $cfg['terminal_key'],
            'Amount' => $amount,
            'OrderId' => (string)$orderId . '-' . time(),
            'Description' => (string)($opts['description'] ?? ('Order #' . $orderId)),
            'SuccessURL' => $this->absolute($returnUrl),
            'FailURL' => $this->absolute($returnUrl),
            'DATA' => ['order_id' => (string)$orderId],
        ];
        $payload['Token'] = $this->token($payload, $cfg['password']);
        $ch = curl_init('https://securepay.tinkoff.ru/v2/Init');
        if ($ch === false) {
            return ['ok' => false, 'error' => t('acquiring.err.create')];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && !empty($data['Success']) && !empty($data['PaymentId'])) {
            return [
                'ok' => true,
                'provider_ref' => (string)$data['PaymentId'],
                'pay_url' => (string)($data['PaymentURL'] ?? ''),
                'meta' => ['mode' => 'live'],
            ];
        }
        return ['ok' => false, 'error' => t('acquiring.err.create'), 'meta' => ['http' => $code, 'body' => $data]];
    }

    public function parseWebhook(array $payload): array
    {
        $ref = (string)($payload['PaymentId'] ?? '');
        $statusRaw = (string)($payload['Status'] ?? '');
        $amount = ((float)($payload['Amount'] ?? 0)) / 100.0;
        $status = 'pending';
        if (in_array($statusRaw, ['CONFIRMED', 'AUTHORIZED'], true)) {
            $status = 'succeeded';
        } elseif (in_array($statusRaw, ['REJECTED', 'CANCELED', 'DEADLINE_EXPIRED'], true)) {
            $status = 'failed';
        }
        if ($ref === '') {
            return ['ok' => false, 'error' => 'missing PaymentId'];
        }
        return ['ok' => true, 'provider_ref' => $ref, 'status' => $status, 'amount' => $amount];
    }

    /** @param array<string,mixed> $payload */
    private function token(array $payload, string $password): string
    {
        $flat = [];
        foreach ($payload as $k => $v) {
            if ($k === 'Token' || $k === 'DATA' || $k === 'Receipt' || is_array($v)) {
                continue;
            }
            $flat[$k] = (string)$v;
        }
        $flat['Password'] = $password;
        ksort($flat);
        return hash('sha256', implode('', $flat));
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
