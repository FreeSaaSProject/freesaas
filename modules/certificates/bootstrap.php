<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/CertificatesRepository.php';
require_once __DIR__ . '/lib/CertificateLedgerRepository.php';
require_once __DIR__ . '/lib/CertificateProcessor.php';
require_once __DIR__ . '/lib/CertificatesCart.php';

Assets::register('certificates', [
    'css' => ['certificates.css'],
]);

StatusesRepository::registerFieldGroup([
    'title' => 'certificates.statuses.group',
    'when' => 'certificates',
    'fields' => [
        ['key' => 'EVT_SERT', 'label' => 'certificates.statuses.field.EVT_SERT', 'type' => 'checkbox'],
    ],
]);

$pages = [
    '/certificates' => ['permission' => 'certificates.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/certificates/new' => ['permission' => 'certificates.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/certificates/{id}/edit' => ['permission' => 'certificates.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/certificates/ledger' => ['permission' => 'certificates.ledger', 'methods' => ['GET', 'POST'], 'file' => 'ledger.php'],
    '/certificates/events/{id}/quote' => ['permission' => 'certificates.view', 'methods' => ['GET'], 'file' => 'event_quote.php'],
    '/certificates/events/{id}/apply' => ['permission' => 'certificates.apply', 'methods' => ['POST'], 'file' => 'event_apply.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'certificates',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/certificates/pages/' . $file);
        },
    ]);
}

$storefrontPages = [
    '/certificates/cart/apply' => 'cart_apply.php',
    '/certificates/cart/reset' => 'cart_reset.php',
];
foreach ($storefrontPages as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'certificates',
        'methods' => ['POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/certificates/pages/' . $file);
        },
    ]);
}

BulkActions::register('certificates', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'certificates.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => CertificatesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

Cron::register('certificates.expire', [
    'module' => 'certificates',
    'label' => 'certificates.cron.expire',
    'every' => 60,
    'handler' => static function (): array {
        return CertificateProcessor::expireDue(100);
    },
]);

EventBus::on('calendar.event.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('certificates.view') && !Auth::can('certificates.apply')) {
        return $payload;
    }
    if (!CertificatesRepository::tableReady()) {
        return $payload;
    }

    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    $isNew = !empty($payload['is_new']);
    $canModify = !empty($payload['can_modify']);
    $eventId = (int)($payload['id'] ?? 0);
    $clientId = (int)($row['id_user'] ?? 0);
    $activeCerts = $clientId > 0 ? CertificatesRepository::listActiveForHolder($clientId) : [];
    $canApply = !$isNew
        && $eventId > 0
        && Auth::can('certificates.apply')
        && (Auth::capability('EVT_SERT') || Auth::capability('SUPERADMIN_ACCESS'));

    Assets::need('certificates');
    ob_start();
    $certSection = [
        'row' => $row,
        'isNew' => $isNew,
        'canModify' => $canModify,
        'eventId' => $eventId,
        'clientId' => $clientId,
        'activeCerts' => $activeCerts,
        'canApply' => $canApply,
        'returnUrl' => (string)($payload['return_url'] ?? '/calendar/events/' . $eventId . '/edit'),
        'applyFlash' => '',
        'applyError' => '',
    ];
    extract($certSection, EXTR_SKIP);
    require __DIR__ . '/views/_event_cert.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('clients.view.profile', static function ($payload) {
    if (!is_array($payload) || !CertificatesRepository::tableReady()) {
        return $payload;
    }
    if (!Auth::can('certificates.view')) {
        return $payload;
    }
    $client = $payload['client'] ?? null;
    if (!is_array($client)) {
        return $payload;
    }
    $clientId = (int)($client['id'] ?? 0);
    if ($clientId < 1) {
        return $payload;
    }
    $counts = CertificatesRepository::countForClient($clientId);
    Assets::need('certificates');
    ob_start();
    $bought = $counts['bought'];
    $active = $counts['active'];
    require __DIR__ . '/views/_client_profile.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('sites.cart.prepare', static function ($payload) {
    if (!is_array($payload) || !CertificatesRepository::tableReady()) {
        return $payload;
    }
    $guestReady = !empty($payload['guest_ready']);
    $clientId = 0;
    if (class_exists('SitesGuest', false)) {
        $clientId = (int)SitesGuest::clientId();
    }
    $certCode = CertificatesCart::code();
    $certInfo = null;
    $certError = '';
    if ($certCode !== '' && $clientId > 0) {
        $v = CertificateProcessor::validate($certCode, $clientId, [
            'allow_unbound_holder' => true,
        ]);
        if (!empty($v['ok'])) {
            $certInfo = [
                'ok' => true,
                'code' => (string)($v['cert']['code'] ?? $certCode),
                'balance' => (float)($v['balance'] ?? 0),
                'id' => (int)($v['cert']['id'] ?? 0),
            ];
        } else {
            $certError = (string)($v['error'] ?? '');
            CertificatesCart::clear();
            $certCode = '';
        }
    }

    Assets::need('certificates');
    ob_start();
    require __DIR__ . '/views/_cart_cert.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('sites.checkout.before', static function ($payload) {
    if (!is_array($payload) || !CertificatesRepository::tableReady()) {
        return $payload;
    }
    $code = CertificatesCart::code();
    if ($code === '') {
        return $payload;
    }
    $clientId = 0;
    if (class_exists('SitesGuest', false)) {
        $clientId = (int)SitesGuest::clientId();
    }
    if ($clientId < 1 && class_exists('ClientsRepository', false)) {
        $phone = (string)($payload['phone'] ?? '');
        $client = ClientsRepository::findByPhoneDigits($phone);
        if ($client) {
            $clientId = (int)$client['id'];
        }
    }
    $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
    $productIds = [];
    $typeIds = [];
    $sum = 0.0;
    foreach ($lines as $line) {
        $pid = (int)($line['id'] ?? $line['product_id'] ?? 0);
        $sum += (float)($line['price'] ?? 0) * max(1, (int)($line['qty'] ?? 1));
        if ($pid > 0) {
            $productIds[] = $pid;
            if (class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($pid);
                if ($p && (int)($p['type_id'] ?? 0) > 0) {
                    $typeIds[] = (int)$p['type_id'];
                }
            }
        }
    }
    $v = CertificateProcessor::validate($code, $clientId, [
        'product_ids' => $productIds,
        'type_ids' => $typeIds,
        'check_sum' => $sum,
        'amount' => $sum,
        'allow_unbound_holder' => true,
    ]);
    if (empty($v['ok'])) {
        $payload['error'] = (string)($v['error'] ?? t('certificates.err.apply_failed'));
        return $payload;
    }
    $payload['certificate_code'] = $code;
    $payload['certificate_id'] = (int)($v['cert']['id'] ?? 0);
    $payload['certificate_client_id'] = $clientId;
    return $payload;
});

EventBus::on('sites.checkout.after', static function ($payload) {
    if (!is_array($payload) || !CertificatesRepository::tableReady()) {
        return $payload;
    }
    $code = CertificatesCart::code();
    if ($code === '') {
        $code = (string)($payload['certificate_code'] ?? '');
    }
    if ($code === '') {
        return $payload;
    }
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId < 1) {
        return $payload;
    }
    $clientId = (int)($payload['certificate_client_id'] ?? 0);
    if ($clientId < 1 && class_exists('SitesGuest', false)) {
        $clientId = (int)SitesGuest::clientId();
    }
    $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
    $sum = 0.0;
    foreach ($lines as $line) {
        $sum += (float)($line['price'] ?? 0) * max(1, (int)($line['qty'] ?? 1));
    }
    $result = CertificateProcessor::applyOrder($orderId, $code, $clientId, $lines, $sum);
    if (!empty($result['ok'])) {
        CertificatesCart::clear();
        $payload['certificate_spend'] = $result;
    } else {
        $payload['certificate_error'] = (string)($result['error'] ?? '');
    }
    return $payload;
});
