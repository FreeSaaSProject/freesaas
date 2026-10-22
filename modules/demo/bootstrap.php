<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DemoSeed.php';
require_once __DIR__ . '/lib/DemoProvision.php';

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check()) {
        return $notices;
    }
    if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
        return $notices;
    }
    array_unshift($notices, [
        'short' => t('demo.notice_short'),
        'href' => '/',
        'title' => t('demo.notice_title'),
        'tone' => 'info',
    ]);
    return $notices;
});
