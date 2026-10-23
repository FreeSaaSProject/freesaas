<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DemoStand.php';
require_once __DIR__ . '/lib/DemoSchedule.php';
require_once __DIR__ . '/lib/DemoSeed.php';
require_once __DIR__ . '/lib/DemoProvision.php';
require_once __DIR__ . '/lib/DemoJobs.php';

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
    $own = [[
        'short' => t('demo.notice_short'),
        'href' => '/',
        'title' => t('demo.notice_title'),
        'tone' => 'info',
    ]];
    // Sibling stand in the other language (separate host + DB).
    $other = I18n::locale() === 'en' ? 'ru' : 'en';
    $switchUrl = trim((string)Config::get('demo', 'switch_url_' . $other, ''));
    if ($switchUrl !== '' && preg_match('#^https?://#i', $switchUrl)) {
        $own[] = [
            'short' => $other === 'en' ? t('demo.switch_to_en') : t('demo.switch_to_ru'),
            'href' => $switchUrl,
            'title' => $other === 'en' ? t('demo.switch_title_en') : t('demo.switch_title_ru'),
            'tone' => 'info',
        ];
    }
    return array_merge($own, $notices);
});

// Self-maintenance jobs exist only on demo stands; production never lists them.
if (class_exists('DemoMode', false) && DemoMode::readonly()) {
    $demoSchedule = DemoSchedule::load();
    Cron::register('demo.update', array_merge([
        'module' => 'demo',
        'label' => 'demo.cron.update',
        'handler' => static function (): array {
            return DemoJobs::update();
        },
    ], $demoSchedule['update']));
    Cron::register('demo.reseed', array_merge([
        'module' => 'demo',
        'label' => 'demo.cron.reseed',
        'handler' => static function (): array {
            return DemoJobs::reseed();
        },
    ], $demoSchedule['reseed']));
    Cron::register('demo.version_check', array_merge([
        'module' => 'demo',
        'label' => 'demo.cron.version_check',
        'handler' => static function (): array {
            return DemoJobs::versionCheck();
        },
    ], $demoSchedule['version_check']));
    unset($demoSchedule);
}
