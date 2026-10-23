<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$message = '';
$error = '';

/** @return array{work_hour_start:int,work_hour_end:int} */
$hoursRow = static function (): array {
    return [
        'work_hour_start' => CalendarTimeline::hourStart(),
        'work_hour_end' => CalendarTimeline::hourEnd(),
    ];
};
$row = $hoursRow();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $post = $_POST;
        if (array_key_exists('work_hour_start', $post) || array_key_exists('work_hour_end', $post)) {
            CalendarTimeline::updateWorkHours(
                (int)($post['work_hour_start'] ?? $row['work_hour_start']),
                (int)($post['work_hour_end'] ?? $row['work_hour_end'])
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
        $row = $hoursRow();
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'event_mode' => CalendarConfig::eventMode(),
                'manual_create' => CalendarConfig::isVisitsMode(),
                'compact_chip_minutes' => CalendarConfig::compactChipMinutes(),
                'work_hour_start' => $row['work_hour_start'],
                'work_hour_end' => $row['work_hour_end'],
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = $hoursRow();
    }
}

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
        'work_hour_start' => $row['work_hour_start'],
        'work_hour_end' => $row['work_hour_end'],
        'show_hours' => true,
    ]);
    return;
}

View::render('settings', [
    'title' => t('calendar.menu.settings'),
    'message' => $message,
    'error' => $error,
    'row' => $row,
    'showHours' => true,
    'eventMode' => $eventMode,
    'compactChipMinutes' => $compactChipMinutes,
    'extraHtml' => $extraHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar'],
        ['label' => t('calendar.menu.settings'), 'current' => true],
    ]),
], 'layout', 'calendar');
