<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Agents send only the keys they change; the HTML form posts everything (checkbox absent = off).
    if (!$wantsJson || array_key_exists('qr_archive', $_POST)) {
        DocsConfig::setQrArchive(!empty($_POST['qr_archive']));
    }
    if (isset($_POST['org']) && is_array($_POST['org'])) {
        DocsConfig::setOrg($wantsJson ? array_merge(DocsConfig::org(), $_POST['org']) : $_POST['org']);
    }
    $message = t('core.common.saved');
}

$qrLib = class_exists('StickerQr', false) || is_file(base_path('modules/stickers/lib/phpqrcode/phpqrcode.php'));
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'qr_archive' => DocsConfig::qrArchive(),
        'qr_library' => $qrLib,
        'org' => DocsConfig::org(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('docs.menu.settings'),
    'qrArchive' => DocsConfig::qrArchive(),
    'qrLib' => $qrLib,
    'org' => DocsConfig::org(),
    'message' => $message,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.list'), 'href' => '/documents'],
        ['label' => t('docs.menu.settings'), 'current' => true],
    ]),
], 'layout', 'docs');
