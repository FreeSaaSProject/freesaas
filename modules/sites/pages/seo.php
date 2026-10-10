<?php
declare(strict_types=1);

$message = '';
$error = '';
$row = SystemRepository::get() ?: [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        SystemSite::updateBasics([
            'name' => (string)($_POST['name'] ?? ''),
            'title' => (string)($_POST['title'] ?? ''),
            'keywords' => (string)($_POST['keywords'] ?? ''),
            'tel' => (string)($_POST['tel'] ?? ''),
        ]);
        $message = t('core.common.saved');
        $row = SystemRepository::get() ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

View::render('seo', [
    'title' => t('sites.menu.seo'),
    'row' => $row,
    'hasKeywords' => Database::columnExists('base_system', 'keywords'),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.seo'), 'current' => true],
    ]),
], 'layout', 'sites');
