<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProcentsRepository.php';
require_once __DIR__ . '/lib/ProcentsPaymentSync.php';

Assets::register('procents', [
    'css' => ['procents.css'],
    'js' => ['procents.js'],
]);

ModuleManager::registerRoute('/procents', [
    'module' => 'procents',
    'methods' => ['GET'],
    'permission' => 'procents.view',
    'handler' => static function (): void {
        require base_path('modules/procents/pages/report.php');
    },
]);

ModuleManager::registerRoute('/procents/days', [
    'module' => 'procents',
    'methods' => ['GET'],
    'permission' => 'procents.view',
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/procents/pages/days.php');
    },
]);

ModuleManager::registerRoute('/procents/day', [
    'module' => 'procents',
    'methods' => ['GET'],
    'permission' => 'procents.view',
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/procents/pages/day.php');
    },
]);
