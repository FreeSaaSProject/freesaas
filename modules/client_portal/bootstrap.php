<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/ClientPortalConfig.php';
require_once __DIR__ . '/lib/ClientPortalAuth.php';
require_once __DIR__ . '/lib/ClientPortalSections.php';
require_once __DIR__ . '/lib/ClientPortalView.php';

Assets::register('client_portal', [
    'css' => ['client_portal.css'],
]);

ModuleManager::registerMenuGroup('client_portal.menu.group', 42);

$adminPages = [
    '/client-portal/status' => [
        'permission' => 'client_portal.manage',
        'methods' => ['GET'],
        'file' => 'status.php',
    ],
    '/client-portal/settings' => [
        'permission' => 'client_portal.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
];
foreach ($adminPages as $path => $def) {
    $file = (string)$def['file'];
    $opts = [
        'module' => 'client_portal',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/client_portal/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $opts['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $opts);
}

$publicPages = [
    '/cabinet/login' => ['methods' => ['GET', 'POST'], 'file' => 'login.php'],
    '/cabinet/logout' => ['methods' => ['GET', 'POST'], 'file' => 'logout.php'],
    '/cabinet' => ['methods' => ['GET', 'POST'], 'file' => 'cabinet.php'],
    '/cabinet/{section}' => ['methods' => ['GET', 'POST'], 'file' => 'cabinet_section.php'],
];
foreach ($publicPages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'client_portal',
        'methods' => $def['methods'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/client_portal/pages/' . $file);
        },
    ]);
}

EventBus::on('client_portal.sections', static function (array $payload): array {
    $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
    $sections[] = [
        'id' => 'profile',
        'module' => 'client_portal',
        'title' => t('client_portal.section.profile'),
        'weight' => 10,
        'nav' => true,
        'home_card' => true,
    ];
    $payload['sections'] = $sections;
    return $payload;
});

EventBus::on('client_portal.home', static function (array $payload): array {
    $cards = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
    foreach ($cards as $i => $card) {
        if (!is_array($card) || (string)($card['id'] ?? '') !== 'profile') {
            continue;
        }
        $cards[$i]['html'] = '<p class="muted">' . e(t('client_portal.profile.home_card')) . '</p>';
    }
    $payload['cards'] = $cards;
    return $payload;
});

EventBus::on('client_portal.render', static function (array $payload): array {
    if ((string)($payload['section'] ?? '') !== 'profile') {
        return $payload;
    }
    $clientId = (int)($payload['client_id'] ?? 0);
    $client = $clientId > 0 ? ClientsRepository::find($clientId) : null;
    if ($client === null) {
        return $payload;
    }
    ob_start();
    $flash = (string)($payload['flash'] ?? '');
    require base_path('modules/client_portal/views/profile.php');
    $payload['html'] = (string)ob_get_clean();
    return $payload;
});

EventBus::on('sites.diagnose', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $issues = is_array($payload['issues'] ?? null) ? $payload['issues'] : [];
    $sections = ClientPortalSections::collect();
    $visible = ClientPortalSections::visible();
    $payload['client_portal'] = [
        'cookie' => ClientPortalConfig::cookieName(),
        'sections_registered' => count($sections),
        'sections_visible' => count($visible),
        'login_path' => '/cabinet/login',
    ];
    if ($sections === []) {
        $issues[] = [
            'code' => 'client_portal.no_sections',
            'level' => 'info',
            'message' => 'No portal sections registered yet',
        ];
    }
    $payload['issues'] = $issues;
    return $payload;
});

EventBus::on('sites.sitemap', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $urls = is_array($payload['urls'] ?? null) ? $payload['urls'] : [];
    $urls[] = ['loc' => '/cabinet/login'];
    $payload['urls'] = $urls;
    return $payload;
});

EventBus::on('sites.llms_txt', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
    $sections[] = [
        'title' => t('client_portal.module.name'),
        'lines' => [
            'Client personal cabinet: /cabinet/login then /cabinet',
        ],
    ];
    $payload['sections'] = $sections;
    return $payload;
});
