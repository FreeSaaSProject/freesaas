<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BookingSlots.php';
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
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'booking',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/booking/pages/' . $file);
        },
    ]);
}
