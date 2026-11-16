<?php
declare(strict_types=1);

$registered = ClientPortalSections::collect();
$visible = ClientPortalSections::visible();
$phone = trim((string)($_GET['phone'] ?? ''));
$diagnose = ClientPortalPasswordReset::diagnose($phone !== '' ? $phone : null);
$payload = [
    'ok' => true,
    'cookie' => ClientPortalConfig::cookieName(),
    'login_path' => '/cabinet/login',
    'forgot_path' => '/cabinet/forgot',
    'sections_registered' => $registered,
    'sections_visible' => $visible,
    'visibility' => ClientPortalConfig::visibility(),
    'weights' => ClientPortalConfig::weights(),
    'password_reset' => $diagnose,
];

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($payload);
    return;
}

View::render('status', [
    'title' => t('client_portal.status.title'),
    'payload' => $payload,
    'phone' => $phone,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('client_portal.menu.settings'), 'href' => '/client-portal/settings'],
        ['label' => t('client_portal.status.title'), 'current' => true],
    ]),
], 'layout', 'client_portal');
