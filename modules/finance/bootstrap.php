<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/FinanceYoy.php';
require_once __DIR__ . '/lib/FinanceBalanceRepository.php';

Assets::register('finance', [
    'css' => ['finance.css'],
    'js' => ['yoy-chart.js'],
]);

ModuleManager::registerRoute('/finance/balance', [
    'module' => 'finance',
    'methods' => ['GET'],
    'permission' => 'finance.view',
    'handler' => static function (): void {
        require base_path('modules/finance/pages/balance.php');
    },
]);
