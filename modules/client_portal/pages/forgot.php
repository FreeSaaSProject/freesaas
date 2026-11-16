<?php
declare(strict_types=1);

ClientPortalAuth::ensureBag();
if (ClientPortalAuth::check()) {
    redirect('/cabinet');
}

$channels = ClientPortalPasswordReset::channelsForClient(null);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'channels' => $channels]);
    return;
}

ClientPortalView::render('forgot', [
    'title' => t('client_portal.reset.forgot_title'),
    'channels' => $channels,
]);
