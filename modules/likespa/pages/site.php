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
        if (array_key_exists('mothersite', $_POST)) {
            Config::set('likespa', 'mothersite', trim((string)$_POST['mothersite']));
        }
        $message = t('core.common.saved');
        $row = SystemRepository::get() ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

View::render('site', [
    'title' => t('likespa.menu.site'),
    'row' => $row,
    'mothersite' => (string)Config::get('likespa', 'mothersite', ''),
    'hasKeywords' => Database::columnExists('base_system', 'keywords'),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.site'), 'current' => true],
    ]),
], 'layout', 'likespa');
