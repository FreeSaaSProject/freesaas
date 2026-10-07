<?php
declare(strict_types=1);

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        LikespaConfig::saveFromPost($_POST);
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cfg = LikespaConfig::formValues();

View::render('settings', [
    'title' => t('likespa.menu.settings'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.term_scans'), 'href' => '/likespa/term-scans'],
        ['label' => t('likespa.menu.settings'), 'current' => true],
    ]),
], 'layout', 'likespa');
