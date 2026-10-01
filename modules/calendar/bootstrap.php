<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/EventsRepository.php';
require_once __DIR__ . '/lib/CalendarWeek.php';
require_once __DIR__ . '/lib/CalendarTimeline.php';

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
