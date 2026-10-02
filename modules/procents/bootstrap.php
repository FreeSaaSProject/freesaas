<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProcentsRepository.php';

ModuleManager::registerRoute('/procents', [
    'module' => 'procents',
    'methods' => ['GET'],
    'permission' => 'procents.view',
    'handler' => static function (): void {
        require base_path('modules/procents/pages/report.php');
    },
]);
