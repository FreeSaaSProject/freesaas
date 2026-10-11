<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
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
        $row = SystemRepository::get() ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'seo' => $row, 'saved' => true]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'seo' => $row,
        'has_keywords' => Database::columnExists('base_system', 'keywords'),
    ]);
    return;
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
