<?php
declare(strict_types=1);

$registered = ClientPortalSections::collect();
$visible = ClientPortalSections::visible();
$payload = [
    'ok' => true,
    'cookie' => ClientPortalConfig::cookieName(),
    'login_path' => '/cabinet/login',
    'sections_registered' => $registered,
    'sections_visible' => $visible,
    'visibility' => ClientPortalConfig::visibility(),
    'weights' => ClientPortalConfig::weights(),
];

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($payload);
    return;
}

View::render('status', [
    'title' => t('client_portal.status.title'),
    'payload' => $payload,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('client_portal.status.title'), 'current' => true],
    ]),
], 'layout', 'client_portal');
