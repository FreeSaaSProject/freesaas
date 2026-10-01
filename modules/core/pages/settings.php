<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/SystemRepository.php');

$message = '';
$error = '';
$row = SystemRepository::get();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        SystemRepository::updateBasics([
            'title' => (string)($_POST['title'] ?? ''),
            'company' => (string)($_POST['company'] ?? ''),
            'name' => (string)($_POST['name'] ?? ''),
            'tel' => (string)($_POST['tel'] ?? ''),
            'work_hour_start' => (int)($_POST['work_hour_start'] ?? 9),
            'work_hour_end' => (int)($_POST['work_hour_end'] ?? 22),
        ]);
        $message = t('core.common.saved');
        $row = SystemRepository::get();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

View::render('settings', [
    'title' => t('core.menu.settings'),
    'row' => $row,
    'message' => $message,
    'error' => $error,
]);
