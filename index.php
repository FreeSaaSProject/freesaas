<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/app/bootstrap.php';

try {
    Router::dispatch();
} catch (Throwable $e) {
    http_response_code(500);
    if (class_exists('View')) {
        View::render('error', [
            'title' => 'Server error',
            'message' => $e->getMessage(),
            'hint' => $e->getFile() . ':' . $e->getLine(),
        ]);
    } else {
        echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}
