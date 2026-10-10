<?php
declare(strict_types=1);

$code = (string)Router::param('code', '');
$meta = SitesFront::siteMeta();
$promo = null;
if ($code !== '' && class_exists('PromopagesRepository', false)) {
    $promo = PromopagesRepository::findByCode($code);
    $promo = PromopageRenderer::resolveForPublic($promo);
}

if ($promo === null) {
    http_response_code(404);
    SiteTheme::render('error', [
        'site' => $meta,
        'nav' => SitesFront::nav(''),
        'title' => t('sites.promo.not_found'),
        'message' => t('sites.promo.not_found'),
        'hint' => $code,
    ]);
    return;
}

$rendered = PromopageRenderer::render($promo);
PromopageRenderer::bumpShows($promo);
$phone = trim((string)($promo['phone'] ?? ''));
if ($phone !== '') {
    $meta['tel'] = $phone;
}
$title = trim((string)($promo['meta_title'] ?? ''));
if ($title === '') {
    $title = (string)($promo['descr'] ?? t('sites.promo.title'));
}
if (trim((string)($promo['meta_descr'] ?? '')) !== '') {
    $meta['meta_descr'] = (string)$promo['meta_descr'];
}

SiteTheme::render('promo', [
    'site' => $meta,
    'nav' => SitesFront::nav(''),
    'title' => $title,
    'promo' => $promo,
    'html' => $rendered['html'],
    'section_nav' => $rendered['section_nav'],
], 'promo');
