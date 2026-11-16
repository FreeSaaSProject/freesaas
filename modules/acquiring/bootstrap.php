<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/AcquiringProvider.php';
require_once __DIR__ . '/lib/AcquiringConfig.php';
require_once __DIR__ . '/lib/AcquiringRegistry.php';
require_once __DIR__ . '/lib/AcquiringPaymentsRepository.php';
require_once __DIR__ . '/lib/Acquiring.php';
require_once __DIR__ . '/lib/Providers/YooKassaProvider.php';
require_once __DIR__ . '/lib/Providers/TBankProvider.php';

AcquiringRegistry::register(new YooKassaProvider());
AcquiringRegistry::register(new TBankProvider());

Assets::register('acquiring', [
    'css' => ['acquiring.css'],
]);

$admin = [
    '/acquiring/status' => ['permission' => 'acquiring.view', 'methods' => ['GET'], 'file' => 'status.php'],
    '/acquiring/payments' => ['permission' => 'acquiring.view', 'methods' => ['GET'], 'file' => 'payments.php'],
    '/acquiring/settings' => [
        'permission' => 'acquiring.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
];
foreach ($admin as $path => $def) {
    $file = (string)$def['file'];
    $opts = [
        'module' => 'acquiring',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/acquiring/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $opts['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $opts);
}

ModuleManager::registerRoute('/acquiring/checkout', [
    'module' => 'acquiring',
    'methods' => ['GET', 'POST'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/acquiring/pages/checkout.php');
    },
]);

ModuleManager::registerRoute('/acquiring/webhook/{provider}', [
    'module' => 'acquiring',
    'methods' => ['POST'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/acquiring/pages/webhook.php');
    },
]);

EventBus::on('client_mcp.payment', static function (array $payload): array {
    $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
    if (($payment['status'] ?? '') === 'paid') {
        return $payload;
    }
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    $orderId = (int)($order['id'] ?? 0);
    if ($orderId < 1 || !AcquiringRegistry::forCheckout()) {
        return $payload;
    }
    if (AcquiringPaymentsRepository::isOrderPaid($orderId)) {
        $payment['status'] = 'paid';
        $payment['mode'] = 'online';
        $payload['payment'] = $payment;
        return $payload;
    }
    $created = Acquiring::createForOrder($orderId, null, '/cabinet/orders');
    if (!empty($created['ok']) && !empty($created['pay_url'])) {
        $payment['required'] = true;
        $payment['mode'] = 'online';
        $payment['url'] = (string)$created['pay_url'];
        $payment['expires_at'] = date('c', time() + 3600);
        $payment['instructions'] = '';
        $payload['payment'] = $payment;
    }
    return $payload;
});

EventBus::on('orders.client_state', static function (array $payload): array {
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    $orderId = (int)($order['id'] ?? 0);
    if ($orderId > 0 && AcquiringPaymentsRepository::isOrderPaid($orderId)) {
        $payload['paid'] = true;
    }
    return $payload;
});
