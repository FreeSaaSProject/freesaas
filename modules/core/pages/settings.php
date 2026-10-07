<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/SystemRepository.php');
require_once base_path('modules/core/lib/SystemLogo.php');

$message = '';
$error = '';
$row = SystemRepository::get();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        SystemRepository::updateBasics([
            'company' => (string)($_POST['company'] ?? ''),
        ]);

        if (!empty($_POST['logo_remove'])) {
            SystemLogo::remove();
        }

        $upload = $_FILES['logo'] ?? null;
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            SystemLogo::saveFromUpload($upload);
        }

        EventBus::emit('core.settings.save', [
            'post' => $_POST,
            'files' => $_FILES,
            'row' => SystemRepository::get(),
        ]);

        $message = t('core.common.saved');
        $row = SystemRepository::get();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = SystemRepository::get();
    }
}

$sections = EventBus::emit('core.settings.sections', [
    'html' => '',
    'row' => $row,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

View::render('settings', [
    'title' => t('core.menu.settings'),
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'logoUrl' => SystemLogo::url(SystemLogo::VARIANT_AUTH),
    'hasLogo' => SystemLogo::has(),
    'extraHtml' => $extraHtml,
]);
