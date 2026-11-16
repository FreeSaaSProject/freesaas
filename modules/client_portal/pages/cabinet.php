<?php
declare(strict_types=1);

ClientPortalAuth::ensureBag();
if (!ClientPortalAuth::check()) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('client_portal.flash.need_login'), 401);
        return;
    }
    redirect('/cabinet/login');
}

$clientId = ClientPortalAuth::clientId();
$cards = ClientPortalSections::homeCards($clientId);
$nav = ClientPortalSections::nav('home');

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'client_id' => $clientId,
        'cards' => $cards,
        'nav' => $nav,
    ]);
    return;
}

$body = ClientPortalView::captureModule('home', [
    'cards' => $cards,
    'title' => t('client_portal.home.title'),
]);

ClientPortalView::render('cabinet', [
    'title' => t('client_portal.home.title'),
    'cabinet_nav' => $nav,
    'cabinet_body' => $body,
]);
