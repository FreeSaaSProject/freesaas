<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/StaffReportConfig.php';
require_once __DIR__ . '/lib/StaffReportPublisherConfig.php';
require_once __DIR__ . '/lib/StaffReportProfiles.php';
require_once __DIR__ . '/lib/DayCollector.php';
require_once __DIR__ . '/lib/CriteriaAnalyzer.php';
require_once __DIR__ . '/lib/CoverageCalculator.php';
require_once __DIR__ . '/lib/TicketWriter.php';
require_once __DIR__ . '/lib/MonthRewards.php';
require_once __DIR__ . '/lib/GratitudeBoard.php';
require_once __DIR__ . '/lib/DayRewardContract.php';
require_once __DIR__ . '/lib/StaffReportRunner.php';
require_once __DIR__ . '/lib/CalendarRewardOverlay.php';

Assets::register('staff_reports', [
    'css' => ['staff_reports.css'],
]);

$pages = [
    '/staff-reports/generate' => [
        'permission' => 'staff_reports.edit',
        'methods' => ['GET', 'POST'],
        'file' => 'generate.php',
    ],
    '/staff-reports/day' => [
        'permission' => 'staff_reports.view',
        'methods' => ['GET'],
        'file' => 'day.php',
    ],
    '/staff-reports/profiles' => [
        'permission' => 'staff_reports.view',
        'methods' => ['GET', 'POST'],
        'file' => 'profiles.php',
    ],
    '/staff-reports/rewards' => [
        'permission' => 'staff_reports.view',
        'methods' => ['GET'],
        'file' => 'rewards.php',
    ],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'staff_reports',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/staff_reports/pages/' . $file);
        },
    ]);
}

EventBus::on('audio.settings.sections', static function ($payload) {
    if (!is_array($payload)) {
        $payload = ['html' => ''];
    }
    if (!Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_publisher', [
        'publisher' => StaffReportPublisherConfig::read(),
    ], 'staff_reports');
    return $payload;
});

EventBus::on('audio.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (empty($post['staff_reports_publisher_save'])) {
        return $payload;
    }
    StaffReportPublisherConfig::save([
        'require_full_parse' => !empty($post['sr_require_full_parse']),
        'managing_contract_id' => (int)($post['sr_managing_contract'] ?? 0),
        'ticket_status_id' => (int)($post['sr_ticket_status'] ?? 4),
        'ticket_title_prefix' => (string)($post['sr_ticket_prefix'] ?? '[Отчеты:'),
        'gratitude_news_id' => (int)($post['sr_gratitude_news_id'] ?? 0),
        'contract_working_days' => (int)($post['sr_contract_working_days'] ?? 5),
        'llm' => ['enabled' => !empty($post['sr_llm_enabled'])],
    ]);
    return $payload;
});

EventBus::on('audio_reports.calendar.cell', static function ($payload) {
    return CalendarRewardOverlay::onCell($payload);
});

EventBus::on('audio_reports.calendar.summary_enrich', static function ($payload) {
    return CalendarRewardOverlay::onSummaryEnrich($payload);
});

EventBus::on('audio_reports.summary.external', static function ($payload) {
    return CalendarRewardOverlay::onSummaryExternal($payload);
});

EventBus::on('audio_reports.calendar.reparse.after', static function ($payload) {
    return CalendarRewardOverlay::onReparseAfter($payload);
});
