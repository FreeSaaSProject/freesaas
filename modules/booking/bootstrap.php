<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BookingSlots.php';
require_once __DIR__ . '/lib/BookingConfig.php';
require_once __DIR__ . '/lib/BookingOccupancy.php';
require_once __DIR__ . '/lib/BookingOperatorGate.php';
require_once __DIR__ . '/lib/BookingCatalog.php';
require_once __DIR__ . '/lib/BookingAvailability.php';
require_once __DIR__ . '/lib/BookingValidate.php';
require_once __DIR__ . '/lib/BookingBook.php';

Assets::register('booking', [
    'css' => ['assets/css/booking.css'],
]);

$pages = [
    '/booking/new' => ['permission' => 'booking.edit', 'methods' => ['GET', 'POST'], 'file' => 'new.php'],
    '/booking/availability' => ['permission' => 'booking.view', 'methods' => ['GET'], 'file' => 'availability.php'],
    '/booking/book' => ['permission' => 'booking.edit', 'methods' => ['POST'], 'file' => 'book.php'],
    '/booking/clients' => ['permission' => 'booking.edit', 'methods' => ['GET'], 'file' => 'clients.php'],
    '/booking/orders' => ['permission' => 'booking.view', 'methods' => ['GET'], 'file' => 'orders.php'],
    '/booking/cancel' => ['permission' => 'booking.edit', 'methods' => ['POST', 'GET'], 'file' => 'cancel.php'],
    '/booking/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'booking',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/booking/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    ModuleManager::registerRoute($path, $route);
}

EventBus::on('calendar.settings.sections', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_section', [
        'cfg' => BookingConfig::formValues(),
    ], 'booking');
    return $payload;
});

EventBus::on('calendar.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['booking_settings_save'])) {
        return $payload;
    }
    BookingConfig::saveFromPost($post);
    return $payload;
});

