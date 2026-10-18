<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/VacationsRepository.php';
require_once __DIR__ . '/lib/VacationsBiContrib.php';

EventBus::on('bi.datasets', [VacationsBiContrib::class, 'onDatasets']);

$pages = [
    '/vacations' => ['permission' => 'vacations.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/vacations/new' => ['permission' => 'vacations.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/vacations/{id}/edit' => ['permission' => 'vacations.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'vacations',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/vacations/pages/' . $file);
        },
    ]);
}

BulkActions::register('vacations', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'vacations.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => VacationsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('vacations', 'sign', [
    'label' => 'vacations.bulk.sign',
    'permission' => 'vacations.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => VacationsRepository::signByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check()) {
        return $notices;
    }
    foreach (VacationsRepository::noticesForUser(Auth::userId()) as $n) {
        $notices[] = $n;
    }
    return $notices;
});
