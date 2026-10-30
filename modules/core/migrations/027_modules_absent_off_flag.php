<?php
declare(strict_types=1);

/**
 * Instances that already applied 025 before app.modules_absent_off existed: set the flag
 * and pin any remaining absent keys to =1. Idempotent.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    try {
        $report = Config::pinEffectiveModules();
        $n = count($report['pinned']);
        if ($n > 0) {
            error_log('[core/027] pinned ' . $n . ' remaining absent module(s) to =1');
        }
        error_log('[core/027] app.modules_absent_off ensured');
    } catch (Throwable $e) {
        error_log('[core/027] modules_absent_off failed: ' . $e->getMessage());
    }
};
