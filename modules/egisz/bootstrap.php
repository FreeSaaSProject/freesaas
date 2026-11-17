<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/Snils.php';
require_once __DIR__ . '/lib/EgiszConfig.php';
require_once __DIR__ . '/lib/EgiszTransport.php';
require_once __DIR__ . '/lib/NullTransport.php';
require_once __DIR__ . '/lib/EgiszSigner.php';
require_once __DIR__ . '/lib/NullSigner.php';
require_once __DIR__ . '/lib/N3/N3SoapClient.php';
require_once __DIR__ . '/lib/N3/N3TerminologyClient.php';
require_once __DIR__ . '/lib/N3/N3EventlogClient.php';
require_once __DIR__ . '/lib/N3/N3HealthTransport.php';
require_once __DIR__ . '/lib/EgiszRegistry.php';
require_once __DIR__ . '/lib/EgiszClientIdsRepository.php';
require_once __DIR__ . '/lib/EgiszStaffRepository.php';
require_once __DIR__ . '/lib/Icd10Repository.php';
require_once __DIR__ . '/lib/EgiszOutboxRepository.php';
require_once __DIR__ . '/lib/Semd/SemdXml.php';
require_once __DIR__ . '/lib/Semd/SemdDocument.php';
require_once __DIR__ . '/lib/Semd/SemdBuilder.php';
require_once __DIR__ . '/lib/Semd/SemdValidator.php';
require_once __DIR__ . '/lib/Semd/AbstractCdaBuilder.php';
require_once __DIR__ . '/lib/Semd/ConsultationProtocolBuilder.php';
require_once __DIR__ . '/lib/Semd/ManipulationProtocolBuilder.php';
require_once __DIR__ . '/lib/Semd/SemdFactory.php';
require_once __DIR__ . '/lib/EgiszPatientSync.php';
require_once __DIR__ . '/lib/EgiszCaseSync.php';
require_once __DIR__ . '/lib/EgiszWorkerAuth.php';
require_once __DIR__ . '/lib/EgiszWorkerJobsRepository.php';
require_once __DIR__ . '/lib/EgiszWorkerDispatch.php';

Assets::register('egisz', [
    'css' => ['egisz.css'],
    'js' => ['egisz.js'],
]);

ModuleManager::registerMenuGroup('egisz.menu.group', 425, '/egisz/journal', '🏥');

$admin = [
    '/egisz/journal' => ['permission' => 'egisz.view', 'methods' => ['GET', 'POST'], 'file' => 'journal.php'],
    '/egisz/status' => ['permission' => 'egisz.view', 'methods' => ['GET'], 'file' => 'status.php'],
    '/egisz/settings' => [
        'permission' => 'egisz.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/egisz/icd/search' => ['permission' => 'egisz.view', 'methods' => ['GET'], 'file' => 'icd_search.php'],
    '/egisz/term/lookup' => ['permission' => 'egisz.view', 'methods' => ['GET'], 'file' => 'term_lookup.php'],
    '/egisz/semd/preview' => [
        'permission' => 'egisz.build',
        'methods' => ['GET', 'POST'],
        'file' => 'semd_preview.php',
    ],
    '/egisz/patient/sync' => [
        'permission' => 'egisz.build',
        'methods' => ['POST'],
        'file' => 'patient_sync.php',
    ],
    '/egisz/case/send' => [
        'permission' => 'egisz.build',
        'methods' => ['GET', 'POST'],
        'file' => 'case_send.php',
    ],
    '/egisz/eventlog/poll' => [
        'permission' => 'egisz.build',
        'methods' => ['GET', 'POST'],
        'file' => 'eventlog_poll.php',
    ],
];
foreach ($admin as $path => $def) {
    $file = (string)$def['file'];
    $opts = [
        'module' => 'egisz',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/egisz/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $opts['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $opts);
}

$workerRoutes = [
    '/egisz/worker/hello' => 'worker_hello.php',
    '/egisz/worker/claim' => 'worker_claim.php',
    '/egisz/worker/result' => 'worker_result.php',
];
foreach ($workerRoutes as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'egisz',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/egisz/pages/' . $file);
        },
    ]);
}

Cron::register('egisz.eventlog_poll', [
    'module' => 'egisz',
    'label' => 'egisz.cron.eventlog',
    'every' => 15,
    'handler' => static function (): array {
        if (!EgiszConfig::n3Configured()) {
            return ['ok' => true, 'skipped' => 1, 'updated' => 0];
        }
        $r = EgiszCaseSync::pollEventlog(30);
        return [
            'ok' => !empty($r['ok']),
            'updated' => (int)($r['updated'] ?? 0),
        ];
    },
]);

EventBus::on('core.layout.gate', static function ($payload) {
    if (!is_array($payload) || !Auth::check()) {
        return $payload;
    }
    $path = function_exists('request_path') ? request_path() : (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    if ((strpos($path, '/documents') === 0 || strpos($path, '/clients') === 0) && Auth::can('egisz.view')) {
        Assets::need('egisz');
    }
    return $payload;
});

EventBus::on('clients.edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $client = $payload['client'] ?? null;
    if (!is_array($client)) {
        return $payload;
    }
    $ids = EgiszClientIdsRepository::find((int)($client['id'] ?? 0));
    $canSync = Auth::can('egisz.build') && EgiszConfig::n3Configured();
    ob_start();
    require __DIR__ . '/views/_client_edit.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('clients.edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if ($id < 1 || !array_key_exists('egisz_snils', $post)) {
        return $payload;
    }
    if (!EgiszClientIdsRepository::upsert(
        $id,
        (string)($post['egisz_snils'] ?? ''),
        (string)($post['egisz_oms_policy'] ?? ''),
        (string)($post['egisz_oms_insurer'] ?? '')
    ) && function_exists('flash')) {
        flash('err', t('egisz.err.bad_snils'));
    }
    return $payload;
});

EventBus::on('clients.view.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $client = $payload['client'] ?? null;
    if (!is_array($client)) {
        return $payload;
    }
    $ids = EgiszClientIdsRepository::find((int)($client['id'] ?? 0));
    if ($ids === null) {
        return $payload;
    }
    $snils = trim((string)($ids['SNILS'] ?? ''));
    $oms = trim((string)($ids['OMS_POLICY'] ?? ''));
    $n3 = trim((string)($ids['N3_ID'] ?? $ids['ID_PATIENT_MIS'] ?? ''));
    if ($snils === '' && $oms === '' && $n3 === '') {
        return $payload;
    }
    $html = '<section class="egisz-client-profile"><h3>' . e(t('egisz.client.sec')) . '</h3><dl>';
    if ($snils !== '') {
        $html .= '<dt>' . e(t('egisz.client.snils')) . '</dt><dd>' . e($snils) . '</dd>';
    }
    if ($oms !== '') {
        $html .= '<dt>' . e(t('egisz.client.oms_policy')) . '</dt><dd>' . e($oms) . '</dd>';
    }
    if ($n3 !== '') {
        $html .= '<dt>' . e(t('egisz.client.n3_id')) . '</dt><dd><code>' . e($n3) . '</code></dd>';
    }
    $html .= '</dl></section>';
    $payload['html'] = ($payload['html'] ?? '') . $html;
    return $payload;
});

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $staff = EgiszStaffRepository::find((int)($user['id'] ?? 0));
    ob_start();
    require __DIR__ . '/views/_user_edit.php';
    $payload['html_access'] = ($payload['html_access'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!array_key_exists('egisz_staff_snils', $post)) {
        return $payload;
    }
    $userId = (int)($payload['id'] ?? $payload['user_id'] ?? 0);
    if ($userId < 1 && is_array($payload['user'] ?? null)) {
        $userId = (int)($payload['user']['id'] ?? 0);
    }
    if ($userId < 1) {
        $userId = (int)($post['id'] ?? 0);
    }
    if ($userId > 0
        && !EgiszStaffRepository::upsert(
            $userId,
            (string)($post['egisz_staff_snils'] ?? ''),
            (string)($post['egisz_position_code'] ?? ''),
            (string)($post['egisz_specialty_code'] ?? ''),
            (string)($post['egisz_role_code'] ?? '')
        )
        && function_exists('flash')
    ) {
        flash('err', t('egisz.err.bad_snils'));
    }
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload) || empty($payload['can_see_sensitive'])) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $staff = EgiszStaffRepository::resolve((int)($user['id'] ?? 0));
    if ($staff['snils'] === '') {
        return $payload;
    }
    $payload['html_flags'] = ($payload['html_flags'] ?? '')
        . '<p class="egisz-staff-flag">' . e(t('egisz.staff.snils')) . ': ' . e($staff['snils']) . '</p>';
    return $payload;
});
