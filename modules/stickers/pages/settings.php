<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

$buildSettings = static function (): array {
    $domain = StickersDiagnose::normalizeCookieDomain(
        (string)Config::get('app', 'session_cookie_domain', '')
    );
    $publicHost = AppRuntime::publicHost();
    $adminHost = AppRuntime::adminHost();
    return [
        'session_cookie_domain' => $domain,
        'session_cookie_domain_suggested' => StickersDiagnose::suggestCookieDomain($adminHost, $publicHost),
        'public_print_enabled' => StickerPrint::isPublicPrintEnabled() ? 1 : 0,
        'public_host' => $publicHost,
        'admin_host' => $adminHost,
        'public_print_url' => StickerPrint::publicPrintUrl(),
        'activation_mode' => StickerActivation::mode(),
    ];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (array_key_exists('session_cookie_domain', $_POST)) {
            $domain = StickersDiagnose::validateCookieDomain(
                (string)($_POST['session_cookie_domain'] ?? '')
            );
            Config::set('app', 'session_cookie_domain', $domain);
        }
        if (array_key_exists('public_print_enabled', $_POST) || array_key_exists('public_print_present', $_POST)) {
            $on = !empty($_POST['public_print_enabled']);
            StickerPrint::setPublicPrintEnabled($on);
        }
        if (array_key_exists('activation_mode', $_POST)) {
            StickerActivation::setMode((string)$_POST['activation_mode']);
        }
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'settings' => $buildSettings(),
                'note' => t('stickers.settings.relogin_note'),
            ]);
            return;
        }
        flash_set('ok', $message . ' ' . t('stickers.settings.relogin_note'));
        redirect('/stickers/settings');
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$settings = $buildSettings();
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $settings,
    ]);
    return;
}

View::render('settings', [
    'title' => t('stickers.settings.title'),
    'message' => $message,
    'error' => $error,
    'settings' => $settings,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('stickers.menu.ins'), 'href' => '/stickers/ins'],
        ['label' => t('stickers.settings.title'), 'current' => true],
    ]),
], 'layout', 'stickers');
