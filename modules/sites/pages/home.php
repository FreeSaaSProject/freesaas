<?php
declare(strict_types=1);

$meta = SitesFront::siteMeta();
$promoId = class_exists(SitesHostContext::class) ? SitesHostContext::promoId() : 0;
$promo = null;
if ($promoId > 0 && class_exists(PromopagesRepository::class)) {
    $promo = PromopagesRepository::find($promoId);
    $promo = PromopageRenderer::resolveForPublic($promo);
}

if ($promo !== null) {
    $rendered = PromopageRenderer::render($promo);
    PromopageRenderer::bumpShows($promo);
    $phone = trim((string)($promo['phone'] ?? ''));
    if ($phone !== '') {
        $meta['tel'] = $phone;
    }
    $title = trim((string)($promo['meta_title'] ?? ''));
    if ($title === '') {
        $title = $meta['title'] !== '' ? $meta['title'] : (string)($promo['descr'] ?? t('sites.home.title'));
    }
    if (trim((string)($promo['meta_descr'] ?? '')) !== '') {
        $meta['meta_descr'] = (string)$promo['meta_descr'];
    }
    SiteTheme::render('promo', [
        'site' => $meta,
        'nav' => SitesFront::nav('home'),
        'title' => $title,
        'promo' => $promo,
        'html' => $rendered['html'],
        'section_nav' => $rendered['section_nav'],
    ], 'promo');
    return;
}

$payload = EventBus::emit('sites.home', [
    'title' => $meta['title'] !== '' ? $meta['title'] : t('sites.home.title'),
    'lead' => t('sites.home.lead'),
    'html' => '',
]);

SiteTheme::render('home', [
    'site' => $meta,
    'nav' => SitesFront::nav('home'),
    'title' => (string)($payload['title'] ?? t('sites.home.title')),
    'lead' => (string)($payload['lead'] ?? t('sites.home.lead')),
    'html' => (string)($payload['html'] ?? ''),
    'show_shop' => ModuleManager::isEnabled('catalog') && class_exists('ProductsRepository', false),
]);
