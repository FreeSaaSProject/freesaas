<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/StaffReportConfig.php';
require_once __DIR__ . '/lib/StaffReportProfiles.php';
require_once __DIR__ . '/lib/DayCollector.php';
require_once __DIR__ . '/lib/CriteriaAnalyzer.php';
require_once __DIR__ . '/lib/CoverageCalculator.php';
require_once __DIR__ . '/lib/TicketWriter.php';
require_once __DIR__ . '/lib/MonthRewards.php';
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
    $editPosition = max(0, (int)($_GET['sr_pos'] ?? $_POST['sr_position'] ?? 0));
    $statuses = [];
    if (class_exists('StatusesRepository', false)) {
        foreach (StatusesRepository::listAll() as $row) {
            $statuses[] = ['ID' => (int)$row['ID'], 'NAME' => (string)$row['NAME']];
        }
    }
    $profile = $editPosition > 0 ? StaffReportProfiles::findByPosition($editPosition) : null;
    if ($profile === null && $editPosition > 0) {
        $profile = [
            'id' => 0,
            'position_id' => $editPosition,
            'enabled' => false,
            'config' => StaffReportConfig::defaults(),
        ];
    }
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_profiles', [
        'statuses' => $statuses,
        'editPosition' => $editPosition,
        'profile' => $profile,
    ], 'staff_reports');
    return $payload;
});

EventBus::on('audio.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (empty($post['staff_reports_settings_save'])) {
        return $payload;
    }
    $position = max(0, (int)($post['sr_position'] ?? 0));
    if ($position < 1) {
        return $payload;
    }
    $enabled = !empty($post['sr_enabled']);
    $config = [
        'analysis' => [
            'mode' => (string)($post['sr_mode'] ?? 'procedure_slot'),
            'staff_field' => (string)($post['sr_staff_field'] ?? 'ID_OPERATOR'),
            'procedure_time_buffer' => (int)($post['sr_procedure_buffer'] ?? 1600),
            'booking_buffer_sec' => (int)($post['sr_booking_buffer'] ?? 900),
            'equivalent_via_audio_position' => true,
        ],
        'excluded_procedure_type_ids' => (string)($post['sr_excluded_types'] ?? ''),
        'criteria_type_ids' => (string)($post['sr_criteria_ids'] ?? ''),
        'color_thresholds' => [
            'red_max' => (float)($post['sr_red_max'] ?? 40),
            'yellow_max' => (float)($post['sr_yellow_max'] ?? 70),
        ],
        'coverage_min_percent' => (float)($post['sr_coverage_min'] ?? 70),
        'require_full_parse' => !empty($post['sr_require_full_parse']),
        'contract_reward_sum' => (int)($post['sr_reward_sum'] ?? 500),
        'managing_contract_id' => (int)($post['sr_managing_contract'] ?? 0),
        'ticket_status_id' => (int)($post['sr_ticket_status'] ?? 4),
        'ticket_title_prefix' => (string)($post['sr_ticket_prefix'] ?? '[Отчеты:'),
        'llm' => ['enabled' => !empty($post['sr_llm_enabled'])],
    ];
    StaffReportProfiles::save($position, $enabled, $config);
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
