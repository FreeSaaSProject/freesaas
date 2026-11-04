<?php
declare(strict_types=1);

/**
 * Repair path after HIGH (pinning auto-enabled new packs while modules_absent_off=1).
 * Re-runs pinEffectiveModules in fail_closed mode: any still-absent on-disk ids → explicit =0.
 * Does not flip existing =1 back to 0 (would brick intentional enables). Operators who got
 * unwanted =1 from the bad pin should `module disable <id>`.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $report = Config::pinEffectiveModules();
    $n = count($report['pinned']);
    if ($n > 0) {
        error_log(
            '[core/028] fail_closed pinned ' . $n . ' absent module(s) to =0: '
            . implode(',', array_keys($report['pinned']))
        );
    } else {
        error_log('[core/028] no absent keys; modules_absent_off=' . (Config::modulesAbsentDefaultsOff() ? '1' : '0'));
    }
};
