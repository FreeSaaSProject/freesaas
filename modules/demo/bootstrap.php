<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DemoStand.php';
require_once __DIR__ . '/lib/DemoSchedule.php';
require_once __DIR__ . '/lib/DemoSeed.php';
require_once __DIR__ . '/lib/DemoProvision.php';
require_once __DIR__ . '/lib/DemoJobs.php';
require_once __DIR__ . '/lib/DemoChrome.php';

Assets::register('demo', [
    'css' => ['demo.css'],
]);

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
    // Banner strip carries the main copy; chips keep only RU↔EN switch.
    $own = [];
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

EventBus::on('core.layout.gate', static function ($gate) {
    if (!is_array($gate)) {
        $gate = [];
    }
    return DemoChrome::onLayoutGate($gate);
});

EventBus::on('router.not_found', static function ($payload) {
    if (!is_array($payload) || !empty($payload['handled'])) {
        return $payload;
    }
    if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
        return $payload;
    }
    $path = (string)($payload['path'] ?? '');
    $modId = DemoChrome::disabledModuleForPath($path);
    if ($modId === null) {
        return $payload;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('demo.module_off.title'),
        'message' => t('demo.module_off.body'),
        'hint' => $modId,
    ], 'layout');
    $payload['handled'] = true;
    return $payload;
});

// Public JSON for freesaas.ru tile flags (CORS locked to configured origin).
if (class_exists('DemoMode', false) && DemoMode::readonly()) {
    ModuleManager::registerRoute('/demo-modules.json', [
        'module' => 'demo',
        'public' => true,
        'csrf' => false,
        'methods' => ['GET', 'OPTIONS'],
        'handler' => static function (): void {
            require base_path('modules/demo/pages/modules_json.php');
        },
    ]);
}

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
    $reseedIdx = 0;
    foreach ($demoSchedule['reseeds'] as $reseedSched) {
        $jobId = $reseedIdx === 0 ? 'demo.reseed' : ('demo.reseed_' . preg_replace('/\D+/', '', (string)($reseedSched['daily_at'] ?? $reseedIdx)));
        Cron::register($jobId, array_merge([
            'module' => 'demo',
            'label' => 'demo.cron.reseed',
            'handler' => static function (): array {
                return DemoJobs::reseed();
            },
        ], $reseedSched));
        $reseedIdx++;
    }
    Cron::register('demo.version_check', array_merge([
        'module' => 'demo',
        'label' => 'demo.cron.version_check',
        'handler' => static function (): array {
            return DemoJobs::versionCheck();
        },
    ], $demoSchedule['version_check']));
    unset($demoSchedule, $reseedIdx);
}
