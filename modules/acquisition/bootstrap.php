<?php
declare(strict_types=1);

/**
 * Soft acquisition: first-touch sources + inquiry/call journal.
 * UI on clients/ledger/calendar only while this module is enabled.
 */

require_once __DIR__ . '/lib/PromoSourcesRepository.php';
require_once __DIR__ . '/lib/CallsRepository.php';
require_once __DIR__ . '/lib/AcquisitionClientHooks.php';
require_once __DIR__ . '/lib/AcquisitionLedgerHooks.php';
require_once __DIR__ . '/lib/AcquisitionOrdersHooks.php';
require_once __DIR__ . '/lib/AcquisitionCalendarHooks.php';
require_once __DIR__ . '/lib/AcquisitionBiContrib.php';

ModuleManager::registerMenuGroup('acquisition.menu.group', 215, '/acquisition/calls', '📣');

$pages = [
    '/acquisition/calls' => ['permission' => 'acquisition.view', 'methods' => ['GET'], 'file' => 'calls_list.php'],
    '/acquisition/calls/new' => ['permission' => 'acquisition.edit', 'methods' => ['GET', 'POST'], 'file' => 'calls_edit.php'],
    '/acquisition/calls/{id}/edit' => ['permission' => 'acquisition.edit', 'methods' => ['GET', 'POST'], 'file' => 'calls_edit.php'],
    '/acquisition/sources' => ['permission' => 'acquisition.view', 'methods' => ['GET'], 'file' => 'sources_list.php'],
    '/acquisition/sources/new' => ['permission' => 'acquisition.edit', 'methods' => ['GET', 'POST'], 'file' => 'sources_edit.php'],
    '/acquisition/sources/{id}/edit' => ['permission' => 'acquisition.edit', 'methods' => ['GET', 'POST'], 'file' => 'sources_edit.php'],
    '/acquisition/ingest' => ['permission' => 'acquisition.edit', 'methods' => ['POST'], 'file' => 'ingest.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'acquisition',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/acquisition/pages/' . $file);
        },
    ]);
}

AcquisitionClientHooks::register();
AcquisitionLedgerHooks::register();
AcquisitionOrdersHooks::register();
AcquisitionCalendarHooks::register();

EventBus::on('bi.datasets', [AcquisitionBiContrib::class, 'onDatasets']);
EventBus::on('bi.recipes', [AcquisitionBiContrib::class, 'onRecipes']);
