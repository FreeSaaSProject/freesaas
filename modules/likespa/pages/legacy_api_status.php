<?php
declare(strict_types=1);

$payload = LikespaLegacyApi::diagnose();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'legacy_api' => $payload]);
}

View::render('legacy_api_status', [
    'title' => t('likespa.legacy_api.status_title'),
    'payload' => $payload,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.settings'), 'href' => '/likespa/settings'],
        ['label' => t('likespa.legacy_api.status_title'), 'current' => true],
    ]),
], 'layout', 'likespa');
