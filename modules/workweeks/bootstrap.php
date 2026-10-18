<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/WorkweeksRepository.php';
require_once __DIR__ . '/lib/WorkweeksAssistantPreview.php';
require_once __DIR__ . '/lib/NeededPeopleBotPlugin.php';
require_once __DIR__ . '/lib/ShiftsBotPlugin.php';
require_once __DIR__ . '/lib/WorkweeksBiShiftLanes.php';
require_once __DIR__ . '/lib/WorkweeksBiContrib.php';

EventBus::on('bi.datasets', [WorkweeksBiContrib::class, 'onDatasets']);
EventBus::on('bi.derive_steps', [WorkweeksBiContrib::class, 'onDeriveSteps']);
EventBus::on('bi.recipes', [WorkweeksBiContrib::class, 'onRecipes']);

EventBus::on('assistant.write.preview', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    return WorkweeksAssistantPreview::onWritePreview($payload);
});

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $ids = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    if (isset($ids['workweeks.day']) || isset($ids['workweeks.list'])) {
        $hints[] = t('workweeks.assistant.hint');
    }
    $payload['hints'] = $hints;
    return $payload;
});

Assets::register('workweeks', [
    'css' => ['workweeks.css'],
    'js' => ['workweeks.js'],
]);

StatusesRepository::registerFieldGroup([
    'title' => 'workweeks.statuses.group',
    'when' => 'workweeks',
    'fields' => [
        ['key' => 'SHOW_LOAD_LEVEL', 'label' => 'workweeks.statuses.field.SHOW_LOAD_LEVEL', 'type' => 'checkbox'],
        ['key' => 'SHOW_IN_LOADS', 'label' => 'workweeks.statuses.field.SHOW_IN_LOADS', 'type' => 'checkbox'],
        ['key' => 'CAN_BE_ASSIGNED', 'label' => 'workweeks.statuses.field.CAN_BE_ASSIGNED', 'type' => 'checkbox'],
        ['key' => 'CAN_BE_ASSIGNED_ASANY', 'label' => 'workweeks.statuses.field.CAN_BE_ASSIGNED_ASANY', 'type' => 'checkbox'],
        ['key' => 'EVT_ASSIGN', 'label' => 'workweeks.statuses.field.EVT_ASSIGN', 'type' => 'checkbox'],
        ['key' => 'EVT_ASSIGN_MY', 'label' => 'workweeks.statuses.field.EVT_ASSIGN_MY', 'type' => 'checkbox'],
        ['key' => 'DEFAULT_PRICE_COL', 'label' => 'workweeks.statuses.field.DEFAULT_PRICE_COL', 'type' => 'select_shift'],
    ],
]);

$pages = [
    '/workweeks' => ['permission' => 'workweeks.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/workweeks/new' => ['permission' => 'workweeks.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/workweeks/{id}/edit' => ['permission' => 'workweeks.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/workweeks/nazop' => ['permission' => 'workweeks.view', 'methods' => ['GET', 'POST'], 'file' => 'nazop.php'],
    '/workweeks/nazcab' => ['permission' => 'workweeks.view', 'methods' => ['GET', 'POST'], 'file' => 'nazcab.php'],
    '/workweeks/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    // Legacy day URL → list filtered by date
    '/workweeks/day/{date}' => ['permission' => 'workweeks.view', 'methods' => ['GET'], 'file' => 'day_redirect.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'workweeks',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/workweeks/pages/' . $file);
        },
    ];
    $perm = (string)($def['permission'] ?? '');
    if ($perm !== '') {
        $route['permission'] = $perm;
    }
    $capability = (string)($def['capability'] ?? '');
    if ($capability !== '') {
        $route['capability'] = $capability;
    }
    ModuleManager::registerRoute($path, $route);
}

BulkActions::register('workweeks', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'workweeks.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => WorkweeksRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

$registerWorkweeksBots = static function (): void {
    NeededPeopleBotPlugin::register();
    ShiftsBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerWorkweeksBots();
}
EventBus::on('bots.ready', static function ($payload) use ($registerWorkweeksBots) {
    $registerWorkweeksBots();
    return is_array($payload) ? $payload : [];
});
