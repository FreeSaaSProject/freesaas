<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/WellnessOwner.php';
require_once __DIR__ . '/lib/FitFoodsRepository.php';
require_once __DIR__ . '/lib/FitFoodlogsRepository.php';
require_once __DIR__ . '/lib/FitWorkoutsRepository.php';
require_once __DIR__ . '/lib/FitWeightlogsRepository.php';
require_once __DIR__ . '/lib/FitTargetsRepository.php';
require_once __DIR__ . '/lib/FitDayBalance.php';
require_once __DIR__ . '/lib/HealthMedicinesRepository.php';
require_once __DIR__ . '/lib/HealthSymptomsRepository.php';
require_once __DIR__ . '/lib/HealthLogsRepository.php';
require_once __DIR__ . '/lib/HealthDoctorRxRepository.php';
require_once __DIR__ . '/lib/WellnessCalendarRepository.php';
require_once __DIR__ . '/lib/WellnessUi.php';
require_once __DIR__ . '/lib/WellnessQuickAdd.php';
require_once __DIR__ . '/lib/FitRecipesRepository.php';
require_once __DIR__ . '/lib/FitRecipeSync.php';
require_once __DIR__ . '/lib/FitFoodBotPlugin.php';

Assets::register('wellness', [
    'css' => ['wellness.css'],
    'js' => ['wellness.js'],
]);

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $ids = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    foreach ($ids as $id => $_true) {
        if (strpos((string)$id, 'wellness.') === 0) {
            $hints[] = t('wellness.assistant.hint');
            break;
        }
    }
    $payload['hints'] = $hints;
    return $payload;
});

ModuleManager::registerMenuGroup('wellness.menu.group', 650, '/wellness');

$pages = [
    '/wellness' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'food_diary.php'],
    '/wellness/calendar' => ['permission' => 'wellness.view', 'methods' => ['GET'], 'file' => 'calendar.php'],
    '/wellness/food-diary' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'food_diary.php'],
    '/wellness/food-diary/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'foodlog_edit.php'],
    '/wellness/food-diary/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'foodlog_edit.php'],
    '/wellness/foods' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'foods_list.php'],
    '/wellness/foods/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'foods_edit.php'],
    '/wellness/foods/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'foods_edit.php'],
    '/wellness/workouts' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'workouts_list.php'],
    '/wellness/workouts/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'workout_edit.php'],
    '/wellness/workouts/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'workout_edit.php'],
    '/wellness/weight' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'weight_list.php'],
    '/wellness/weight/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'weight_edit.php'],
    '/wellness/weight/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'weight_edit.php'],
    '/wellness/targets' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'targets_list.php'],
    '/wellness/targets/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'target_edit.php'],
    '/wellness/targets/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'target_edit.php'],
    '/wellness/health-diary' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'health_diary.php'],
    '/wellness/health-diary/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'health_log_edit.php'],
    '/wellness/health-diary/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'health_log_edit.php'],
    '/wellness/doctor-rx' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'doctor_rx_list.php'],
    '/wellness/doctor-rx/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'doctor_rx_edit.php'],
    '/wellness/doctor-rx/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'doctor_rx_edit.php'],
    '/wellness/medicines' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'medicines_list.php'],
    '/wellness/medicines/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'medicines_edit.php'],
    '/wellness/medicines/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'medicines_edit.php'],
    '/wellness/symptoms' => ['permission' => 'wellness.view', 'methods' => ['GET', 'POST'], 'file' => 'symptoms_list.php'],
    '/wellness/symptoms/new' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'symptoms_edit.php'],
    '/wellness/symptoms/{id}/edit' => ['permission' => 'wellness.edit', 'methods' => ['GET', 'POST'], 'file' => 'symptoms_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'wellness',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/wellness/pages/' . $file);
        },
    ]);
}

$bulkDeletes = [
    'wellness_foods' => [FitFoodsRepository::class, 'deleteByIds'],
    'wellness_food_diary' => [FitFoodlogsRepository::class, 'deleteByIds'],
    'wellness_workouts' => [FitWorkoutsRepository::class, 'deleteByIds'],
    'wellness_weight' => [FitWeightlogsRepository::class, 'deleteByIds'],
    'wellness_targets' => [FitTargetsRepository::class, 'deleteByIds'],
    'wellness_health_diary' => [HealthLogsRepository::class, 'deleteByIds'],
    'wellness_doctor_rx' => [HealthDoctorRxRepository::class, 'deleteByIds'],
    'wellness_medicines' => [HealthMedicinesRepository::class, 'deleteByIds'],
    'wellness_symptoms' => [HealthSymptomsRepository::class, 'deleteByIds'],
];

foreach ($bulkDeletes as $listId => $callable) {
    BulkActions::register($listId, 'delete', [
        'label' => 'core.bulk.delete',
        'permission' => 'wellness.delete',
        'confirm' => 'core.bulk.confirm_delete',
        'run' => static function (array $ids, array $params) use ($callable): array {
            /** @var callable $fn */
            $fn = $callable;
            return ['done' => (int)$fn($ids), 'skipped' => []];
        },
    ]);
}

$registerFitBot = static function (): void {
    FitFoodBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerFitBot();
}
EventBus::on('bots.ready', static function ($payload) use ($registerFitBot) {
    $registerFitBot();
    return is_array($payload) ? $payload : [];
});
