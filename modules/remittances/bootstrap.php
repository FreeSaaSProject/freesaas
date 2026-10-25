<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/RemittanceParser.php';
require_once __DIR__ . '/lib/RemittanceAgreementsRepository.php';
require_once __DIR__ . '/lib/RemittanceBatchesRepository.php';
require_once __DIR__ . '/lib/RemittanceMatcher.php';
require_once __DIR__ . '/lib/RemittanceExpected.php';
require_once __DIR__ . '/lib/RemittanceReconcile.php';
require_once __DIR__ . '/lib/RemittanceFeePoster.php';

Assets::register('remittances', [
    'css' => ['remittances.css'],
]);

$pages = [
    '/remittances' => ['permission' => 'remittances.manage', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/remittances/new' => ['permission' => 'remittances.manage', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/remittances/{id}/edit' => ['permission' => 'remittances.manage', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/remittances/moves/{moveId}/action' => [
        'permission' => 'ledger.edit',
        'methods' => ['POST'],
        'file' => 'move_action.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'remittances',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/remittances/pages/' . $file);
        },
    ]);
}

BulkActions::register('remittances', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'remittances.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        RemittanceMatcher::clearCache();
        return ['done' => RemittanceAgreementsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('ledger.moves.badges', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('ledger.view')) {
        return $payload;
    }
    $ids = $payload['ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
        return $payload;
    }
    $badges = RemittanceReconcile::badgesHtml($ids);
    if ($badges === []) {
        return $payload;
    }
    Assets::need('remittances');
    $byId = is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [];
    foreach ($badges as $mid => $html) {
        $mid = (int)$mid;
        $prev = (string)($byId[$mid] ?? '');
        $byId[$mid] = trim($prev . ' ' . $html);
    }
    $payload['by_id'] = $byId;
    return $payload;
});

EventBus::on('ledger.moves.edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('ledger.view')) {
        return $payload;
    }
    $moveId = (int)($payload['id'] ?? 0);
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    if ($moveId < 1) {
        return $payload;
    }
    $html = RemittanceReconcile::editSectionHtml($moveId, $row);
    if ($html === '') {
        return $payload;
    }
    if (!isset($payload['html']) || !is_array($payload['html'])) {
        $payload['html'] = [];
    }
    $payload['html'][] = $html;
    return $payload;
});
