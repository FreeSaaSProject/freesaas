<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/FinanceYoy.php';
require_once __DIR__ . '/lib/FinanceBalanceRepository.php';

Assets::register('finance', [
    'css' => ['finance.css'],
    'js' => ['yoy-chart.js'],
]);

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $ids = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    if (isset($ids['finance.balance'])) {
        $hints[] = t('finance.assistant.hint');
    } elseif (empty($payload['group_mode'])) {
        // Personal chat only: do not invent URLs. Groups skip — naming absent products leaks into «what can you do?».
        $hints[] = t('finance.assistant.hint_absent');
    }
    $payload['hints'] = $hints;
    return $payload;
});

// LLM / bookmarks sometimes invent /finance; only real screen is /finance/balance.
ModuleManager::registerRoute('/finance', [
    'module' => 'finance',
    'methods' => ['GET'],
    'permission' => 'finance.view',
    'handler' => static function (): void {
        redirect('/finance/balance');
    },
]);

ModuleManager::registerRoute('/finance/balance', [
    'module' => 'finance',
    'methods' => ['GET'],
    'permission' => 'finance.view',
    'handler' => static function (): void {
        require base_path('modules/finance/pages/balance.php');
    },
]);
