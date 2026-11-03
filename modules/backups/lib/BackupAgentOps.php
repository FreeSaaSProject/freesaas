<?php
declare(strict_types=1);

/** Surface A (/agent/backups/*) — agent_token host check. */
final class BackupAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/backups/check', 'backups.check', [self::class, 'check'], [
            'description' => 'Host readiness for backups/CDC: MySQL triggers, grants, FTP, cron, disk, PHP',
        ]);

        ModuleManager::registerRoute('/agent/backups/check', [
            'module' => 'backups',
            'public' => true,
            'csrf' => false,
            'methods' => ['GET'],
            'handler' => static function (): void {
                require_once base_path('app/agent_http.php');
            },
        ]);
    }

    /** @return array<string,mixed> */
    public static function check(): array
    {
        $report = BackupDoctor::run();
        return array_merge(['ok' => !empty($report['ok'])], $report);
    }
}
