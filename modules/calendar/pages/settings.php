<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/SystemRepository.php');

$message = '';
$error = '';
$row = SystemRepository::get();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $post = $_POST;
        if (array_key_exists('work_hour_start', $post) || array_key_exists('work_hour_end', $post)) {
            CalendarTimeline::updateWorkHours(
                (int)($post['work_hour_start'] ?? 9),
                (int)($post['work_hour_end'] ?? 22)
            );
        }
        if (array_key_exists('calendar_manual_create', $post)) {
            Config::set('calendar', 'manual_create', !empty($post['calendar_manual_create']) ? '1' : '0');
        }
        $message = t('core.common.saved');
        $row = SystemRepository::get();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = SystemRepository::get();
    }
}

$showHours = Database::tableExists('base_system')
    && Database::columnExists('base_system', 'work_hour_start')
    && Database::columnExists('base_system', 'work_hour_end');

View::render('settings', [
    'title' => t('calendar.menu.settings'),
    'message' => $message,
    'error' => $error,
    'row' => $row,
    'showHours' => $showHours,
    'manualCreate' => (int)Config::get('calendar', 'manual_create', 1) === 1,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar'],
        ['label' => t('calendar.menu.settings'), 'current' => true],
    ]),
], 'layout', 'calendar');
