<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/EventsRepository.php';
require_once __DIR__ . '/lib/CalendarWeek.php';
require_once __DIR__ . '/lib/CalendarTimeline.php';

Assets::register('calendar', [
    'css' => ['calendar.css'],
]);

$pages = [
    '/calendar' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'week.php'],
    '/calendar/week/{date}' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'week.php'],
    '/calendar/day/{date}' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'day.php'],
    '/calendar/events/new' => ['permission' => 'calendar.edit', 'methods' => ['GET', 'POST'], 'file' => 'event_edit.php'],
    '/calendar/events/{id}/edit' => ['permission' => 'calendar.edit', 'methods' => ['GET', 'POST'], 'file' => 'event_edit.php'],
    '/calendar/nazop' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'nazop.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'calendar',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/calendar/pages/' . $file);
        },
    ]);
}

EventBus::on('core.dashboard.catalog', static function ($catalog) {
    if (!is_array($catalog)) {
        $catalog = [];
    }
    if (!Auth::can('calendar.view') || !Database::tableExists('base_events')) {
        return $catalog;
    }
    $catalog[] = [
        'id' => 'calendar.upcoming',
        'title' => t('calendar.widget.upcoming.title'),
        'module' => 'calendar',
        'default_order' => 10,
        'default_on' => true,
        'settings' => DashboardWidgets::limitSettingSchema(8, 1, 40),
    ];
    return $catalog;
});

EventBus::on('core.dashboard.widgets', static function ($widgets) {
    if (!is_array($widgets)) {
        $widgets = [];
    }
    if (!Auth::can('calendar.view') || !Database::tableExists('base_events')) {
        return $widgets;
    }
    if (!DashboardWidgets::isEnabled('calendar.upcoming')) {
        return $widgets;
    }

    $limit = (int)DashboardWidgets::setting('calendar.upcoming', 'limit', 8);
    if ($limit < 1) {
        $limit = 8;
    }

    $myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);
    $staffId = $myOnly ? Auth::userId() : null;
    $now = time();
    $scanEnd = $now + 7 * 86400;
    $items = EventsRepository::listForDayRange($now, $scanEnd, $staffId > 0 ? $staffId : null);
    $upcoming = [];
    foreach ($items as $ev) {
        if ((int)($ev['enabled'] ?? 1) !== 1) {
            continue;
        }
        $upcoming[] = $ev;
        if (count($upcoming) >= $limit) {
            break;
        }
    }

    $staffForLinks = $myOnly ? Auth::userId() : 0;
    $html = View::capture('_widget_upcoming', [
        'events' => $upcoming,
        'myOnly' => $myOnly,
        'staffForLinks' => $staffForLinks,
    ], 'calendar');

    $widgets[] = [
        'id' => 'calendar.upcoming',
        'title' => t('calendar.widget.upcoming.title'),
        'html' => $html,
        'tone' => $upcoming ? 'ok' : 'neutral',
        'href' => EventsRepository::weekUrl($now, $staffForLinks),
        'size' => 'lg',
    ];
    return $widgets;
});

EventBus::on('core.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        $payload = ['html' => '', 'row' => null];
    }
    if (!Auth::can('core.settings')) {
        return $payload;
    }
    if (!Database::tableExists('base_system')
        || !Database::columnExists('base_system', 'work_hour_start')
        || !Database::columnExists('base_system', 'work_hour_end')
    ) {
        return $payload;
    }
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : null;
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_hours', [
        'row' => $row,
    ], 'calendar');
    return $payload;
});

EventBus::on('core.settings.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('core.settings')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!array_key_exists('work_hour_start', $post) && !array_key_exists('work_hour_end', $post)) {
        return $payload;
    }
    require_once base_path('modules/core/lib/SystemRepository.php');
    SystemRepository::updateWorkHours(
        (int)($post['work_hour_start'] ?? 9),
        (int)($post['work_hour_end'] ?? 22)
    );
    return $payload;
});
