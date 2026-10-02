<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/EventsRepository.php';
require_once __DIR__ . '/lib/CalendarWeek.php';
require_once __DIR__ . '/lib/CalendarTimeline.php';
require_once __DIR__ . '/lib/CalendarWidgets.php';

Assets::register('calendar', [
    'css' => ['calendar.css'],
    'js' => ['calendar.js'],
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
    $catalog[] = [
        'id' => 'calendar.today_board',
        'title' => t('calendar.widget.board.title'),
        'module' => 'calendar',
        'default_order' => 11,
        'default_on' => false,
        'settings' => [],
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

    $staffId = CalendarWidgets::staffFilter();
    $myOnly = CalendarWidgets::myOnly();
    $staffForLinks = $myOnly ? Auth::userId() : 0;
    $now = time();
    $dayHref = EventsRepository::dayUrl($now, $staffForLinks);
    $weekHref = EventsRepository::weekUrl($now, $staffForLinks);

    if (DashboardWidgets::isEnabled('calendar.upcoming')) {
        $limit = (int)DashboardWidgets::setting('calendar.upcoming', 'limit', 8);
        if ($limit < 1) {
            $limit = 8;
        }
        $items = CalendarWidgets::todayFeed($staffId, $limit);
        $html = View::capture('_widget_upcoming', [
            'items' => $items,
            'myOnly' => $myOnly,
        ], 'calendar');
        $widgets[] = [
            'id' => 'calendar.upcoming',
            'title' => t('calendar.widget.upcoming.title'),
            'html' => $html,
            'tone' => $items ? 'ok' : 'neutral',
            'href' => $weekHref,
            'href_mobile' => $dayHref,
            'size' => 'lg',
        ];
    }

    if (DashboardWidgets::isEnabled('calendar.today_board')) {
        Assets::need('calendar');
        $dayStart = strtotime('today 00:00:00') ?: $now;
        $dayEnd = $dayStart + 86400;
        $events = CalendarWidgets::eventsTodayActive($staffId, false);
        // Board also shows earlier-today finished? User asked ongoing + future — use active filter.
        // But board visual of "today" might want all of today's remaining; already have that.
        $procedures = CalendarWidgets::proceduresTodayActive($staffId);
        $orders = [];
        if (class_exists('OrdersRepository', false) && Database::tableExists('base_orders')) {
            $orders = OrdersRepository::listForDayRange($dayStart - 1, $dayEnd, $staffId);
            $orders = array_values(array_filter($orders, static function (array $o) use ($now): bool {
                return (int)($o['dt_event'] ?? 0) >= $now - 1800;
            }));
        }
        $chips = CalendarTimeline::chipsForBoard($dayStart, $events, $procedures, $orders);
        $html = View::capture('_widget_today_board', [
            'hours' => CalendarTimeline::hours(),
            'gridHeight' => CalendarTimeline::gridHeight(),
            'pxPerHour' => CalendarTimeline::PX_PER_HOUR,
            'chips' => $chips,
            'myOnly' => $myOnly,
        ], 'calendar');
        $widgets[] = [
            'id' => 'calendar.today_board',
            'title' => t('calendar.widget.board.title'),
            'html' => $html,
            'tone' => $chips ? 'ok' : 'neutral',
            'href' => $weekHref,
            'href_mobile' => $dayHref,
            'size' => 'lg',
        ];
    }

    return $widgets;
});

EventBus::on('core.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        $payload = ['html' => '', 'row' => null];
    }
    if (!Auth::can('core.settings')) {
        return $payload;
    }
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : null;
    $showHours = Database::tableExists('base_system')
        && Database::columnExists('base_system', 'work_hour_start')
        && Database::columnExists('base_system', 'work_hour_end');
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_hours', [
        'row' => $row,
        'showHours' => $showHours,
        'manualCreate' => (int)Config::get('calendar', 'manual_create', 1) === 1,
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
    if (array_key_exists('work_hour_start', $post) || array_key_exists('work_hour_end', $post)) {
        require_once base_path('modules/core/lib/SystemRepository.php');
        SystemRepository::updateWorkHours(
            (int)($post['work_hour_start'] ?? 9),
            (int)($post['work_hour_end'] ?? 22)
        );
    }
    if (array_key_exists('calendar_manual_create', $post)) {
        Config::set('calendar', 'manual_create', !empty($post['calendar_manual_create']) ? '1' : '0');
    }
    return $payload;
});
