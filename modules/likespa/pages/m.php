<?php
declare(strict_types=1);

/**
 * Storefront /m — legacy likespa.ru/m QR target: messenger bots + staff tip links.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    return;
}

SiteTheme::render('m', [
    'site' => SitesFront::siteMeta(),
    'nav' => SitesFront::nav(''),
    'title' => t('likespa.m.title'),
    'bots' => [
        [
            'label' => 'MAX',
            'href' => 'https://max.ru/id631308298500_bot',
            'desc' => t('likespa.m.max_desc'),
        ],
        [
            'label' => 'Telegram',
            'href' => 'https://t.me/likespabot',
            'desc' => t('likespa.m.tg_desc'),
        ],
    ],
    'staff' => LikespaTipStaff::list(),
], 'layout');
