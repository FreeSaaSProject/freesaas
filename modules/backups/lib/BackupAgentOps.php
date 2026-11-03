<?php
declare(strict_types=1);

/** Surface A (/agent/backups/*) — agent_token ops. */
final class BackupAgentOps
{
    public static function register(): void
    {
        $ops = [
            ['GET', '/agent/backups/check', 'backups.check', [self::class, 'check'], 'Host readiness for backups/CDC'],
            ['GET', '/agent/backups/cdc', 'backups.cdc.status', [self::class, 'cdcStatus'], 'CDC trigger/journal status'],
            ['POST', '/agent/backups/cdc/install', 'backups.cdc.install', [self::class, 'cdcInstall'], 'Install/refresh CDC triggers'],
            ['POST', '/agent/backups/cdc/uninstall', 'backups.cdc.uninstall', [self::class, 'cdcUninstall'], 'Drop CDC triggers'],
            ['GET', '/agent/backups/standby', 'backups.standby.status', [self::class, 'standbyStatus'], 'Standby applied cursor/full'],
            ['POST', '/agent/backups/standby/sync', 'backups.standby.sync', [self::class, 'standbySync'], 'Pull FTP full+incr and apply (standby host)'],
        ];
        foreach ($ops as [$method, $path, $opId, $handler, $desc]) {
            AgentOps::registerModuleOp($method, $path, $opId, $handler, ['description' => $desc]);
            ModuleManager::registerRoute($path, [
                'module' => 'backups',
                'public' => true,
                'csrf' => false,
                'methods' => [$method],
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function check(): array
    {
        $report = BackupDoctor::run();
        return array_merge(['ok' => !empty($report['ok'])], $report);
    }

    /** @return array<string,mixed> */
    public static function cdcStatus(): array
    {
        return array_merge(['ok' => true], BackupCdc::status());
    }

    /** @return array<string,mixed> */
    public static function cdcInstall(): array
    {
        BackupConfig::set('cdc_enabled', '1');
        $r = BackupCdc::install();
        return array_merge(['ok' => !empty($r['ok'])], $r);
    }

    /** @return array<string,mixed> */
    public static function cdcUninstall(): array
    {
        $r = BackupCdc::uninstall();
        BackupConfig::set('cdc_enabled', '0');
        return array_merge(['ok' => !empty($r['ok'])], $r);
    }

    /** @return array<string,mixed> */
    public static function standbyStatus(): array
    {
        return BackupStandby::status();
    }

    /** @return array<string,mixed> */
    public static function standbySync(): array
    {
        return BackupStandby::sync(true);
    }
}
