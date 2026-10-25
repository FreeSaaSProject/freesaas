<?php
declare(strict_types=1);

/**
 * Surface A (/agent/audio_reports/*) — day rating report via agent_token.
 * See docs/agents/audio-scripts-debug.md.
 */
final class AudioReportsAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp(
            'GET',
            '/agent/audio_reports/day-report',
            'audio_reports.agent.day_report',
            [self::class, 'dayReport'],
            [
                'description' => 'Staff day rating report JSON (same as UI day-report); admin+date',
                'query' => ['admin', 'date'],
            ]
        );
        ModuleManager::registerRoute('/agent/audio_reports/day-report', [
            'module' => 'audio_reports',
            'public' => true,
            'csrf' => false,
            'methods' => ['GET'],
            'handler' => static function (): void {
                require_once base_path('app/agent_http.php');
            },
        ]);
    }

    /** @return array<string,mixed> */
    public static function dayReport(): array
    {
        $in = AgentOps::requestInput();
        $admin = (int)($in['admin'] ?? 0);
        $date = trim((string)($in['date'] ?? ''));
        if ($admin < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'error' => 'admin and date=YYYY-MM-DD required', 'http' => 400];
        }
        if (!class_exists('DayRatingReport', false)) {
            require_once __DIR__ . '/DayRatingReport.php';
        }
        $report = DayRatingReport::build($admin, $date);
        // Drop heavy HTML from agent payload.
        unset($report['sections_html']);
        $report['http'] = !empty($report['ok']) ? 200 : 404;
        return $report;
    }
}
