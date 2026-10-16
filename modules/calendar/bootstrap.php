<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/EventsRepository.php';
require_once __DIR__ . '/lib/CalendarWeek.php';
require_once __DIR__ . '/lib/CalendarTimeline.php';
require_once __DIR__ . '/lib/CalendarWidgets.php';
require_once __DIR__ . '/lib/CalendarDayStats.php';
require_once __DIR__ . '/lib/CalendarTimelineEnrich.php';

Assets::register('calendar', [
    'css' => ['calendar.css'],
    'js' => ['calendar.js'],
]);
ModuleManager::registerMenuGroup('calendar.menu.group', 200, '/calendar');

StatusesRepository::registerFieldGroup([
    'title' => 'calendar.statuses.group',
    'when' => 'calendar',
    'fields' => [
        ['key' => 'EVT_MY_ONLY', 'label' => 'calendar.statuses.field.EVT_MY_ONLY', 'type' => 'checkbox'],
        ['key' => 'EVT_OLD', 'label' => 'calendar.statuses.field.EVT_OLD', 'type' => 'checkbox'],
        ['key' => 'EVT_MONEY_DATA', 'label' => 'calendar.statuses.field.EVT_MONEY_DATA', 'type' => 'checkbox'],
        ['key' => 'EVT_DELETE', 'label' => 'calendar.statuses.field.EVT_DELETE', 'type' => 'checkbox'],
        ['key' => 'EVT_CREATE_NEW', 'label' => 'calendar.statuses.field.EVT_CREATE_NEW', 'type' => 'checkbox'],
        ['key' => 'EVT_MODIFY', 'label' => 'calendar.statuses.field.EVT_MODIFY', 'type' => 'checkbox'],
        ['key' => 'EVT_BONUS', 'label' => 'calendar.statuses.field.EVT_BONUS', 'type' => 'checkbox'],
        ['key' => 'EVT_CONTACT_DATA', 'label' => 'calendar.statuses.field.EVT_CONTACT_DATA', 'type' => 'checkbox'],
        ['key' => 'EVT_DOC_LINKS', 'label' => 'calendar.statuses.field.EVT_DOC_LINKS', 'type' => 'checkbox'],
        ['key' => 'EVT_PHOTOS', 'label' => 'calendar.statuses.field.EVT_PHOTOS', 'type' => 'checkbox'],
    ],
]);

$pages = [
    '/calendar' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'week.php'],
    '/calendar/week/{date}' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'week.php'],
    '/calendar/day/{date}' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'day.php'],
    '/calendar/events/new' => ['permission' => 'calendar.edit', 'methods' => ['GET', 'POST'], 'file' => 'event_edit.php'],
    '/calendar/events/{id}/edit' => ['permission' => 'calendar.edit', 'methods' => ['GET', 'POST'], 'file' => 'event_edit.php'],
    '/calendar/nazop' => ['permission' => 'calendar.view', 'methods' => ['GET', 'POST'], 'file' => 'nazop.php'],
    '/calendar/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'calendar',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/calendar/pages/' . $file);
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

BulkActions::register('calendar_events', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'calendar.delete',
    'confirm' => 'calendar.event.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $id = (int)($ids[0] ?? 0);
        $redirect = '/calendar';
        if ($id > 0) {
            $row = EventsRepository::find($id);
            if ($row !== null) {
                $redirect = EventsRepository::weekUrl((int)($row['dtstart'] ?? 0));
            }
        }
        $done = EventsRepository::deleteByIds($ids);
        return ['done' => $done, 'skipped' => [], 'redirect' => $redirect];
    },
]);

EventBus::on('core.bulk.check_item', static function (array $payload): array {
    if (($payload['list_id'] ?? '') !== 'calendar_events' || ($payload['action'] ?? '') !== 'delete') {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    $row = $id > 0 ? EventsRepository::find($id) : null;
    if ($row === null || !EventsRepository::canDelete($row)) {
        $payload['blocked'] = true;
        $payload['reason'] = t('calendar.event.err.no_delete');
    }
    return $payload;
});

Health::register('calendar.hours', 'calendar', static function (): array {
    $start = (int)Config::get('calendar', 'hour_start', 9);
    $end = (int)Config::get('calendar', 'hour_end', 21);
    if ($start < 0 || $start > 23 || $end < 1 || $end > 24 || $start >= $end) {
        return [
            'status' => 'fail',
            'message' => "invalid calendar hours hour_start={$start} hour_end={$end}",
            'meta' => ['hour_start' => $start, 'hour_end' => $end],
        ];
    }
    return [
        'status' => 'ok',
        'message' => "hours {$start}–{$end}",
        'meta' => ['hour_start' => $start, 'hour_end' => $end],
    ];
}, 'Calendar day hours');
