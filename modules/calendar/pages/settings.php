<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/SystemRepository.php');

$wantsJson = AgentHttp::wantsJson();

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
        if (isset($post['calendar_event_mode'])) {
            CalendarConfig::setEventMode((string)$post['calendar_event_mode']);
        } elseif (array_key_exists('calendar_manual_create', $post)) {
            // Legacy POST key
            CalendarConfig::setEventMode(!empty($post['calendar_manual_create']) ? 'visits' : 'blocks');
        }
        if (array_key_exists('calendar_compact_chip_minutes', $post)) {
            CalendarConfig::setCompactChipMinutes((int)$post['calendar_compact_chip_minutes']);
        }
        EventBus::emit('calendar.settings.save', [
            'post' => $post,
        ]);
        $message = t('core.common.saved');
        $row = SystemRepository::get();
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'event_mode' => CalendarConfig::eventMode(),
                'manual_create' => CalendarConfig::isVisitsMode(),
                'compact_chip_minutes' => CalendarConfig::compactChipMinutes(),
                'work_hour_start' => (int)($row['work_hour_start'] ?? Config::get('calendar', 'hour_start', 9)),
                'work_hour_end' => (int)($row['work_hour_end'] ?? Config::get('calendar', 'hour_end', 21)),
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = SystemRepository::get();
    }
}

$showHours = Database::tableExists('base_system')
    && Database::columnExists('base_system', 'work_hour_start')
    && Database::columnExists('base_system', 'work_hour_end');

$sections = EventBus::emit('calendar.settings.sections', [
    'html' => '',
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';
$eventMode = CalendarConfig::eventMode();
$compactChipMinutes = CalendarConfig::compactChipMinutes();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'event_mode' => $eventMode,
        'manual_create' => $eventMode === CalendarConfig::MODE_VISITS,
        'compact_chip_minutes' => $compactChipMinutes,
        'work_hour_start' => (int)($row['work_hour_start'] ?? Config::get('calendar', 'hour_start', 9)),
        'work_hour_end' => (int)($row['work_hour_end'] ?? Config::get('calendar', 'hour_end', 21)),
        'show_hours' => $showHours,
    ]);
    return;
}

View::render('settings', [
    'title' => t('calendar.menu.settings'),
    'message' => $message,
    'error' => $error,
    'row' => $row,
    'showHours' => $showHours,
    'eventMode' => $eventMode,
    'compactChipMinutes' => $compactChipMinutes,
    'extraHtml' => $extraHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar'],
        ['label' => t('calendar.menu.settings'), 'current' => true],
    ]),
], 'layout', 'calendar');
