<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ReconsDayBoard.php';

Assets::register('recons', [
    'css' => ['recons.css'],
]);

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $ids = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    if (isset($ids['recons.day']) || isset($ids['recons.day.write'])) {
        $hints[] = t('recons.assistant.hint');
    } elseif (empty($payload['group_mode'])) {
        $hints[] = t('recons.assistant.hint_absent');
    }
    $payload['hints'] = $hints;
    return $payload;
});

ModuleManager::registerRoute('/recons', [
    'module' => 'recons',
    'methods' => ['GET'],
    'permission' => 'recons.view',
    'handler' => static function (): void {
        redirect('/recons/day');
    },
]);

ModuleManager::registerRoute('/recons/day', [
    'module' => 'recons',
    'methods' => ['GET', 'POST'],
    'permission' => 'recons.view',
    'handler' => static function (): void {
        require base_path('modules/recons/pages/day.php');
    },
]);
